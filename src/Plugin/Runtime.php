<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use Amp\DeferredFuture;
use Revolt\EventLoop;
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
use XiHan\Framework\Plugin\Internal\HostAddresses;
use XiHan\Framework\Plugin\Internal\Lifecycle;
use XiHan\Framework\Plugin\Internal\Listener;
use XiHan\Framework\Plugin\Internal\PluginService;
use XiHan\Framework\Plugin\Internal\StdinWatcher;
use XiHan\Framework\Plugin\Internal\TenantScope;
use XiHan\Framework\Plugin\Internal\Versions;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\FrameworkException;
use XiHan\Framework\Primitives\FrameworkInfo;
use XiHan\Framework\Primitives\LifecycleState;

use function Amp\async;
use function Amp\delay;

/**
 * 进程外插件的运行时（RFC-0017，spec/plugin/）：以插件的身份运行到停止。
 *
 * 读宿主下发的环境变量（handshake.md §1），在只接受本机连接的端点上提供 xihan.plugin.v1 的 Plugin 服务（gRPC，明文
 * HTTP/2），向 stdout 写一行握手行（§2），每次调用先按常数时间核对会话令牌（§4）。调用经 SDK 自己的调用器与完整管道
 * 执行，上下文由 InvocationMetadata 还原（invocation.md §2）。应答 Stop 之后、stdin 关闭或收到 SIGTERM（需要 pcntl）
 * 时收尾并返回 0（lifecycle.md §7.5、§7.7）。
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

    /** 宿主的回调端点：一个或多个 `<network> <address>`，按宿主的偏好排序、以 `;` 分隔。 */
    public const string ENV_HOST_ADDRESS = 'XIHAN_PLUGIN_HOST_ADDRESS';

    /** 实例 ID。 */
    public const string ENV_INSTANCE = 'XIHAN_PLUGIN_INSTANCE';

    /** 宿主默认区域。 */
    public const string ENV_LOCALE = 'XIHAN_PLUGIN_LOCALE';

    /** 拒绝启动时的退出码：宿主看到的是进程在握手之前退出（handshake.md §5）。 */
    public const int EXIT_REFUSED = 1;

    /** stdin 关闭与 SIGTERM 之后收尾的总时限（秒）：短于宿主发出终止信号后再等的 5 秒（lifecycle.md §7.7） */
    private const float SHUTDOWN_LIMIT = 3.0;

    /** UTF-8 的 BOM */
    private const string BOM = "\xEF\xBB\xBF";

    private readonly Lifecycle $lifecycle;

    private readonly PluginService $service;

    private readonly Host $host;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $stopped;

    private ?GrpcServer $server = null;

    private bool $stopping = false;

    /** 是否已经开始收尾（stdin 关闭或 SIGTERM） */
    private bool $shuttingDown = false;

    /**
     * @param array<string, string> $environment
     */
    private function __construct(
        private readonly Options $options,
        Manifest $manifest,
        string $manifestText,
        private readonly Logger $logger,
        private readonly string $session,
        array $environment,
    ) {
        $tenants = new TenantScope();
        $hostAddress = $environment[self::ENV_HOST_ADDRESS] ?? null;
        $endpoint = HostAddresses::select($hostAddress, HostAddresses::supported());
        if ($endpoint === null) {
            // 照常服务，回调一律以 plugins.host.unavailable 失败、不发请求（handshake.md §4）
            $logger->warning('No supported callback endpoint; callbacks to the host will fail.', [
                'hostAddress' => $hostAddress ?? '',
            ]);
        }
        $this->host = new Host($endpoint, $session, $manifest->id, $manifest->permissions, $tenants);
        $this->lifecycle = new Lifecycle();
        $this->service = new PluginService(
            $options,
            $manifest,
            $manifestText,
            new Invoker($options->registry, $options->pipeline),
            $this->lifecycle,
            $logger,
            $tenants,
            $this->host,
            new SystemClock(),
            function (float $grace): void {
                $this->shutdown($grace);
            },
        );
        $this->stopped = new DeferredFuture();
        $primitives = $environment[self::ENV_PRIMITIVES] ?? '';
        if ($primitives !== '' && $primitives !== (string) $manifest->primitives) {
            $logger->warning('The host primitives version differs from the manifest; the handshake will fail.', [
                'host' => $primitives,
                'plugin' => $manifest->primitives,
            ]);
        }
    }

    /**
     * 以进程外插件的身份运行：核对清单与声明、读环境变量、监听、写握手行，服务到停止；正常停止返回 0。
     *
     * 缺可选依赖、清单不合法、不是进程外插件、写了 contributes.pipeline、primitives 不是本 SDK 支持的原语层大版本、声明的
     * 操作不在清单里、订阅重复、环境变量缺失或不合法、监听失败时拒绝启动（handshake.md §1）：不监听、不写握手行，在 stderr
     * 记一条 error 日志，返回 EXIT_REFUSED。
     */
    public static function serve(Options $options): int
    {
        $environment = $options->environment ?? getenv();
        $logger = new Logger($options->stderr, $options->logLevel, [
            'instance' => $environment[self::ENV_INSTANCE] ?? '',
        ]);
        try {
            // 先核对依赖：缺包时说清楚要装什么，而不是在加载协议类时报类不存在
            Dependencies::check();
            // 握手发清单文件的原文，去掉开头的 BOM（handshake.md §3 第 3 条）；读清单也按去掉之后的文本
            $text = str_starts_with($options->manifest, self::BOM) ? substr($options->manifest, 3) : $options->manifest;
            $manifest = Manifest::read($text);
            $logger = $logger->with(['plugin' => $manifest->id]);
            self::checkDeclarations($options, $manifest);
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
            $runtime = new self($options, $manifest, $text, $logger, $session, $environment);
        } catch (FrameworkException $exception) {
            return self::refuse($logger, $exception);
        }

        return $runtime->run();
    }

    /**
     * 监视 stdin 与 SIGTERM、监听、写握手行并等待停止。
     */
    private function run(): int
    {
        // 先起 stdin 的监视：Windows 上它是子进程，在监听之前起就不会继承监听的套接字
        $watcher = StdinWatcher::start($this->options->stdin ?? STDIN, function (): void {
            $this->requestShutdown('stdin closed');
        });
        if ($watcher === null) {
            $this->logger->warning('stdin cannot be watched; closing it will not stop the plugin.');
        }
        $signal = $this->watchSigterm();
        if ($signal !== null) {
            // Revolt 在下一轮循环才真正装上信号处理器：先让它装好，握手行一写出宿主就可能发 SIGTERM
            delay(0);
        }
        try {
            try {
                $listener = Listener::open($this->options->network, $this->options->address);
            } catch (FrameworkException $exception) {
                return self::refuse($this->logger, $exception);
            }
            try {
                $this->serveOn($listener);
            } finally {
                $listener->close();
                $this->host->close();
            }
        } finally {
            $watcher?->stop();
            if ($signal !== null) {
                EventLoop::cancel($signal);
            }
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
            // 常数时间比较：逐字节短路的比较会从耗时里泄露令牌前缀；只认恰好一个值，两个值即使有一个对也拒绝（§4）
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
            'callback' => (string) ($this->host->endpoint() ?? ''),
        ]);
        $this->stopped->getFuture()->await();
    }

    /**
     * 有 pcntl 时把 SIGTERM 当作收尾请求（lifecycle.md §7.7）；没有时 SIGTERM 按系统缺省终止进程。
     */
    private function watchSigterm(): ?string
    {
        // pcntl 只在 Unix 上有；Revolt 的事件循环靠它分派信号
        if (!extension_loaded('pcntl')) {
            return null;
        }

        return EventLoop::unreference(EventLoop::onSignal(\SIGTERM, function (): void {
            $this->requestShutdown('SIGTERM');
        }));
    }

    /**
     * stdin 关闭或 SIGTERM（lifecycle.md §7.7）：收尾至多 3 秒，到时不论是否完成都返回 0；进行中的 Stop 同样受这个时限。
     */
    private function requestShutdown(string $cause): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        $this->logger->information('Shutting down.', ['cause' => $cause]);
        $begun = hrtime(true);
        // 定时器保持引用：主 Fiber 在等停止，事件循环里得留着能把它唤醒的东西
        $limit = EventLoop::delay(self::SHUTDOWN_LIMIT, function (): void {
            $this->logger->warning('Shutdown did not finish within the time limit; exiting anyway.', [
                'limit' => self::SHUTDOWN_LIMIT,
            ]);
            $this->complete();
        });
        async(function () use ($begun, $limit): void {
            try {
                $this->service->drain(self::SHUTDOWN_LIMIT);
                $this->shutdown(max(0.0, self::SHUTDOWN_LIMIT - (hrtime(true) - $begun) / 1e9));
            } finally {
                EventLoop::cancel($limit);
            }
        })->ignore();
    }

    /**
     * 优雅停止：结束健康推送，不再接受连接，在途的流在 $grace 秒内结束，然后让 serve() 返回；只执行一次。
     */
    private function shutdown(float $grace): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->service->finish();
        $this->server?->shutdown($grace);
        // 只有 Draining 能迁移到 Stopped：没有启动过或启动后进入 Failed 的保持原状态，进程照样退出
        $this->lifecycle->transition(LifecycleState::Stopped);
        $this->complete();
    }

    /**
     * 让 serve() 返回。
     */
    private function complete(): void
    {
        if (!$this->stopped->isComplete()) {
            $this->stopped->complete();
        }
    }

    /**
     * 核对清单与声明（handshake.md §1 末段，在监听之前）：进程外插件、不贡献中间件、primitives 是本 SDK 支持的原语层
     * 大版本、操作都在清单里、订阅的（主题，类型）不重复。操作在登记表里不会重复、主题文法与目录含 en-US 在构造时已经
     * 核对，健康项在运行期按清单过滤。
     *
     * @throws ConfigurationException
     */
    private static function checkDeclarations(Options $options, Manifest $manifest): void
    {
        $id = $manifest->id;
        if ($manifest->kind !== Kind::Plugin || $manifest->isolation !== Isolation::OutOfProcess) {
            throw self::configuration(
                sprintf("The manifest '%s' must describe a plugin with isolation out-of-process.", $id),
            );
        }
        // 只有 Wasm 插件能贡献中间件（RFC-0017「范围」）：宿主在发现时拒绝写了 contributes.pipeline 的进程外插件
        if (($manifest->contributes->pipeline ?? []) !== []) {
            throw self::configuration(sprintf(
                "The plugin '%s' must not declare 'contributes.pipeline': only wasm plugins can contribute middleware.",
                $id,
            ));
        }
        if ((string) $manifest->primitives !== FrameworkInfo::SPEC_VERSION) {
            throw self::configuration(sprintf(
                "The manifest '%s' declares primitives '%s', but this SDK supports primitives '%s'.",
                $id,
                $manifest->primitives,
                FrameworkInfo::SPEC_VERSION,
            ));
        }
        $declared = $manifest->operations();
        foreach ($options->registry->list() as $descriptor) {
            if (!in_array($descriptor->contract->id, $declared, true)) {
                throw self::configuration(sprintf(
                    "The operation '%s' is not declared in contributes.operations of '%s'.",
                    $descriptor->contract->id,
                    $id,
                ));
            }
        }
        // 订阅按（主题，类型）去重而不看方式：宿主拒绝同一对以两种方式各声明一次（handshake.md §3 第 5 条）
        $pairs = [];
        foreach ($options->subscriptions as $subscription) {
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

    /**
     * 拒绝启动：在 stderr 记一条 error 日志，不监听、不写握手行。
     */
    private static function refuse(Logger $logger, FrameworkException $exception): int
    {
        $logger->error('The plugin refused to start.', [
            'code' => $exception->errorCode(),
            'reason' => $exception->getMessage(),
        ]);

        return self::EXIT_REFUSED;
    }

    private static function configuration(string $message): ConfigurationException
    {
        return new ConfigurationException($message, ErrorCode::INVALID_ARGUMENT);
    }
}
