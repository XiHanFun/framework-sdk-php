<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\HealthStatus;
use XiHan\Framework\Plugin\Hooks;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\Runtime;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Plugin\SubscriptionMode;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\FrameworkInfo;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\RawJson;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;
use XiHan\Framework\Tests\Vectors;

use function Amp\async;
use function Amp\delay;

/**
 * 插件一侧的运行时（lifecycle.md §7，invocation.md §1–§4）：测试扮演宿主，在进程内对接运行时，逐条核对次序表、配置、
 * 启动与就绪、健康与健康推送、停止与收尾、调用、流式调用与投递的核对顺序、会话令牌与拒绝启动。
 */
final class RuntimeTest extends TestCase
{
    private const string MANIFEST = <<<'JSON'
        {
          "id": "xihan.tests.items",
          "version": "1.0.0",
          "primitives": "1",
          "stability": "experimental",
          "kind": "plugin",
          "isolation": "out-of-process",
          "contributes": {
            "operations": ["items.get", "items.slow", "items.literal"],
            "health": ["items"]
          },
          "permissions": ["capability:kernel.pipeline"],
          "entry": "items"
        }
        JSON;

    /** @var list<string> 回调与处理器留下的记录，按发生的顺序 */
    private array $events = [];

    /** 健康回调被调用的次数 */
    private int $healthCalls = 0;

    /** 测试改它来切换健康回调报的结果 */
    private string $report = 'ready';

    /** 测试插件在 Configure 里读到的问候语 */
    private string $greeting = '';

    /** 处理器最近收到的信封 */
    private ?Envelope $delivered = null;

    /** @var \Closure(): Health 当前的健康回调 */
    private \Closure $health;

    protected function setUp(): void
    {
        $this->health = static fn (): Health => new Health(LifecycleState::Ready, [
            new HealthItem('items'),
            new HealthItem('undeclared', HealthStatus::Unhealthy),
        ]);
    }

    public function testHandshakeOrderAndReadiness(): void
    {
        $harness = new Harness();
        // 清单原文带 BOM：读与握手都按去掉 BOM 之后的文本
        $harness->serve($this->options($harness, manifest: "\xEF\xBB\xBF" . self::MANIFEST));
        try {
            // 握手之前：Configure、Start、Stop 都不在次序表里；Health 报本地状态、不调健康回调
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->configure()));
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->start()));
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->stop()));
            self::assertHealth($harness->health(), LifecycleState::Declared, []);

            $handshake = $harness->handshake();
            self::assertSame(1, $handshake->getProtocolVersion());
            self::assertSame(Runtime::PROTOCOL_VERSION, $handshake->getProtocolVersion());
            // 握手上报的 SDK 身份取自 FrameworkInfo（plugin.proto 的 SdkInfo）
            $sdk = $handshake->getSdk();
            self::assertInstanceOf(V1\SdkInfo::class, $sdk);
            self::assertSame('php', FrameworkInfo::SDK_LANGUAGE);
            self::assertSame(FrameworkInfo::SDK_LANGUAGE, $sdk->getLanguage());
            self::assertSame(FrameworkInfo::SDK_NAME, $sdk->getName());
            self::assertSame(FrameworkInfo::SDK_VERSION, $sdk->getVersion());
            self::assertSame(1, $handshake->getPrimitives());
            self::assertSame(self::MANIFEST, $handshake->getManifest());
            $operations = [];
            foreach ($handshake->getOperations() as $operation) {
                self::assertInstanceOf(V1\OperationDeclaration::class, $operation);
                self::assertFalse($operation->getStreaming());
                $operations[] = $operation->getId();
            }
            self::assertSame(['items.get', 'items.literal', 'items.slow'], $operations);
            $subscriptions = [];
            foreach ($handshake->getSubscriptions() as $subscription) {
                self::assertInstanceOf(V1\Subscription::class, $subscription);
                $subscriptions[] = $subscription->getMessageType() . '/' . $subscription->getMode();
            }
            // 按声明的顺序，方式总是写出（没写的是 competing）
            self::assertSame(['/competing', 'x/competing', 'y/broadcast'], $subscriptions);
            // 运行时自己的目录不随握手发送
            self::assertCount(0, $handshake->getCatalogs());

            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->handshake()));
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->start()));
            self::assertHealth($harness->health(), LifecycleState::Resolved, []);

            self::assertTrue($harness->configure('{"Greeting":"hi"}')->getStatus()?->getOk());
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->configure()));
            self::assertHealth($harness->health(), LifecycleState::Configured, []);
            self::assertSame(0, $this->healthCalls);

            // Start 之前：未声明的操作仍先报 Unimplemented，声明的操作与投递报 plugins.process.unavailable
            self::assertSame(GrpcStatus::UNIMPLEMENTED, Harness::grpcStatus(fn () => $harness->invoke('items.missing')));
            $unavailable = [
                'code' => 'PLUGINS_UNAVAILABLE_001',
                'key' => 'plugins.process.unavailable',
                'text' => '',
                'details' => ['id' => 'xihan.tests.items', 'state' => 'Configured'],
            ];
            self::assertSame($unavailable, Harness::error($harness->invoke('items.get')->getStatus()?->getError()));
            $delivered = $harness->deliver(self::envelope('items.changed', 'e0', 'x'));
            self::assertSame($unavailable, Harness::error($delivered->getStatus()?->getError()));
            // 找不到订阅先于就绪核对
            $missing = $harness->deliver(self::envelope('nobody.listens', 'e0', 't'));
            self::assertSame('plugins.event.unsubscribed', $missing->getStatus()?->getError()?->getMessageKey());

            // Start 应答之前已经求过一次健康，按结果进入 Ready
            self::assertTrue($harness->start()->getStatus()?->getOk());
            self::assertSame(1, $this->healthCalls);
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->start()));
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->configure()));
            // 可服务期间每次 Health 都求一次健康回调，未声明的健康项丢掉、同名只告警一次
            self::assertHealth($harness->health(), LifecycleState::Ready, ['items']);
            self::assertHealth($harness->health(), LifecycleState::Ready, ['items']);
            self::assertSame(3, $this->healthCalls);
            $warnings = array_filter(
                $harness->messages('warning'),
                static fn (mixed $message): bool => is_string($message) && str_contains($message, 'contributes.health'),
            );
            self::assertCount(1, $warnings);

            $response = $harness->invoke('items.get', '', ['xihan.tenant' => 't1']);
            self::assertTrue($response->getStatus()?->getOk());
            // 空载荷等同 {}；配置节的成员名按 ASCII 不区分大小写读
            self::assertSame('{"id":null,"tenant":"t1","greeting":"hi"}', $response->getPayload());
        } finally {
            $harness->close();
        }
    }

    public function testInvocationsStreamsAndDeliveries(): void
    {
        $harness = $this->started();
        try {
            // 带消息键的错误不写 text，details 是参数的 JSON 对象；自由文本错误写 text
            $failed = $harness->invoke('items.get', '{"id":"missing"}');
            self::assertSame([
                'code' => 'ITEMS_NOTFOUND_001',
                'key' => 'items.item.missing',
                'text' => '',
                'details' => ['id' => 'missing'],
            ], Harness::error($failed->getStatus()?->getError()));
            self::assertSame([
                'code' => 'ITEMS_CONFLICT_001',
                'key' => '',
                'text' => 'stale',
                'details' => null,
            ], Harness::error($harness->invoke('items.literal')->getStatus()?->getError()));

            // 流式调用：读第一帧，不是 start 为 InvalidArgument，是 start 为 Unimplemented，不论操作是否声明
            foreach (
                [
                    [(new V1\StreamFrame())->setChunk('{}'), GrpcStatus::INVALID_ARGUMENT],
                    [null, GrpcStatus::INVALID_ARGUMENT],
                    [(new V1\StreamFrame())->setStart((new V1\InvokeRequest())->setOperation('items.get')), GrpcStatus::UNIMPLEMENTED],
                    [(new V1\StreamFrame())->setStart((new V1\InvokeRequest())->setOperation('items.missing')), GrpcStatus::UNIMPLEMENTED],
                ] as [$frame, $expected]
            ) {
                $stream = $harness->client()->call('/xihan.plugin.v1.Plugin/InvokeStream');
                if ($frame === null) {
                    $stream->closeSend();
                } else {
                    // 不结束发送方向：插件读到第一帧就作出判定
                    $stream->send($frame->serializeToString());
                }
                self::assertSame($expected, Harness::grpcStatus(static fn () => $stream->receive()));
            }

            // 投递：没有信封与不认识的方式是 InvalidArgument
            self::assertSame(GrpcStatus::INVALID_ARGUMENT, Harness::grpcStatus(fn () => $harness->deliver(null)));
            self::assertSame(GrpcStatus::INVALID_ARGUMENT, Harness::grpcStatus(fn () => $harness->deliver(self::envelope('items.changed', 'e1', 'x'), 'bogus')));
            // 宿主写来的 time 读不出：不交给作者，也不当作没有时间；先于找订阅（invocation.md §4）
            foreach (['items.changed', 'nobody.listens'] as $topic) {
                $unreadable = self::envelope($topic, 'e1', 'x')->setTime('2026-11-25T03:25:00');
                self::assertSame(GrpcStatus::INVALID_ARGUMENT, Harness::grpcStatus(fn () => $harness->deliver($unreadable)), $topic);
            }
            // sdk-envelope-times.json 的 unreadable 每一条都以 InvalidArgument 拒绝（ADR-0310）
            $unreadableTimes = Vectors::object('plugin/sdk-envelope-times')->unreadable;
            self::assertIsArray($unreadableTimes);
            foreach ($unreadableTimes as $case) {
                self::assertInstanceOf(\stdClass::class, $case);
                self::assertIsString($case->name);
                self::assertIsString($case->text);
                $unreadable = self::envelope('items.changed', 'e1', 'x')->setTime($case->text);
                self::assertSame(GrpcStatus::INVALID_ARGUMENT, Harness::grpcStatus(fn () => $harness->deliver($unreadable)), $case->name);
            }
            self::assertSame([], $this->events, 'an envelope whose time cannot be read reaches no handler');
            self::assertSame([
                'code' => 'KERNEL_NOTFOUND_001',
                'key' => 'plugins.event.unsubscribed',
                'text' => '',
                'details' => ['id' => 'xihan.tests.items', 'topic' => 'nobody.listens', 'type' => 't', 'mode' => 'competing'],
            ], Harness::error($harness->deliver(self::envelope('nobody.listens', 'e1', 't'), '')->getStatus()?->getError()));
            // 广播的 x 没有以广播订阅：不改交给竞争的订阅
            self::assertSame('plugins.event.unsubscribed', $harness->deliver(self::envelope('items.changed', 'e1', 'x'), 'broadcast')->getStatus()?->getError()?->getMessageKey());

            // 匹配的订阅按声明顺序依次执行，第一个失败即结果
            $this->events = [];
            self::assertTrue($harness->deliver(self::envelope('items.changed', 'e2', 'x'))->getStatus()?->getOk());
            self::assertSame(['all:e2', 'x:e2'], $this->events);
            $this->events = [];
            $failed = $harness->deliver(self::envelope('items.changed', 'fail', 'x'));
            self::assertSame('ITEMS_UNAVAILABLE_001', $failed->getStatus()?->getError()?->getCode());
            self::assertSame(['all:fail'], $this->events);
            $this->events = [];
            $thrown = $harness->deliver(self::envelope('items.changed', 'throw', 'x'));
            self::assertSame(['code' => 'KERNEL_UNEXPECTED_001', 'key' => 'kernel.invocation.unhandled_exception', 'text' => '', 'details' => null], Harness::error($thrown->getStatus()?->getError()));
            self::assertTrue($harness->deliver(self::envelope('items.changed', 'e3', 'y'), 'broadcast')->getStatus()?->getOk());
            self::assertSame(['all:throw', 'y:e3'], $this->events);

            // 时间换算成 UTC、比微秒更细的小数截断，dataschema 原样、不裁剪；空串都是没有
            $timed = self::envelope('items.changed', 'e4', 'z')->setTime('2026-11-25T11:25:00.1234567+08:00')
                ->setDataSchema(' https://example.com/schemas/item.json ');
            self::assertTrue($harness->deliver($timed)->getStatus()?->getOk());
            self::assertNotNull($this->delivered);
            self::assertSame('2026-11-25T03:25:00.123456+00:00', $this->delivered->time?->format('Y-m-d\TH:i:s.uP'));
            self::assertSame(' https://example.com/schemas/item.json ', $this->delivered->dataSchema);
            self::assertTrue($harness->deliver(self::envelope('items.changed', 'e5', 'z'))->getStatus()?->getOk());
            self::assertNotNull($this->delivered);
            self::assertNull($this->delivered->time);
            self::assertSame('', $this->delivered->dataSchema);
        } finally {
            $harness->close();
        }
    }

    public function testResponsesThatAreNotValidJsonTextFailInThePlugin(): void
    {
        // 类型化的应答写不成合法的 JSON 文本即写不出（invocation.md §1「插件一侧写出应答」，ADR-0318）：值、键与数组元素里不是合法
        // UTF-8 的字节经 Status.error 报 unhandled_exception，没有参数、不带 inner，payload 为空，不写成 U+FFFD 交给宿主
        $harness = $this->started();
        try {
            foreach (['surrogate', 'byte-key', 'element'] as $id) {
                $failed = $harness->invoke('items.get', '{"id":"' . $id . '"}');
                $status = $failed->getStatus();
                self::assertNotNull($status, $id);
                self::assertFalse($status->getOk(), $id);
                self::assertSame('', $failed->getPayload(), $id);
                $error = $status->getError();
                self::assertSame(
                    ['code' => 'KERNEL_UNEXPECTED_001', 'key' => 'kernel.invocation.unhandled_exception', 'text' => '', 'details' => null],
                    Harness::error($error),
                    $id,
                );
                self::assertFalse($error?->hasInner(), $id);
            }
            // 多字节文本与真正的 U+FFFD 照常写出
            $text = "中文\u{1F600}\u{FFFD}";
            $written = $harness->invoke('items.get', '{"id":"' . $text . '"}');
            self::assertTrue($written->getStatus()?->getOk());
            self::assertSame('{"id":"' . $text . '","tenant":null,"greeting":"hi"}', $written->getPayload());
        } finally {
            $harness->close();
        }
    }

    public function testResponsesThatDoNotPassTheJsonTextChecksFailInThePlugin(): void
    {
        // 写出的整段文本要过共同检查（invocation.md §1「插件一侧写出应答」，ADR-0319）：嵌套超过 64 层（RawJson 原样嵌进去的文本
        // 也算）经 Status.error 报 unhandled_exception，没有参数、不带 inner，payload 为空，不交给宿主去拒绝
        $harness = $this->started();
        try {
            foreach (['deep', 'raw-deep'] as $id) {
                $failed = $harness->invoke('items.get', '{"id":"' . $id . '"}');
                $status = $failed->getStatus();
                self::assertNotNull($status, $id);
                self::assertFalse($status->getOk(), $id);
                self::assertSame('', $failed->getPayload(), $id);
                $error = $status->getError();
                self::assertSame(
                    ['code' => 'KERNEL_UNEXPECTED_001', 'key' => 'kernel.invocation.unhandled_exception', 'text' => '', 'details' => null],
                    Harness::error($error),
                    $id,
                );
                self::assertFalse($error?->hasInner(), $id);
            }
            // 恰好 64 层照常写出
            $written = $harness->invoke('items.get', '{"id":"limit"}');
            self::assertTrue($written->getStatus()?->getOk());
            self::assertSame(str_repeat('[', 64) . str_repeat(']', 64), $written->getPayload());
        } finally {
            $harness->close();
        }
    }

    /**
     * `$depth` 层嵌套的空数组，最外层是第 1 层。
     *
     * @return list<mixed>
     */
    private static function nested(int $depth): array
    {
        $value = [];
        for ($level = 1; $level < $depth; $level++) {
            $value = [$value];
        }

        return $value;
    }

    public function testSessionTokenIsCheckedBeforeAnything(): void
    {
        $harness = (new Harness());
        $harness->serve($this->options($harness));
        try {
            $attempts = [
                [],
                ['xihan-plugin-session' => 'wrong'],
                ['xihan-plugin-session' => [Harness::SESSION, 'wrong']],
                ['xihan-plugin-session' => ['wrong', Harness::SESSION]],
                ['xihan-plugin-session' => [Harness::SESSION, Harness::SESSION]],
            ];
            foreach ($attempts as $metadata) {
                $client = $harness->client($metadata);
                self::assertSame(GrpcStatus::UNAUTHENTICATED, Harness::grpcStatus(fn () => $harness->unary('Handshake', null, new V1\HandshakeResponse(), $client)));
                // 流式调用同样先核对令牌，不读第一帧
                $stream = $client->call('/xihan.plugin.v1.Plugin/InvokeStream');
                self::assertSame(GrpcStatus::UNAUTHENTICATED, Harness::grpcStatus(static fn () => $stream->receive()));
                $client->close();
            }
        } finally {
            $harness->close();
        }
    }

    public function testWatchHealthPushesChangesAndEndsAfterStop(): void
    {
        $this->health = function (): Health {
            return $this->report === 'degraded'
                ? new Health(LifecycleState::Degraded, [new HealthItem('items', HealthStatus::Degraded)], Error::literal('ITEMS_UNAVAILABLE_001', 'slow'))
                : new Health(LifecycleState::Ready, [new HealthItem('items')]);
        };
        $harness = $this->started();
        $watch = $harness->client()->call('/xihan.plugin.v1.Plugin/WatchHealth');
        $watch->send('', true);
        $first = new V1\HealthResponse();
        $first->mergeFromString((string) $watch->receive());
        self::assertHealth($first, LifecycleState::Ready, ['items']);

        // Health 求到的变化同样推给 WatchHealth
        $this->report = 'degraded';
        self::assertHealth($harness->health(), LifecycleState::Degraded, ['items']);
        $pushed = new V1\HealthResponse();
        $pushed->mergeFromString((string) $watch->receive());
        self::assertHealth($pushed, LifecycleState::Degraded, ['items']);
        self::assertSame('slow', $pushed->getError()?->getText());

        $stopped = $harness->stop(2, 'unload');
        self::assertTrue($stopped->getStatus()?->getOk());
        // 进入 Draining 推一次，应答 Stop 后结束流
        $last = new V1\HealthResponse();
        $last->mergeFromString((string) $watch->receive());
        self::assertSame(LifecycleState::Draining->number(), $last->getState());
        self::assertNull($watch->receive());
        self::assertSame(0, $harness->exitCode());
        self::assertSame(['stop:unload'], $this->events);
        self::assertContains('Stopped.', $harness->messages('information'));
    }

    public function testHealthHookOutcomes(): void
    {
        $this->report = 'throw';
        $this->health = function (): Health {
            return match ($this->report) {
                'throw' => throw new \RuntimeException('/secret/path'),
                'failed' => new Health(LifecycleState::Failed, [], Error::literal('ITEMS_UNEXPECTED_001', 'gone')),
                'started' => new Health(LifecycleState::Started, [new HealthItem('items')]),
                default => new Health(LifecycleState::Ready, [new HealthItem('items')]),
            };
        };
        $harness = $this->started();
        try {
            // 启动时求的那一次抛出：这一次是 Degraded，原因不带异常的消息，没有健康项；Degraded 仍可服务
            $health = $harness->health();
            self::assertHealth($health, LifecycleState::Degraded, []);
            self::assertSame([
                'code' => 'PLUGINS_UNEXPECTED_001',
                'key' => 'plugins.process.failed',
                'text' => '',
                'details' => ['id' => 'xihan.tests.items', 'phase' => 'health', 'reason' => 'RuntimeException'],
            ], Harness::error($health->getError()));
            self::assertTrue($harness->invoke('items.get', '{"id":"a"}')->getStatus()?->getOk());

            $this->report = 'ready';
            self::assertHealth($harness->health(), LifecycleState::Ready, ['items']);
            // 可服务期间抛出：这一次是 Degraded，状态也迁到 Degraded；之后的结果照常决定状态
            $this->report = 'throw';
            self::assertHealth($harness->health(), LifecycleState::Degraded, []);
            $this->report = 'started';
            // 可服务期间报其他状态的不改变状态
            self::assertHealth($harness->health(), LifecycleState::Degraded, ['items']);
            $this->report = 'ready';
            self::assertHealth($harness->health(), LifecycleState::Ready, ['items']);
            $this->report = 'failed';
            self::assertHealth($harness->health(), LifecycleState::Failed, []);
            $calls = $this->healthCalls;
            // Failed 之后不再调健康回调，也不再回来
            $this->report = 'ready';
            self::assertHealth($harness->health(), LifecycleState::Failed, []);
            self::assertSame($calls, $this->healthCalls);
            self::assertSame(['id' => 'xihan.tests.items', 'state' => 'Failed'], Harness::error($harness->invoke('items.get')->getStatus()?->getError())['details']);

            // 启动成功过之后进入 Failed：照样调停止回调、应答、退出
            self::assertTrue($harness->stop()->getStatus()?->getOk());
            self::assertSame(0, $harness->exitCode());
            self::assertSame(['stop:unload'], $this->events);
        } finally {
            $harness->close();
        }
    }

    public function testStartTreatsOtherReportedStatesAsReady(): void
    {
        $this->health = fn (): Health => $this->report === 'started'
            ? new Health(LifecycleState::Started, [new HealthItem('items')])
            : new Health(LifecycleState::Draining);
        $this->report = 'started';
        $harness = $this->started();
        try {
            // Start 时健康回调报了 Ready、Degraded、Failed 之外的状态：按 Ready（lifecycle.md §7.3）
            self::assertHealth($harness->health(), LifecycleState::Ready, ['items']);
            $this->report = 'draining';
            self::assertHealth($harness->health(), LifecycleState::Ready, []);
            self::assertTrue($harness->invoke('items.get', '{"id":"a"}')->getStatus()?->getOk());
        } finally {
            $harness->close();
        }
    }

    public function testConfigurationThatIsNotAnObjectFails(): void
    {
        foreach (['[]', '{"a":', '{"a":1,"a":2}', '"text"'] as $configuration) {
            $harness = new Harness();
            $harness->serve($this->options($harness));
            try {
                $harness->handshake();
                self::assertSame([
                    'code' => 'PLUGINS_UNEXPECTED_001',
                    'key' => 'plugins.process.failed',
                    'text' => '',
                    'details' => ['id' => 'xihan.tests.items', 'phase' => 'configure', 'reason' => 'the configuration is not a JSON object'],
                ], Harness::error($harness->configure($configuration)->getStatus()?->getError()), $configuration);
                self::assertHealth($harness->health(), LifecycleState::Failed, []);
                // 启动回调没有成功过：不调停止回调，应答 ok，以 0 退出
                self::assertTrue($harness->stop(0)->getStatus()?->getOk());
                self::assertSame(0, $harness->exitCode());
                self::assertSame([], $this->events, 'the configure and stop hooks are not called');
            } finally {
                $harness->close();
            }
        }
    }

    public function testHooksThatThrowReportTheSimpleTypeName(): void
    {
        $cases = [
            'configure' => [new Hooks(configure: static fn (): Result => throw new ItemsException('/secret')), 'ItemsException'],
            'start' => [new Hooks(start: static fn (): Result => throw new class ('/secret') extends \RuntimeException {
            }), 'RuntimeException@anonymous'],
            // @phpstan-ignore argument.type (故意返回不是 Result 的值：运行期按抛出 TypeError 处理)
            'start returning no Result' => [new Hooks(start: static fn (): string => 'not a result'), 'TypeError'],
        ];
        foreach ($cases as $name => [$hooks, $reason]) {
            $harness = new Harness();
            $harness->serve($this->options($harness, hooks: $hooks));
            try {
                $harness->handshake();
                $configured = $harness->configure()->getStatus();
                $status = $configured?->getOk() === true ? $harness->start()->getStatus() : $configured;
                $error = Harness::error($status?->getError());
                self::assertSame('plugins.process.failed', $error['key'], $name);
                self::assertSame($reason, is_array($error['details']) ? $error['details']['reason'] : null, $name);
                self::assertHealth($harness->health(), LifecycleState::Failed, []);
                self::assertTrue($harness->stop()->getStatus()?->getOk());
                self::assertSame(0, $harness->exitCode());
                // 异常的消息只进日志
                if ($reason !== 'TypeError') {
                    rewind($harness->stderr);
                    self::assertStringContainsString('/secret', (string) stream_get_contents($harness->stderr));
                }
            } finally {
                $harness->close();
            }
        }

        // 停止回调抛出：写进 StopResponse.status，照常退出
        $harness = $this->started(new Hooks(stop: static fn (): Result => throw new \LogicException('boom')));
        try {
            $stopped = $harness->stop();
            self::assertSame(['id' => 'xihan.tests.items', 'phase' => 'stop', 'reason' => 'LogicException'], Harness::error($stopped->getStatus()?->getError())['details']);
            self::assertSame(0, $harness->exitCode());
        } finally {
            $harness->close();
        }
    }

    public function testStopWaitsForInFlightCallsUntilTheGrace(): void
    {
        $harness = $this->started();
        try {
            $slow = async(fn (): V1\InvokeResponse => $harness->invoke('items.slow'));
            delay(0.1);
            $began = microtime(true);
            $stop = async(fn (): V1\StopResponse => $harness->stop(1));
            delay(0.1);
            // 停止中：新的调用以 PLUGINS_UNAVAILABLE_001 拒绝，state 为 Draining；Stop 也不再接受
            self::assertSame(['id' => 'xihan.tests.items', 'state' => 'Draining'], Harness::error($harness->invoke('items.get')->getStatus()?->getError())['details']);
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, Harness::grpcStatus(fn () => $harness->stop()));
            $stopped = $stop->await();
            self::assertInstanceOf(V1\StopResponse::class, $stopped);
            self::assertTrue($stopped->getStatus()?->getOk());
            $elapsed = microtime(true) - $began;
            // 宽限到了触发在途调用的取消信号、不再等（不等它真的结束），然后调停止回调
            self::assertGreaterThan(0.8, $elapsed);
            self::assertLessThan(2.5, $elapsed);
            // 被取消的调用来得及应答时是 KERNEL_CANCELLED_001，来不及时随服务端停止、连接断开一起结束
            try {
                $response = $slow->await();
                self::assertInstanceOf(V1\InvokeResponse::class, $response);
                self::assertSame('KERNEL_CANCELLED_001', $response->getStatus()?->getError()?->getCode());
            } catch (GrpcException $exception) {
                self::assertSame(GrpcStatus::UNAVAILABLE, $exception->status);
            }
            self::assertSame(0, $harness->exitCode());
            delay(0.1);
            self::assertEqualsCanonicalizing(['slow:cancelled', 'stop:unload'], $this->events);
        } finally {
            $harness->close();
        }
    }

    public function testStdinClosingDrainsAndCallsTheStopHookOnce(): void
    {
        $harness = $this->started();
        try {
            $slow = async(fn (): V1\InvokeResponse => $harness->invoke('items.slow'));
            delay(0.1);
            $began = microtime(true);
            $harness->closeStdin();
            // 立即触发在途调用的取消信号
            $response = $slow->await();
            self::assertInstanceOf(V1\InvokeResponse::class, $response);
            self::assertSame('KERNEL_CANCELLED_001', $response->getStatus()?->getError()?->getCode());
            self::assertSame(0, $harness->exitCode());
            self::assertLessThan(2.0, microtime(true) - $began);
            self::assertSame(['slow:cancelled', 'stop:shutdown'], $this->events);
        } finally {
            $harness->close();
        }

        // 停止回调迟迟不返回：从 stdin 关闭起最多 3 秒就退出
        $harness = $this->started(new Hooks(stop: static function (): Result {
            delay(6);

            return Result::success();
        }));
        try {
            $began = microtime(true);
            $harness->closeStdin();
            self::assertSame(0, $harness->exitCode());
            $elapsed = microtime(true) - $began;
            self::assertGreaterThan(2.5, $elapsed);
            self::assertLessThan(4.5, $elapsed);
        } finally {
            $harness->close();
        }

        // 启动之前关闭 stdin：没有启动成功过，不调停止回调
        $harness = new Harness();
        $harness->serve($this->options($harness));
        $this->events = [];
        $harness->closeStdin();
        self::assertSame(0, $harness->exitCode());
        self::assertSame([], $this->events);
        $harness->close();
    }

    public function testRefusalsLogAnErrorAndWriteNoHandshakeLine(): void
    {
        $wasm = str_replace('"isolation": "out-of-process",', '"isolation": "wasm",', self::MANIFEST);
        $middleware = str_replace('"health": ["items"]', '"health": ["items"], "pipeline": [{"middleware": "M", "stage": "observe"}]', self::MANIFEST);
        $duplicate = new Subscription('items.changed', static fn (): Result => Result::success());
        $cases = [
            'not out of process' => [$wasm, [], [], null, 'isolation out-of-process'],
            'middleware' => [$middleware, [], [], null, 'contributes.pipeline'],
            'primitives' => [str_replace('"primitives": "1"', '"primitives": "2"', self::MANIFEST), [], [], null, "primitives '2'"],
            'undeclared operation' => [str_replace('"items.get", ', '', self::MANIFEST), [], [], null, "'items.get'"],
            'duplicate subscriptions' => [self::MANIFEST, [], [$duplicate, $duplicate], null, 'more than once'],
            'not json' => ['{"id":', [], [], null, 'KERNEL_CAPABILITY_006'],
            'no session' => [self::MANIFEST, [Runtime::ENV_SESSION => null], [], null, 'XIHAN_PLUGIN_SESSION'],
            'no versions' => [self::MANIFEST, [Runtime::ENV_PROTOCOL_VERSIONS => null], [], null, 'XIHAN_PLUGIN_PROTOCOL_VERSIONS'],
            'listen address' => [self::MANIFEST, [], [], '127.0.0.1:99999', 'loopback'],
        ];
        foreach ($cases as $name => [$manifest, $variables, $subscriptions, $address, $expected]) {
            $harness = new Harness();
            $options = $this->options($harness, manifest: $manifest, environment: $variables, subscriptions: $subscriptions === [] ? null : $subscriptions, address: $address);
            self::assertSame(Runtime::EXIT_REFUSED, Runtime::serve($options), $name);
            self::assertSame('', $harness->stdoutText(), $name);
            $errors = array_values(array_filter($harness->logs(), static fn (array $entry): bool => $entry['level'] === 'error'));
            self::assertCount(1, $errors, $name);
            self::assertSame('The plugin refused to start.', $errors[0]['message'], $name);
            self::assertIsString($errors[0]['reason'] ?? null);
            self::assertIsString($errors[0]['code'] ?? null);
            self::assertStringContainsString($expected, $errors[0]['code'] . ' ' . $errors[0]['reason'], $name);
            $harness->close();
        }
    }

    /**
     * 起一个配置并启动好的插件。
     */
    private function started(?Hooks $hooks = null): Harness
    {
        $harness = new Harness();
        $harness->serve($this->options($harness, hooks: $hooks));
        $harness->handshake();
        self::assertTrue($harness->configure('{"greeting":"hi"}')->getStatus()?->getOk());
        self::assertTrue($harness->start()->getStatus()?->getOk());
        $this->events = [];

        return $harness;
    }

    /**
     * 组装测试插件：items.get 回显、items.slow 等到取消、items.literal 返回自由文本错误；订阅 items.changed 的全部类型
     * （竞争）、类型 x（竞争）与类型 y（广播）。items.get 对几个特定的 id 交回写不出的应答。
     *
     * @param array<string, string|null> $environment
     * @param list<Subscription>|null $subscriptions
     */
    private function options(
        Harness $harness,
        ?Hooks $hooks = null,
        string $manifest = self::MANIFEST,
        array $environment = [],
        ?array $subscriptions = null,
        ?string $address = null,
    ): Options {
        $registry = new Registry();
        $registry->add(new Contract('items.get', Stability::Experimental), function (Context $context, JsonObject $request): Result {
            if ($request['id'] === 'missing') {
                return Result::fail(Error::notFound('ITEMS_NOTFOUND_001', 'items.item.missing', ['id' => 'missing']));
            }
            // 写不成合法 JSON 文本的应答：值、键与数组元素里不是合法 UTF-8 的字节，嵌套超过 64 层（RawJson 的原文从根算起）；
            // limit 恰好 64 层，照常写出
            $unwritable = match ($request['id']) {
                'surrogate' => ['id' => "a\xED\xA0\x80"],
                'byte-key' => ["\xFF" => 'b'],
                'element' => ['items' => ['c', "\xFF"]],
                'deep' => ['items' => self::nested(64)],
                'raw-deep' => [[RawJson::of(str_repeat('[', 63) . str_repeat(']', 63))]],
                'limit' => self::nested(64),
                default => null,
            };
            if ($unwritable !== null) {
                return Result::ok($unwritable);
            }

            return Result::ok(['id' => $request['id'], 'tenant' => $context->tenant(), 'greeting' => $this->greeting]);
        });
        $registry->add(new Contract('items.slow', Stability::Experimental), function (Context $context): Result {
            $until = microtime(true) + 10;
            while (!$context->cancellation()->isRequested() && microtime(true) < $until) {
                delay(0.01);
            }
            $this->events[] = 'slow:' . ($context->cancellation()->isRequested() ? 'cancelled' : 'finished');

            return Result::fail($context->cancellationError() ?? Error::literal('ITEMS_UNEXPECTED_001', 'not cancelled'));
        });
        $registry->add(new Contract('items.literal', Stability::Experimental), static fn (): Result => Result::fail(Error::literal('ITEMS_CONFLICT_001', 'stale')));
        $record = function (string $prefix): \Closure {
            return function (Context $context, Envelope $envelope) use ($prefix): Result {
                $this->events[] = $prefix . ':' . $envelope->id;
                $this->delivered = $envelope;

                return match ($envelope->id) {
                    'fail' => Result::fail(Error::literal('ITEMS_UNAVAILABLE_001', 'busy')),
                    'throw' => throw new \RuntimeException('boom'),
                    default => Result::success(),
                };
            };
        };
        $hooks ??= new Hooks();

        return new Options(
            manifest: $manifest,
            registry: $registry,
            subscriptions: $subscriptions ?? [
                new Subscription('items.changed', $record('all')),
                new Subscription('items.changed', $record('x'), 'x'),
                new Subscription('items.changed', $record('y'), 'y', SubscriptionMode::Broadcast),
            ],
            hooks: new Hooks(
                configure: $hooks->configure ?? function (Configuration $configuration): Result {
                    $this->greeting = $configuration->string('GREETING') ?? '';

                    return Result::success();
                },
                start: $hooks->start,
                stop: $hooks->stop ?? function (string $reason): Result {
                    $this->events[] = 'stop:' . $reason;

                    return Result::success();
                },
                health: function (): Health {
                    $this->healthCalls++;

                    return ($this->health)();
                },
            ),
            network: $address === null ? null : Network::Tcp,
            address: $address,
            stdin: $harness->stdin,
            stdout: $harness->stdout,
            stderr: $harness->stderr,
            environment: Harness::environment($environment),
        );
    }

    /**
     * 核对健康应答的状态与健康项名。
     *
     * @param list<string> $items
     */
    private static function assertHealth(V1\HealthResponse $health, LifecycleState $state, array $items): void
    {
        self::assertSame($state->number(), $health->getState(), 'state ' . $state->value);
        $names = [];
        foreach ($health->getItems() as $item) {
            self::assertInstanceOf(V1\HealthItem::class, $item);
            $names[] = $item->getName();
        }
        self::assertSame($items, $names);
    }

    /**
     * 组装一个信封。
     */
    private static function envelope(string $topic, string $id, string $type): V1\Envelope
    {
        return (new V1\Envelope())->setTopic($topic)->setId($id)->setType($type)->setSource('urn:test')->setSpecVersion('1.0');
    }
}
