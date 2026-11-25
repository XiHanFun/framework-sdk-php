<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Amp\Cancellation as AmpCancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Google\Protobuf\Internal\Message;
use Revolt\EventLoop;
use XiHan\Framework\Invocation\CancellationSource;
use XiHan\Framework\Invocation\Clock;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Invocation\DeadlineCancellation;
use XiHan\Framework\Manifest\Manifest;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Expose;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Operation\Response;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Logger;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\ResolvedBinding;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Plugin\SubscriptionMode;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\FrameworkInfo;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * xihan.plugin.v1.Plugin 服务的实现：握手、配置、启停、健康、调用与事件投递（handshake.md §3，lifecycle.md §7，
 * invocation.md §1–§4）。
 *
 * 会话令牌由服务端的守卫在进入这里之前核对，先于一切（含次序核对与流式调用读第一帧）。业务失败写进 Status.error，
 * gRPC 状态只用于协议层的失败：没有共同的协议版本与不在次序表里的调用（FAILED_PRECONDITION）、未声明的操作与流式调用
 * （UNIMPLEMENTED）、帧序或投递不合法（INVALID_ARGUMENT）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class PluginService
{
    /** 方法路径的前缀 */
    public const string PREFIX = '/xihan.plugin.v1.Plugin/';

    /** Stop 的 grace_seconds 为 0 时等在途调用的秒数（lifecycle.md §7.5） */
    public const float DEFAULT_GRACE = 5.0;

    /** WatchHealth 在可服务期间重新求健康回调的间隔（秒）：宿主 HealthInterval 的缺省值（lifecycle.md §7.4） */
    private const float HEALTH_INTERVAL = 10.0;

    /** 可服务的状态（lifecycle.md §7.3） */
    private const array SERVING = [LifecycleState::Ready, LifecycleState::Degraded];

    /** 健康回调报了会改变状态的三种状态（lifecycle.md §7.4） */
    private const array ADOPTED = [LifecycleState::Ready, LifecycleState::Degraded, LifecycleState::Failed];

    /** @var array<string, true> 已经告警过的未声明的健康项 */
    private array $undeclaredItems = [];

    /** @var array<int, CancellationSource> 在途的调用与投递：收尾时触发它们的取消信号 */
    private array $inFlight = [];

    /** 下一个在途调用的编号 */
    private int $nextCall = 0;

    /** @var DeferredFuture<null>|null 停止时等在途调用结束 */
    private ?DeferredFuture $idle = null;

    /** 启动回调是否成功过：决定停止时调不调停止回调（lifecycle.md §7.5） */
    private bool $started = false;

    /** @var DeferredFuture<Result<Unit>>|null 停止回调：第一次调用时创建，回调返回时带上结果 */
    private ?DeferredFuture $stopHook = null;

    /** @var list<HealthItem> 最近一次健康回调报的、已声明的健康项 */
    private array $items = [];

    /** 最近一次健康回调报的原因 */
    private ?Error $reported = null;

    /** 进入 Draining 时的健康应答：WatchHealth 结束之前一定推过它 */
    private ?string $drainingHealth = null;

    /** 应答 Stop 或收尾之后触发：WatchHealth 结束流 */
    private readonly DeferredCancellation $finished;

    /**
     * @param string $manifestText 清单原文（已去掉开头的 BOM），握手原样发出
     * @param \Closure(float): void $shutdown 在 Stop 应答之后停止服务端并让进程退出（等在途流的秒数）
     */
    public function __construct(
        private readonly Options $options,
        private readonly Manifest $manifest,
        private readonly string $manifestText,
        private readonly Invoker $invoker,
        private readonly Lifecycle $lifecycle,
        private readonly Logger $logger,
        private readonly TenantScope $tenants,
        private readonly Host $host,
        private readonly Clock $clock,
        private readonly \Closure $shutdown,
    ) {
        $this->finished = new DeferredCancellation();
    }

    /**
     * 返回方法路径 → 处理函数。
     *
     * @return array<string, \Closure(ServerCall): void>
     */
    public function methods(): array
    {
        return [
            self::PREFIX . 'Handshake' => $this->handshake(...),
            self::PREFIX . 'Configure' => $this->configure(...),
            self::PREFIX . 'Start' => $this->start(...),
            self::PREFIX . 'Stop' => $this->stop(...),
            self::PREFIX . 'Health' => $this->health(...),
            self::PREFIX . 'WatchHealth' => $this->watchHealth(...),
            self::PREFIX . 'Invoke' => $this->invoke(...),
            self::PREFIX . 'InvokeStream' => $this->invokeStream(...),
            self::PREFIX . 'HandleEvent' => $this->handleEvent(...),
        ];
    }

    /**
     * stdin 关闭或 SIGTERM 时收尾（lifecycle.md §7.7）：可服务的进入 Draining，立即触发全部在途调用的取消信号并等它们结束；
     * 启动回调成功过的调一次停止回调（原因 shutdown，正在进行的 Stop 已经调了就等它）。一共至多 $limit 秒，时限由调用方兜底。
     */
    public function drain(float $limit): void
    {
        $start = hrtime(true);
        if ($this->lifecycle->is(...self::SERVING)) {
            $this->beginDraining();
        }
        $this->cancelInFlight();
        $this->awaitIdle($limit);
        if ($this->started) {
            $remaining = max(0.0, $limit - (hrtime(true) - $start) / 1e9);
            $this->runStopHook('shutdown', $remaining, new TimeoutCancellation($remaining));
        }
    }

    /**
     * 结束全部 WatchHealth 流：应答 Stop 之后与收尾时调用。
     */
    public function finish(): void
    {
        if (!$this->finished->isCancelled()) {
            $this->finished->cancel();
        }
    }

    /**
     * 判断 WatchHealth 是否该结束：Stop 已经应答或已经收尾。
     *
     * @phpstan-impure
     */
    private function isFinished(): bool
    {
        return $this->finished->isCancelled();
    }

    /**
     * 握手：协商版本、报告原语层版本，交出清单、操作与订阅声明、作者的消息目录（handshake.md §3）；只在 Declared 接受。
     *
     * @throws GrpcException 握手已经完成或没有共同的协议版本（FAILED_PRECONDITION）
     */
    private function handshake(ServerCall $call): void
    {
        $request = new V1\HandshakeRequest();
        $this->read($call, $request);
        if (!$this->lifecycle->is(LifecycleState::Declared)) {
            throw self::outOfOrder('Handshake is accepted only once, before anything else.');
        }
        $offered = iterator_to_array($request->getProtocolVersions(), false);
        $selected = Versions::negotiate($offered);
        if ($selected === null) {
            throw new GrpcException(GrpcStatus::FAILED_PRECONDITION, sprintf(
                'No common protocol version: the host supports [%s], the plugin supports [%s].',
                implode(', ', array_map(strval(...), array_filter($offered, is_int(...)))),
                implode(', ', Versions::SUPPORTED),
            ));
        }
        $response = (new V1\HandshakeResponse())
            ->setProtocolVersion($selected)
            ->setPrimitives($this->manifest->primitives)
            ->setManifest($this->manifestText)
            ->setSdk((new V1\SdkInfo())
                ->setName(FrameworkInfo::SDK_NAME)
                ->setVersion(FrameworkInfo::SDK_VERSION)
                ->setLanguage(FrameworkInfo::SDK_LANGUAGE))
            ->setOperations($this->declarations())
            // 按声明的顺序写订阅，方式总是写出 competing 或 broadcast（handshake.md §3 第 5 条）
            ->setSubscriptions(array_map(
                static fn (Subscription $subscription): V1\Subscription => (new V1\Subscription())
                    ->setTopic($subscription->topic)
                    ->setMessageType($subscription->messageType)
                    ->setMode($subscription->effectiveMode()->value),
                $this->options->subscriptions,
            ))
            ->setCatalogs($this->catalogs());
        $this->lifecycle->transition(LifecycleState::Resolved);
        $this->logger->information('Handshake completed.', [
            'protocolVersion' => $selected,
            'host' => $request->getHost()?->getSdk() ?? '',
            'instance' => $request->getInstanceId(),
        ]);
        $call->send($response->serializeToString());
    }

    /**
     * 配置（lifecycle.md §7.2）：只在 Resolved 接受；配置节不是 JSON 对象时不调配置回调，失败即 Failed。
     *
     * @throws GrpcException 次序不对（FAILED_PRECONDITION）
     */
    private function configure(ServerCall $call): void
    {
        $request = new V1\ConfigureRequest();
        $this->read($call, $request);
        if (!$this->lifecycle->is(LifecycleState::Resolved)) {
            throw self::outOfOrder('Configure is accepted only once, after the handshake.');
        }
        $section = $this->section($request->getConfiguration());
        if ($section === null) {
            $outcome = Result::fail(
                PluginErrors::failed($this->manifest->id, 'configure', PluginErrors::REASON_NOT_AN_OBJECT),
            );
        } else {
            $permissions = iterator_to_array($request->getGrantedPermissions(), false);
            // 绑定连同属性原样交给作者（lifecycle.md §7.2）：属性名不折叠大小写、不核对文法与上限，没有属性时是空表；
            // 主值与属性值可能是凭据，运行时不把它们写进日志与错误
            $bindings = [];
            foreach ($request->getBindings() as $binding) {
                $bindings[] = new ResolvedBinding(
                    $binding->getName(),
                    $binding->getType(),
                    $binding->getValue(),
                    iterator_to_array($binding->getProperties()),
                );
            }
            // 回调前的权限核对以 granted_permissions 为准（invocation.md §5）
            $this->host->grant(array_values(array_filter($permissions, is_string(...))));
            $configuration = new Configuration($section, $permissions, $bindings, $request->getWorkDirectory());
            $hook = $this->options->hooks->configure;
            $outcome = $hook === null
                ? Result::success()
                : $this->runHook('configure', static fn (): mixed => $hook($configuration));
        }
        $this->settle($outcome, LifecycleState::Configured, 'configure');
        $call->send((new V1\ConfigureResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 启动与就绪（lifecycle.md §7.3）：只在 Configured 接受；启动回调成功后进入 Started，有健康回调就立即求一次并按结果
     * 进入 Ready、Degraded 或 Failed，没有就进入 Ready，然后才应答。
     *
     * @throws GrpcException 次序不对（FAILED_PRECONDITION）
     */
    private function start(ServerCall $call): void
    {
        $this->read($call, new V1\StartRequest());
        if (!$this->lifecycle->is(LifecycleState::Configured)) {
            throw self::outOfOrder('Start is accepted only once, after a successful Configure.');
        }
        $hook = $this->options->hooks->start;
        $outcome = $hook === null ? Result::success() : $this->runHook('start', static fn (): mixed => $hook());
        $this->settle($outcome, LifecycleState::Started, 'start');
        if ($outcome->isOk()) {
            $this->started = true;
            if ($this->options->hooks->health === null) {
                $this->lifecycle->transition(LifecycleState::Ready);
            } else {
                $this->probe($this->options->hooks->health, true);
            }
            $this->logger->information('Started.', [
                'operations' => count($this->invoker->registry()),
                'subscriptions' => count($this->options->subscriptions),
                'state' => $this->lifecycle->state(),
            ]);
        }
        $call->send((new V1\StartResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 停止（lifecycle.md §7.5）：Declared、Draining、Stopped 之外都接受。启动回调没成功过的不调停止回调、直接应答；
     * 其余等在途调用到宽限期（到了触发它们的取消信号、不再等），再调停止回调、应答，然后退出进程。
     *
     * @throws GrpcException 次序不对（FAILED_PRECONDITION）
     */
    private function stop(ServerCall $call): void
    {
        $request = new V1\StopRequest();
        $this->read($call, $request);
        if ($this->lifecycle->is(LifecycleState::Declared, LifecycleState::Draining, LifecycleState::Stopped)) {
            throw self::outOfOrder('Stop is not accepted before the handshake or while stopping.');
        }
        $grace = $request->getGraceSeconds() > 0 ? (float) $request->getGraceSeconds() : self::DEFAULT_GRACE;
        $begun = hrtime(true);
        $this->logger->information('Stopping.', ['reason' => $request->getReason(), 'grace' => $grace]);
        $outcome = Result::success();
        if ($this->started) {
            // 启动成功过之后进入 Failed 的不迁移状态，在途调用与停止回调照常处理
            if ($this->lifecycle->is(LifecycleState::Started, ...self::SERVING)) {
                $this->beginDraining();
            }
            if (!$this->awaitIdle($grace)) {
                // 宽限到了：只经令牌通知仍在进行的调用（RFC-0003），不强行中止，也不再等
                $this->cancelInFlight();
            }
            $remaining = max(0.0, $grace - (hrtime(true) - $begun) / 1e9);
            $outcome = $this->runStopHook($request->getReason(), $remaining, new NullCancellation());
            $error = $outcome->error();
            if ($error !== null) {
                $this->logger->error('The stop hook failed.', ['code' => $error->code, 'error' => $error->message()]);
            }
        }
        $call->send((new V1\StopResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
        $this->finish();
        $remaining = max(0.0, $grace - (hrtime(true) - $begun) / 1e9);
        $shutdown = $this->shutdown;
        // 应答写出、本次调用结束之后才停止服务端：服务端停止时等的在途流包括这一次
        EventLoop::defer(static function () use ($shutdown, $remaining): void {
            $shutdown($remaining);
        });
    }

    /**
     * 健康探测（lifecycle.md §7.4）：任何状态都接受；可服务期间每次都求一次健康回调。
     */
    private function health(ServerCall $call): void
    {
        $this->read($call, new V1\HealthRequest());
        $call->send($this->evaluate()->serializeToString());
    }

    /**
     * 健康推送（lifecycle.md §7.4）：先推当前状态，之后状态、健康项或原因变化时推送；可服务期间每 10 秒重新求一次健康回调；
     * 进入 Draining 时推一次，应答 Stop 后结束流。
     */
    private function watchHealth(ServerCall $call): void
    {
        $this->read($call, new V1\HealthRequest());
        $last = $this->evaluate()->serializeToString();
        $call->send($last);
        $drainingSent = $last === $this->drainingHealth;
        $nextProbe = hrtime(true) + (int) (self::HEALTH_INTERVAL * 1e9);
        while (!$this->isFinished()) {
            $serving = $this->lifecycle->is(...self::SERVING);
            $parts = [$call->cancellation(), $this->finished->getCancellation()];
            if ($serving) {
                $parts[] = new TimeoutCancellation(max(0.0, ($nextProbe - hrtime(true)) / 1e9));
            }
            try {
                $this->lifecycle->changed()->await(new CompositeCancellation(...$parts));
            } catch (CancelledException) {
                // 到了重新求健康的时刻、宿主取消了订阅或流该结束了：下面分别处理
            }
            if ($call->cancellation()->isRequested()) {
                return;
            }
            if ($this->isFinished()) {
                break;
            }
            $probe = $serving && hrtime(true) >= $nextProbe;
            if ($probe) {
                $nextProbe = hrtime(true) + (int) (self::HEALTH_INTERVAL * 1e9);
            }
            $current = ($probe ? $this->evaluate() : $this->snapshot())->serializeToString();
            if ($current !== $last) {
                $call->send($current);
                $last = $current;
                $drainingSent = $drainingSent || $current === $this->drainingHealth;
            }
        }
        // 应答 Stop 与进入 Draining 之间可能没有挂起点，推送的 Fiber 来不及醒：结束之前补上那一次 Draining
        if ($this->drainingHealth !== null && !$drainingSent) {
            $call->send($this->drainingHealth);
        }
    }

    /**
     * 调用一个一元操作（invocation.md §1）：操作未声明 → UNIMPLEMENTED；不可服务 → PLUGINS_UNAVAILABLE_001；然后还原
     * 上下文，经调用器与完整管道执行，payload 为空等同 `{}`。
     *
     * @throws GrpcException 操作未声明（UNIMPLEMENTED）
     */
    private function invoke(ServerCall $call): void
    {
        $request = new V1\InvokeRequest();
        $this->read($call, $request);
        $id = $request->getOperation();
        if ($this->invoker->registry()->find($id) === null) {
            throw new GrpcException(
                GrpcStatus::UNIMPLEMENTED,
                sprintf("The operation '%s' is not provided by this plugin.", $id),
            );
        }
        if (!$this->lifecycle->is(...self::SERVING)) {
            $call->send((new V1\InvokeResponse())
                ->setStatus(Protocol::toStatus(Result::fail($this->unavailable())))
                ->serializeToString());

            return;
        }
        $run = function (Context $context) use ($id, $request): Response {
            try {
                return $this->invoker->invoke($context, new Request($id, $request->getPayload()));
            } catch (\Throwable $exception) {
                // 排在 Observe 内置步骤之前的中间件抛出：插件自己的失败，作为业务失败交回，不当作传输失败
                $this->logger->error('The operation threw an exception.', [
                    'operation' => $id,
                    'exception' => $exception,
                ]);

                return new Response(Result::fail(KernelErrors::unhandledException($exception)), null);
            }
        };
        $response = $this->within($call, $request->getMetadata(), $run);
        $call->send((new V1\InvokeResponse())
            ->setStatus(Protocol::toStatus($response->outcome))
            ->setPayload($response->payload ?? '')
            ->serializeToString());
    }

    /**
     * 流式调用（invocation.md §1）：流式操作等 RFC-0011 的流式契约，插件不实现。读第一帧：流在第一帧之前结束或第一帧不是
     * start 时以 INVALID_ARGUMENT 结束，是 start 时以 UNIMPLEMENTED 结束，不论操作是否声明、插件是否就绪。
     *
     * @throws GrpcException 总是
     */
    private function invokeStream(ServerCall $call): void
    {
        $bytes = $call->receive();
        $frame = new V1\StreamFrame();
        if ($bytes !== null) {
            try {
                $frame->mergeFromString($bytes);
            } catch (\Exception $exception) {
                throw new GrpcException(
                    GrpcStatus::INVALID_ARGUMENT,
                    'A stream frame cannot be decoded.',
                    [],
                    $exception,
                );
            }
        }
        if ($bytes === null || $frame->getFrame() !== 'start') {
            throw new GrpcException(GrpcStatus::INVALID_ARGUMENT, 'The first frame of a stream must be start.');
        }

        throw new GrpcException(
            GrpcStatus::UNIMPLEMENTED,
            'Streaming operations are not supported until RFC-0011 defines their contract.',
        );
    }

    /**
     * 投递一条消息（invocation.md §4）：没有信封、方式不认识或信封的 `time` 不合 RFC-0003 规则 8 → INVALID_ARGUMENT；按
     * （主题，方式，类型）找订阅，一个也没有 → plugins.event.unsubscribed；不可服务 → PLUGINS_UNAVAILABLE_001；然后还原上下文，
     * 匹配的订阅按声明顺序依次交给各自的处理器，第一个失败即终止并作为结果。
     *
     * @throws GrpcException 没有信封、方式不认识或 `time` 读不出（INVALID_ARGUMENT）
     */
    private function handleEvent(ServerCall $call): void
    {
        $request = new V1\HandleEventRequest();
        $this->read($call, $request);
        $wire = $request->getEnvelope();
        $mode = SubscriptionMode::fromWire($request->getMode());
        if ($wire === null) {
            throw new GrpcException(GrpcStatus::INVALID_ARGUMENT, 'A delivery must carry an envelope.');
        }
        if ($mode === null) {
            throw new GrpcException(
                GrpcStatus::INVALID_ARGUMENT,
                sprintf("The delivery mode '%s' must be empty, competing or broadcast.", $request->getMode()),
            );
        }
        $envelope = Protocol::envelope($wire);
        // 宿主写来的 time 一律合文法：读不出是宿主的问题，不交给作者，也不当作没有时间
        if ($envelope === null) {
            throw new GrpcException(
                GrpcStatus::INVALID_ARGUMENT,
                sprintf(
                    "The envelope time '%s' is not an RFC 3339 date-time as RFC-0003 rule 8 reads it.",
                    $wire->getTime(),
                ),
            );
        }
        $matches = array_values(array_filter(
            $this->options->subscriptions,
            static fn (Subscription $subscription): bool => $subscription->accepts($envelope, $mode),
        ));
        if ($matches === []) {
            $outcome = Result::fail(PluginErrors::unsubscribed(
                $this->manifest->id,
                $envelope->topic,
                $envelope->type,
                $request->getMode(),
            ));
        } elseif (!$this->lifecycle->is(...self::SERVING)) {
            $outcome = Result::fail($this->unavailable());
        } else {
            $attempt = $request->getAttempt();
            $run = function (Context $context) use ($matches, $envelope, $attempt): Result {
                foreach ($matches as $subscription) {
                    $outcome = $this->deliver($subscription, $context, $envelope, $attempt);
                    if (!$outcome->isOk()) {
                        return $outcome;
                    }
                }

                return Result::success();
            };
            $outcome = $this->within($call, $request->getMetadata(), $run);
        }
        $call->send((new V1\HandleEventResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 把消息交给一个订阅的处理器；处理器抛出为 KERNEL_UNEXPECTED_001 + kernel.invocation.unhandled_exception（RFC-0011 规则 5）。
     *
     * @return Result<Unit>
     */
    private function deliver(
        Subscription $subscription,
        Context $context,
        Envelope $envelope,
        int $attempt,
    ): Result {
        try {
            return ($subscription->handler)($context, $envelope, $attempt)->toUnit();
        } catch (\Throwable $exception) {
            $this->logger->error('The event handler threw an exception.', [
                'topic' => $envelope->topic,
                'exception' => $exception,
            ]);

            return Result::fail(KernelErrors::unhandledException($exception));
        }
    }

    /**
     * 在一次调用或投递的上下文里执行：上下文由元数据还原，宿主重置流即取消，截止时间到了令牌准时触发；调用期间登记租户，
     * 收尾时可以触发它的取消信号。
     *
     * @template T
     *
     * @param \Closure(Context): T $run
     *
     * @return T
     */
    private function within(ServerCall $call, ?V1\InvocationMetadata $metadata, \Closure $run): mixed
    {
        $source = new CancellationSource();
        $number = $this->nextCall++;
        $this->inFlight[$number] = $source;
        $subscription = $call->cancellation()->subscribe(static fn () => $source->cancel());
        $context = Protocol::context($metadata, $call->timeout(), $source->cancellation(), $this->clock);
        $context->items()->set(Host::ITEM_KEY, $this->host);
        $token = $context->cancellation();
        // 同步执行模型下截止令牌在轮询时才核对时钟：接入事件循环后在截止时刻主动核对，登记的回调准时执行
        $timer = $token instanceof DeadlineCancellation ? DeadlineTimer::start($token, $this->clock) : null;
        $tenant = $context->tenant();
        $this->tenants->enter($tenant);
        try {
            return $run($context);
        } finally {
            unset($this->inFlight[$number]);
            $this->tenants->leave($tenant);
            $timer?->cancel();
            $call->cancellation()->unsubscribe($subscription);
            if ($this->inFlight === [] && $this->idle !== null && !$this->idle->isComplete()) {
                $this->idle->complete();
            }
        }
    }

    /**
     * 进入 Draining：之后的调用与投递以 PLUGINS_UNAVAILABLE_001 失败；记下这一刻的健康应答，WatchHealth 一定推到它。
     */
    private function beginDraining(): void
    {
        if ($this->lifecycle->transition(LifecycleState::Draining)) {
            $this->drainingHealth = $this->snapshot()->serializeToString();
        }
    }

    /**
     * 等在途的调用与投递结束，至多 $timeout 秒；都结束了返回真。
     */
    private function awaitIdle(float $timeout): bool
    {
        if ($this->inFlight === []) {
            return true;
        }
        $this->idle ??= new DeferredFuture();
        try {
            $this->idle->getFuture()->await(new TimeoutCancellation(max(0.0, $timeout)));

            return true;
        } catch (CancelledException) {
            $this->logger->warning('In-flight calls did not finish in time; their cancellation was signaled.', [
                'inFlight' => count($this->inFlight),
            ]);

            return false;
        }
    }

    /**
     * 触发全部在途调用与投递的取消信号（RFC-0003：只经令牌通知，不强行中止）。
     */
    private function cancelInFlight(): void
    {
        foreach ($this->inFlight as $source) {
            $source->cancel();
        }
    }

    /**
     * 调停止回调，进程里至多一次：Stop 与收尾先后到达时，后到的等前一次的结果（至多到 $wait 取消）。
     *
     * @return Result<Unit>
     */
    private function runStopHook(string $reason, float $grace, AmpCancellation $wait): Result
    {
        if ($this->stopHook !== null) {
            try {
                return $this->stopHook->getFuture()->await($wait);
            } catch (CancelledException) {
                return Result::success();
            }
        }
        $this->stopHook = new DeferredFuture();
        $hook = $this->options->hooks->stop;
        $outcome = $hook === null
            ? Result::success()
            : $this->runHook('stop', static fn (): mixed => $hook($reason, $grace));
        $this->stopHook->complete($outcome);

        return $outcome;
    }

    /**
     * 可服务期间求一次健康回调，然后返回当前的健康应答。
     */
    private function evaluate(): V1\HealthResponse
    {
        $hook = $this->options->hooks->health;
        if ($hook !== null && $this->lifecycle->is(...self::SERVING)) {
            $this->probe($hook);
        }

        return $this->snapshot();
    }

    /**
     * 求一次健康回调并照办（lifecycle.md §7.3、§7.4）：只有 Ready、Degraded、Failed 改变状态（按合法迁移表，Failed 之后不再
     * 回来），报其他状态的不改变状态，Start 时则按 Ready；未声明的健康项丢掉；回调抛出时这一次是 Degraded，原因为
     * plugins.process.failed（`phase` 为 health），没有健康项，状态与回调报了 Degraded 一样迁到 Degraded。
     *
     * @param \Closure(): Health $hook
     * @param bool $starting 是否 Start 时求的那一次：Start 应答之后的状态只能是 Ready、Degraded 或 Failed
     */
    private function probe(\Closure $hook, bool $starting = false): void
    {
        $reported = $this->reportedHealth($hook);
        $items = $this->declaredItems($reported);
        $changed = $items != $this->items || $reported->error != $this->reported;
        $this->items = $items;
        $this->reported = $reported->error;
        if (in_array($reported->state, self::ADOPTED, true)) {
            $this->lifecycle->transition($reported->state, $reported->error);
        } elseif ($starting) {
            $this->lifecycle->transition(LifecycleState::Ready);
        }
        if ($changed) {
            $this->lifecycle->signal();
        }
    }

    /**
     * 当前的健康应答，不调健康回调：可服务期间带最近一次报的健康项与原因；其余状态照实报状态（Start 之前是本地状态），
     * 没有健康项，原因是进入该状态的原因。
     */
    private function snapshot(): V1\HealthResponse
    {
        $state = $this->lifecycle->state();
        if ($this->lifecycle->is(...self::SERVING)) {
            return Protocol::toProtoHealth($state, $this->items, $this->reported);
        }

        return Protocol::toProtoHealth($state, [], $this->lifecycle->error());
    }

    /**
     * 调用健康回调；抛出（返回的不是 Health 按抛出 TypeError）时为 Degraded，原因不带异常的消息（lifecycle.md §7.6）。
     *
     * @param \Closure(): mixed $hook 回调；运行期不一定返回 Health
     */
    private function reportedHealth(\Closure $hook): Health
    {
        try {
            $reported = $hook();
            if ($reported instanceof Health) {
                return $reported;
            }
            $type = 'TypeError';
            $this->logger->error('The health hook did not return a Health.', [
                'returned' => get_debug_type($reported),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('The health hook threw an exception.', ['exception' => $exception]);
            $type = self::typeName($exception);
        }

        return new Health(LifecycleState::Degraded, [], PluginErrors::failed($this->manifest->id, 'health', $type));
    }

    /**
     * 只保留清单 contributes.health 声明了的健康项；没声明的每个名字在进程里只告警一次。
     *
     * @return list<HealthItem>
     */
    private function declaredItems(Health $health): array
    {
        $declared = $this->manifest->health();
        $kept = [];
        foreach ($health->items as $item) {
            if (in_array($item->name, $declared, true)) {
                $kept[] = $item;
            } elseif (!isset($this->undeclaredItems[$item->name])) {
                $this->undeclaredItems[$item->name] = true;
                $this->logger->warning('A health item is not declared in contributes.health and is not reported.', [
                    'item' => $item->name,
                ]);
            }
        }

        return $kept;
    }

    /**
     * 握手的操作声明：只有一元操作（handshake.md §3 第 4 条），按 ID 的字节序。
     *
     * @return list<V1\OperationDeclaration>
     */
    private function declarations(): array
    {
        $declarations = [];
        foreach ($this->invoker->registry()->list() as $descriptor) {
            $declarations[] = self::declaration(
                $descriptor->contract,
                $descriptor->schema?->request,
                $descriptor->schema?->response,
            );
        }

        return $declarations;
    }

    private static function declaration(
        Contract $contract,
        ?string $requestSchema,
        ?string $responseSchema,
    ): V1\OperationDeclaration {
        return (new V1\OperationDeclaration())
            ->setId($contract->id)
            ->setStability($contract->stability->value)
            ->setExpose(array_map(static fn (Expose $expose): string => $expose->value, $contract->expose))
            ->setPermission($contract->permission ?? '')
            ->setPolicy($contract->policy ?? '')
            ->setRequestSchema($requestSchema ?? '')
            ->setResponseSchema($responseSchema ?? '')
            ->setStreaming(false);
    }

    /**
     * 握手的消息目录：只有作者自己的目录，区域按名字排序；运行时自己的消息键由宿主提供，不随握手发送
     * （handshake.md §3 第 6 条）。
     *
     * @return list<V1\MessageCatalog>
     */
    private function catalogs(): array
    {
        $catalogs = [];
        foreach ($this->options->catalogs as $catalog) {
            $locales = [];
            foreach ($catalog->locales as $locale => $templates) {
                $locales[] = (new V1\LocaleTemplates())->setLocale($locale)->setTemplates($templates);
            }
            $catalogs[] = (new V1\MessageCatalog())->setName($catalog->name)->setLocales($locales);
        }

        return $catalogs;
    }

    /**
     * 读出配置节（lifecycle.md §7.2）：空等同 `{}`；通不过共同 JSON 读取检查或不是对象时为 null。
     */
    private function section(string $text): ?JsonObject
    {
        if ($text === '') {
            return JsonObject::of();
        }
        try {
            $section = Wire::decode($text, 'configuration');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $section instanceof JsonObject ? $section : null;
    }

    /**
     * 按一步的结果迁移：成功到 $success，失败到 Failed 并记日志。
     *
     * @param Result<Unit> $outcome
     */
    private function settle(Result $outcome, LifecycleState $success, string $phase): void
    {
        $error = $outcome->error();
        if ($error === null) {
            $this->lifecycle->transition($success);

            return;
        }
        $this->lifecycle->transition(LifecycleState::Failed, $error);
        $this->logger->error(sprintf('The %s step failed.', $phase), [
            'code' => $error->code,
            'error' => $error->message(),
        ]);
    }

    /**
     * 执行生命周期回调：抛出的异常连同消息记进日志，交给宿主的是 PLUGINS_UNEXPECTED_001，`reason` 只是异常的简单类名
     * （lifecycle.md §7.6）；返回的不是 Result 按抛出 TypeError 处理。
     *
     * @param \Closure(): mixed $hook
     *
     * @return Result<Unit>
     */
    private function runHook(string $phase, \Closure $hook): Result
    {
        try {
            $result = $hook();
            if ($result instanceof Result) {
                return $result->toUnit();
            }
            $type = 'TypeError';
            $this->logger->error(sprintf('The %s hook did not return a Result.', $phase), [
                'returned' => get_debug_type($result),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('The %s hook threw an exception.', $phase), ['exception' => $exception]);
            $type = self::typeName($exception);
        }

        return Result::fail(PluginErrors::failed($this->manifest->id, $phase, $type));
    }

    /**
     * 返回异常的简单类名（lifecycle.md §7.6）：不带命名空间；匿名类去掉 PHP 附在名字后面的文件路径。
     */
    private static function typeName(\Throwable $exception): string
    {
        $name = $exception::class;
        // 匿名类的名字是「父类@anonymous」后接 NUL、文件路径与序号：路径不能出现在交给宿主的错误里
        $nul = strpos($name, "\0");
        if ($nul !== false) {
            $name = substr($name, 0, $nul);
        }
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    /**
     * 读一条请求消息并解码；没有消息或解不开时以 INTERNAL 结束。
     *
     * @throws GrpcException
     */
    private function read(ServerCall $call, Message $message): void
    {
        $bytes = $call->receive();
        if ($bytes === null) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The call carries no request message.');
        }
        try {
            $message->mergeFromString($bytes);
        } catch (\Exception $exception) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The request message cannot be decoded.', [], $exception);
        }
    }

    /**
     * 不在可服务状态时的失败：`state` 是此刻的状态名（lifecycle.md §7.3）。
     */
    private function unavailable(): Error
    {
        return PluginErrors::unavailable($this->manifest->id, $this->lifecycle->state());
    }

    /**
     * 不在次序表里的调用（lifecycle.md §7.1）：什么都不做、状态不变。
     */
    private static function outOfOrder(string $message): GrpcException
    {
        return new GrpcException(GrpcStatus::FAILED_PRECONDITION, $message);
    }
}
