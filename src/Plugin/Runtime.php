<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use Amp\DeferredFuture;
use XiHan\Framework\Invocation\SystemClock;
use XiHan\Framework\Manifest\Isolation;
use XiHan\Framework\Manifest\Kind;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Plugin\Internal\Dependencies;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcServer;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Internal\Lifecycle;
use XiHan\Framework\Plugin\Internal\Listener;
use XiHan\Framework\Plugin\Internal\PluginService;
use XiHan\Framework\Plugin\Internal\StdinWatcher;
use XiHan\Framework\Plugin\Internal\TenantScope;
use XiHan\Framework\Plugin\Internal\Versions;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\LifecycleState;

use function Amp\async;

/**
 * 进程外插件的运行时（RFC-0017，spec/plugin/）：以插件的身份运行到停止。
 *
 * 读宿主下发的环境变量（handshake.md §1），在只接受本机连接的端点上提供 xihan.plugin.v1 的 Plugin 服务（gRPC，明文
 * HTTP/2），向 stdout 写一行握手行（§2），每次调用先按常数时间核对会话令牌（§4）。调用经 SDK 自己的调用器与完整管道
 * 执行，上下文由 InvocationMetadata 还原（invocation.md §2）。Stop 完成或 stdin 关闭即停止并返回（lifecycle.md §5）。
 *
 * 运行在 amphp 的事件循环上：每个调用一个 Fiber，处理函数仍是同步签名，阻塞的处理函数会挡住同一进程里的其他调用。
 */
final class Runtime
{
    /** 携带会话令牌的元数据键（handshake.md §4）。 */
    public const string SESSION_METADATA = 'xihan-plugin-session';

    /** 宿主支持的协议版本，逗号分隔、升序。 */
    public const string ENV_PROTOCOL_VERSIONS = 'XIHAN_PLUGIN_PROTOCOL_VERSIONS';

    /** 宿主的原语层大版本。 */
    public const string ENV_PRIMITIVES = 'XIHAN_PLUGIN_PRIMITIVES';

    /** 本次启动的会话令牌。 */
    public const string ENV_SESSION = 'XIHAN_PLUGIN_SESSION';

    /** 宿主回调端点 `<network> <address>`。 */
    public const string ENV_HOST_ADDRESS = 'XIHAN_PLUGIN_HOST_ADDRESS';

    /** 实例 ID。 */
    public const string ENV_INSTANCE = 'XIHAN_PLUGIN_INSTANCE';

    /** 宿主默认区域。 */
    public const string ENV_LOCALE = 'XIHAN_PLUGIN_LOCALE';

    private readonly Manifest $manifest;

    private readonly Logger $logger;

    private readonly Lifecycle $lifecycle;

    private readonly PluginService $service;

    private readonly ?Host $host;

    private readonly string $session;

    /** 宿主默认区域，只用于日志 */
    private readonly string $locale;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $stopped;

    private ?GrpcServer $server = null;

    private bool $stopping = false;

    /**
     * @throws ConfigurationException
     */
    private function __construct(private readonly Options $options)
    {
        $this->manifest = Manifest::read($options->manifest);
        $environment = $options->environment ?? getenv();
        $this->logger = new Logger($options->stderr, $options->logLevel, [
            'plugin' => $this->manifest->id,
            'instance' => $environment[self::ENV_INSTANCE] ?? '',
        ]);
        $this->checkDeclarations();
        $versions = $environment[self::ENV_PROTOCOL_VERSIONS] ?? '';
        if (Versions::parse($versions) === null) {
            throw self::configuration(sprintf(
                "%s must be a comma-separated list of protocol versions, got '%s'.",
                self::ENV_PROTOCOL_VERSIONS,
                $versions,
            ));
        }
        $session = $environment[self::ENV_SESSION] ?? '';
        if ($session === '') {
            throw self::configuration(sprintf('%s is required.', self::ENV_SESSION));
        }
        $this->session = $session;
        $primitives = $environment[self::ENV_PRIMITIVES] ?? '';
        if ($primitives !== '' && $primitives !== (string) $this->manifest->primitives) {
            $this->logger->warning('The host primitives version differs from the manifest; the handshake will fail.', [
                'host' => $primitives,
                'plugin' => $this->manifest->primitives,
            ]);
        }
        $tenants = new TenantScope();
        $address = $environment[self::ENV_HOST_ADDRESS] ?? '';
        if ($address !== '') {
            $endpoint = Endpoint::tryParse($address);
            if ($endpoint === null) {
                throw self::configuration(
                    sprintf("%s must be '<network> <address>', got '%s'.", self::ENV_HOST_ADDRESS, $address),
                );
            }
            $this->host = new Host($endpoint, $session, $this->manifest->id, $this->manifest->permissions, $tenants);
        } else {
            $this->host = null;
        }
        $this->lifecycle = new Lifecycle();
        $this->service = new PluginService(
            $options,
            $this->manifest,
            new Invoker($options->registry, $options->pipeline),
            $this->lifecycle,
            $this->logger,
            $tenants,
            $this->host,
            new SystemClock(),
            function (float $grace): void {
                $this->shutdown($grace);
            },
        );
        $this->stopped = new DeferredFuture();
        $this->locale = $environment[self::ENV_LOCALE] ?? '';
    }

    /**
     * 以进程外插件的身份运行：核对清单与声明、读环境变量、监听、写握手行，服务到 Stop 完成或 stdin 关闭；正常停止返回 0。
     *
     * @throws ConfigurationException 缺可选依赖、清单不合法或不是进程外插件、写了 contributes.pipeline、声明的操作不在清单里、
     *     订阅重复、环境变量缺失或不合法、监听失败（都在写握手行之前）
     */
    public static function serve(Options $options): int
    {
        // 先核对依赖：缺包时说清楚要装什么，而不是在加载协议类时报类不存在
        Dependencies::check();

        return (new self($options))->run();
    }

    /**
     * 监视 stdin、监听、写握手行并等待停止。
     */
    private function run(): int
    {
        // 先起 stdin 的监视：Windows 上它是子进程，在监听之前起就不会继承监听的套接字
        $watcher = StdinWatcher::start($this->options->stdin ?? STDIN, function (): void {
            $this->logger->information('stdin closed; stopping.');
            async(function (): void {
                // 当作立即收尾退出的请求（lifecycle.md §5）：不等宿主再发 Stop，停止回调只执行一次
                $this->service->beginDraining();
                $this->service->runStopHook('shutdown', $this->options->stopGrace);
                $this->shutdown($this->options->stopGrace);
            })->ignore();
        });
        if ($watcher === null) {
            $this->logger->warning('stdin cannot be watched; closing it will not stop the plugin.');
        }
        try {
            $listener = Listener::open($this->options->network, $this->options->address);
            try {
                $this->serveOn($listener);
            } finally {
                $listener->close();
                $this->host?->close();
            }
        } finally {
            $watcher?->stop();
        }
        $this->logger->information('Stopped.');

        return 0;
    }

    /**
     * 在端点上提供服务、写握手行，等到停止。
     */
    private function serveOn(Listener $listener): void
    {
        $guard = function (ServerCall $call): ?GrpcException {
            $presented = $call->metadata(self::SESSION_METADATA);
            // 常数时间比较：逐字节短路的比较会从耗时里泄露令牌前缀；只认恰好一个值
            if (count($presented) !== 1 || !hash_equals($this->session, $presented[0])) {
                return new GrpcException(
                    GrpcStatus::UNAUTHENTICATED,
                    'The plugin session token is missing or invalid.',
                );
            }

            return null;
        };
        $this->server = new GrpcServer(
            $listener->socket,
            $this->service->methods(),
            $guard,
            function (\Throwable $exception, string $path): void {
                $this->logger->error('A plugin call failed unexpectedly.', [
                    'method' => $path,
                    'exception' => $exception,
                ]);
            },
        );
        $this->server->start();
        // 握手行是 stdout 的第一行，也是握手完成之前唯一的输出（handshake.md §2）
        $stdout = $this->options->stdout ?? STDOUT;
        fwrite($stdout, $listener->endpoint->handshakeLine() . "\n");
        fflush($stdout);
        $this->logger->information('Listening.', [
            'endpoint' => (string) $listener->endpoint,
            'locale' => $this->locale,
        ]);
        $this->stopped->getFuture()->await();
    }

    /**
     * 优雅停止：不再接受连接，在途的调用在宽限内结束，然后让 serve() 返回；只执行一次。
     */
    private function shutdown(float $grace): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->service->beginDraining();
        $this->server?->shutdown($grace);
        // Stop 之前没有走到 Draining（如还没有 Start）的插件保持原状态，进程照样退出
        $this->lifecycle->transition(LifecycleState::Stopped);
        $this->stopped->complete();
    }

    /**
     * 核对清单与声明（在监听之前，配置错误不进入握手）：进程外插件、不贡献中间件、操作都在清单里、订阅不重复。
     *
     * @throws ConfigurationException
     */
    private function checkDeclarations(): void
    {
        $id = $this->manifest->id;
        if ($this->manifest->kind !== Kind::Plugin || $this->manifest->isolation !== Isolation::OutOfProcess) {
            throw self::configuration(
                sprintf("The manifest '%s' must describe a plugin with isolation out-of-process.", $id),
            );
        }
        // 只有 Wasm 插件能贡献中间件（RFC-0017「范围」）：宿主在发现时拒绝写了 contributes.pipeline 的进程外插件
        if (($this->manifest->contributes->pipeline ?? []) !== []) {
            throw self::configuration(sprintf(
                "The plugin '%s' must not declare 'contributes.pipeline': only wasm plugins can contribute middleware.",
                $id,
            ));
        }
        $declared = $this->manifest->operations();
        $provided = [];
        foreach ($this->options->registry->list() as $descriptor) {
            $provided[] = $descriptor->contract->id;
        }
        foreach ($this->options->streams as $stream) {
            if (in_array($stream->contract->id, $provided, true)) {
                throw self::configuration(
                    sprintf("The operation '%s' of '%s' is provided more than once.", $stream->contract->id, $id),
                );
            }
            $provided[] = $stream->contract->id;
        }
        foreach ($provided as $operation) {
            if (!in_array($operation, $declared, true)) {
                throw self::configuration(
                    sprintf("The operation '%s' is not declared in contributes.operations of '%s'.", $operation, $id),
                );
            }
        }
        // 订阅按（主题，类型）去重而不看方式：宿主拒绝同一对以两种方式各声明一次（handshake.md §3 第 5 条）
        $pairs = [];
        foreach ($this->options->subscriptions as $subscription) {
            $pair = $subscription->topic . "\0" . $subscription->messageType;
            if (isset($pairs[$pair])) {
                throw self::configuration(sprintf(
                    "The subscription '%s' with the message type '%s' is declared more than once by '%s'.",
                    $subscription->topic,
                    $subscription->messageType,
                    $id,
                ));
            }
            $pairs[$pair] = true;
        }
    }

    private static function configuration(string $message): ConfigurationException
    {
        return new ConfigurationException($message, ErrorCode::INVALID_ARGUMENT);
    }
}
