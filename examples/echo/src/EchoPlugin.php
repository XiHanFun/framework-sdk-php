<?php

declare(strict_types=1);

namespace XiHan\Framework\Examples\Echo;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Codec;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Expose;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Schema;
use XiHan\Framework\Plugin\Catalog;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\Hooks;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Logger;
use XiHan\Framework\Plugin\OperationStream;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\StreamingOperation;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Plugin\SubscriptionMode;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\RawJson;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;
use XiHan\Framework\Primitives\Unit;

/**
 * 用本 SDK 写的回声插件：既是插件运行时的端到端用例（黑盒运行器的全部用例），也是 .NET 宿主加载 PHP 插件的示例。
 *
 * 操作：echoes.create（回声文本并附上配置的问候语与上下文）、echoes.relay（经宿主回调调用另一个操作）、echoes.crash
 * （故意让进程异常退出，运行器以 --exclude 排除）、echoes.stream（流式回声）、events.list（列出收到的事件 ID）、
 * events.publish（经宿主发布一条消息）。订阅 echo.events（竞争），同一主题的 echo.cleared 用广播。健康项 echo。
 */
final class EchoPlugin
{
    /** 清单 ID。 */
    public const string ID = 'xihan.samples.echo-php';

    /** 记下的事件 ID 的上限：超过时丢掉最早的，内存不随投递增长 */
    private const int MAX_EVENTS = 1000;

    private string $greeting = '';

    /** @var list<string> */
    private array $events = [];

    private function __construct(private readonly Logger $logger)
    {
    }

    /**
     * 组装运行时的选项。
     *
     * @param string $manifest 清单原文
     */
    public static function options(string $manifest): Options
    {
        $plugin = new self(new Logger(null, scope: ['plugin' => self::ID]));
        $registry = new Registry();
        $registry->add(
            new Contract('echoes.create', Stability::Experimental, [Expose::Http, Expose::Mcp]),
            $plugin->create(...),
            Codec::typed(CreateEcho::class, CreateEcho::shape(), CreateEcho::create(...), 'array'),
            new Schema(
                '{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]}',
                '{"type":"object","properties":{"text":{"type":"string"},"greeting":{"type":"string"},'
                . '"tenant":{"type":"string"},"principal":{"type":"string"},"correlation":{"type":"string"}},'
                . '"required":["text"]}',
            ),
            self::ID,
        );
        $registry->add(
            new Contract('echoes.relay', Stability::Experimental, [Expose::Http]),
            $plugin->relay(...),
            Codec::typed(RelayEcho::class, RelayEcho::shape(), RelayEcho::create(...), 'mixed'),
            owner: self::ID,
        );
        $registry->add(
            new Contract('echoes.crash', Stability::Internal),
            $plugin->crash(...),
            owner: self::ID,
        );
        $registry->add(
            new Contract('events.list', Stability::Experimental, [Expose::Http]),
            $plugin->list(...),
            owner: self::ID,
        );
        $registry->add(
            new Contract('events.publish', Stability::Experimental, [Expose::Http]),
            $plugin->publish(...),
            owner: self::ID,
        );

        return new Options(
            manifest: $manifest,
            registry: $registry,
            subscriptions: [
                new Subscription('echo.events', $plugin->handleEvent(...)),
                // 同一主题的另一个类型用广播：echo.cleared 以两种方式各到一次，运行时按投递带来的方式分给各自的订阅
                new Subscription('echo.events', $plugin->handleEvent(...), 'echo.cleared', SubscriptionMode::Broadcast),
            ],
            catalogs: [Catalog::fromDirectory('echo', dirname(__DIR__) . '/Localization')],
            hooks: new Hooks(
                configure: $plugin->configure(...),
                health: static fn (): Health => new Health(LifecycleState::Ready, [new HealthItem('echo')]),
            ),
            streams: [
                new StreamingOperation(
                    new Contract('echoes.stream', Stability::Experimental),
                    $plugin->stream(...),
                ),
            ],
        );
    }

    /**
     * 读配置节 XiHan:Echo 的 greeting。
     *
     * @return Result<Unit>
     */
    private function configure(Configuration $configuration): Result
    {
        $greeting = $configuration->section['greeting'] ?? '';
        if (!is_string($greeting)) {
            return Result::fail(Error::validation(
                'ECHO_VALIDATION_001',
                'echo.configuration.invalid',
                ['reason' => 'greeting must be a string'],
            ));
        }
        $this->greeting = $greeting;

        return Result::success();
    }

    /**
     * 回声文本并附上下文；文本为空是业务失败，宿主用握手时拿到的目录翻译它。
     *
     * @return Result<array<string, string>>
     */
    private function create(Context $context, CreateEcho $request): Result
    {
        if ($request->text === '') {
            return Result::fail(
                Error::validation('ECHO_VALIDATION_002', 'echo.text.missing', ['operation' => 'echoes.create']),
            );
        }

        return Result::ok(array_filter([
            'text' => $request->text,
            'greeting' => $this->greeting,
            'tenant' => $context->tenant() ?? '',
            'principal' => $context->principal() ?? '',
            'correlation' => $context->correlation() ?? '',
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * 经宿主回调调用另一个操作，把宿主的应答原样返回；没有回调端点时失败。
     *
     * @return Result<RawJson>
     */
    private function relay(Context $context, RelayEcho $request): Result
    {
        $host = Host::of($context);
        if ($host === null) {
            return Result::fail(Error::unavailable('ECHO_UNAVAILABLE_001', 'echo.host.unavailable'));
        }
        $response = $host->invoke($context, $request->operation, $request->payload->json ?? '{}');
        $error = $response->outcome->error();
        if ($error !== null) {
            return Result::fail($error);
        }

        $payload = $response->payload ?? '';

        return Result::ok(RawJson::of($payload === '' ? '{}' : $payload));
    }

    /**
     * 故意让进程异常退出而不应答：宿主应记为崩溃并按退避重启。
     *
     * @return Result<Unit>
     */
    private function crash(Context $context, JsonObject $request): Result
    {
        $this->logger->critical('Crashing on request.');

        exit(3);
    }

    /**
     * 列出收到的事件 ID。
     *
     * @return Result<array{ids: list<string>}>
     */
    private function list(Context $context, JsonObject $request): Result
    {
        return Result::ok(['ids' => $this->events]);
    }

    /**
     * 经宿主发布一条消息到 echo.events，返回逐项结果。
     *
     * @return Result<array{published: bool}>
     */
    private function publish(Context $context, JsonObject $request): Result
    {
        $host = Host::of($context);
        if ($host === null) {
            return Result::fail(Error::unavailable('ECHO_UNAVAILABLE_001', 'echo.host.unavailable'));
        }
        $type = $request['type'] ?? 'echo.published';
        [$outcome] = $host->publish($context, [new Envelope(
            'echo.events',
            bin2hex(random_bytes(16)),
            'urn:xihan:samples:echo-php',
            is_string($type) ? $type : 'echo.published',
            '{}',
        )]);
        $error = $outcome->error();

        return $error === null ? Result::ok(['published' => true]) : Result::fail($error);
    }

    /**
     * 流式回声：把调用方的每个 chunk 包成 {"echo": …} 发回，调用方结束时正常结束。
     *
     * @return Result<Unit>
     */
    private function stream(Context $context, OperationStream $stream): Result
    {
        while (($chunk = $stream->receive()) !== null) {
            $stream->send('{"echo":' . $chunk . '}');
        }

        return Result::success();
    }

    /**
     * 记下投递的事件 ID；至少一次投递，同一个 ID 可能到多次。
     *
     * @return Result<Unit>
     */
    private function handleEvent(Context $context, Envelope $envelope, int $attempt): Result
    {
        $this->events[] = $envelope->id;
        if (count($this->events) > self::MAX_EVENTS) {
            array_shift($this->events);
        }

        return Result::success();
    }
}
