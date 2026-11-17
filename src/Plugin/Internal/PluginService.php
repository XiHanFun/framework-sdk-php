<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Amp\Cancellation as AmpCancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
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
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Operation\Response;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Logger;
use XiHan\Framework\Plugin\OperationStream;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\ResolvedBinding;
use XiHan\Framework\Plugin\StreamingOperation;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Plugin\SubscriptionMode;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\FrameworkInfo;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * xihan.plugin.v1.Plugin 服务的实现：握手、配置、启停、健康、调用与事件投递（handshake.md、lifecycle.md、invocation.md）。
 *
 * 会话令牌由服务端的守卫在进入这里之前核对。业务失败写进 Status.error，gRPC 状态只用于协议层的失败：没有共同的协议
 * 版本、顺序不对的生命周期调用（FAILED_PRECONDITION）、未声明的操作（UNIMPLEMENTED）、帧序不对（INVALID_ARGUMENT）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class PluginService
{
    /** 方法路径的前缀 */
    public const string PREFIX = '/xihan.plugin.v1.Plugin/';

    /** WatchHealth 在两次状态变化之间核对健康回调的间隔（秒）：回调报的变化不经状态机通知 */
    private const float HEALTH_POLL = 1.0;

    /** @var array<string, StreamingOperation> */
    private readonly array $streams;

    /** @var array<string, true> 已经告警过的未声明的健康项 */
    private array $undeclaredItems = [];

    /** 在途的调用与投递数 */
    private int $inFlight = 0;

    /** 停止回调是否已经执行过 */
    private bool $stopHookRan = false;

    /** @var DeferredFuture<null>|null 停止时等在途调用结束 */
    private ?DeferredFuture $idle = null;

    private readonly DeferredCancellation $draining;

    /**
     * @param \Closure(float): void $shutdown 在 Stop 应答之后优雅停止服务端并让进程退出（宽限秒数）
     */
    public function __construct(
        private readonly Options $options,
        private readonly Manifest $manifest,
        private readonly Invoker $invoker,
        private readonly Lifecycle $lifecycle,
        private readonly Logger $logger,
        private readonly TenantScope $tenants,
        private readonly ?Host $host,
        private readonly Clock $clock,
        private readonly \Closure $shutdown,
    ) {
        $streams = [];
        foreach ($options->streams as $operation) {
            $streams[$operation->contract->id] = $operation;
        }
        $this->streams = $streams;
        $this->draining = new DeferredCancellation();
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
     * 进入停止：之后的调用与投递以 PLUGINS_UNAVAILABLE_001 失败，健康推送发完最后一条后结束。
     */
    public function beginDraining(): void
    {
        $this->lifecycle->transition(LifecycleState::Draining);
        if (!$this->draining->isCancelled()) {
            $this->draining->cancel();
        }
    }

    /**
     * 等在途的调用与投递结束，至多 $grace 秒。
     */
    public function awaitIdle(float $grace): void
    {
        if ($this->inFlight === 0) {
            return;
        }
        $this->idle ??= new DeferredFuture();
        try {
            $this->idle->getFuture()->await(new TimeoutCancellation(max(0.0, $grace)));
        } catch (CancelledException) {
            $this->logger->warning('In-flight calls did not finish within the grace period.', [
                'inFlight' => $this->inFlight,
            ]);
        }
    }

    /**
     * 执行停止回调，至多一次：Stop 与 stdin 关闭先后到达时，后到的直接返回成功。
     *
     * @return Result<Unit>
     */
    public function runStopHook(string $reason, float $grace): Result
    {
        $hook = $this->options->hooks->stop;
        if ($hook === null || $this->stopHookRan) {
            return Result::success();
        }
        $this->stopHookRan = true;

        return $this->runHook('stop', static fn (): mixed => $hook($reason, $grace));
    }

    /**
     * 握手：协商版本、报告原语层版本，交出清单、操作与订阅声明、消息目录（handshake.md §3）。
     *
     * @throws GrpcException 没有共同的协议版本，或握手已经完成（FAILED_PRECONDITION）
     */
    private function handshake(ServerCall $call): void
    {
        $request = new V1\HandshakeRequest();
        $this->read($call, $request);
        if (!$this->lifecycle->is(LifecycleState::Declared, LifecycleState::Failed)) {
            throw new GrpcException(GrpcStatus::FAILED_PRECONDITION, 'The plugin has already completed its handshake.');
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
            ->setManifest($this->options->manifest)
            ->setSdk((new V1\SdkInfo())
                ->setName(FrameworkInfo::SDK_NAME)
                ->setVersion(FrameworkInfo::SDK_VERSION)
                ->setLanguage('php'))
            ->setOperations($this->declarations())
            ->setSubscriptions(array_map(
                static fn (Subscription $subscription): V1\Subscription => (new V1\Subscription())
                    ->setTopic($subscription->topic)
                    ->setMessageType($subscription->messageType)
                    ->setMode($subscription->mode->value ?? ''),
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
     * 配置：下发配置节、授予的权限、绑定与工作目录（lifecycle.md §2）；失败即 Failed。
     *
     * @throws GrpcException 还没有握手（FAILED_PRECONDITION）
     */
    private function configure(ServerCall $call): void
    {
        $request = new V1\ConfigureRequest();
        $this->read($call, $request);
        if (!$this->lifecycle->is(LifecycleState::Resolved)) {
            throw new GrpcException(GrpcStatus::FAILED_PRECONDITION, 'Configure requires a completed handshake.');
        }
        $permissions = iterator_to_array($request->getGrantedPermissions(), false);
        $bindings = [];
        foreach ($request->getBindings() as $binding) {
            $bindings[] = new ResolvedBinding($binding->getName(), $binding->getType(), $binding->getValue());
        }
        $this->host?->grant($permissions);
        $section = $this->section($request->getConfiguration());
        if ($section instanceof Error) {
            $outcome = Result::fail($section);
        } else {
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
     * 启动：成功后插件接受调用；没有健康回调时直接报 Ready（lifecycle.md §1）。
     *
     * @throws GrpcException 还没有配置（FAILED_PRECONDITION）
     */
    private function start(ServerCall $call): void
    {
        $this->read($call, new V1\StartRequest());
        if (!$this->lifecycle->is(LifecycleState::Configured)) {
            throw new GrpcException(GrpcStatus::FAILED_PRECONDITION, 'Start requires a successful Configure.');
        }
        $hook = $this->options->hooks->start;
        $outcome = $hook === null ? Result::success() : $this->runHook('start', static fn (): mixed => $hook());
        $this->settle($outcome, LifecycleState::Started, 'start');
        if ($outcome->isOk()) {
            if ($this->options->hooks->health === null) {
                $this->lifecycle->transition(LifecycleState::Ready);
            } else {
                // 有健康回调时由它决定是否 Ready：Started 之后立刻核对一次
                $this->currentHealth();
            }
            $this->logger->information('Started.', [
                'operations' => count($this->invoker->registry()) + count($this->streams),
                'subscriptions' => count($this->options->subscriptions),
                'state' => $this->lifecycle->state(),
            ]);
        }
        $call->send((new V1\StartResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 停止：进入 Draining、拒绝新调用、等在途调用（宽限内）、执行停止回调、应答，然后退出进程（lifecycle.md §5）。
     */
    private function stop(ServerCall $call): void
    {
        $request = new V1\StopRequest();
        $this->read($call, $request);
        $grace = $request->getGraceSeconds() > 0 ? (float) $request->getGraceSeconds() : $this->options->stopGrace;
        $started = $this->clock->now();
        $this->logger->information('Stopping.', ['reason' => $request->getReason(), 'grace' => $grace]);
        $this->beginDraining();
        $this->awaitIdle($grace);
        $outcome = $this->runStopHook($request->getReason(), $grace);
        $error = $outcome->error();
        if ($error !== null) {
            $this->logger->error('The stop hook failed.', ['code' => $error->code, 'message' => $error->message()]);
        }
        $call->send((new V1\StopResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
        $remaining = max(0.0, $grace - ((float) $this->clock->now()->format('U.u') - (float) $started->format('U.u')));
        $shutdown = $this->shutdown;
        // 应答写出、本次调用结束之后才停止服务端：优雅停止要等在途的流，包括这一次
        EventLoop::defer(static function () use ($shutdown, $remaining): void {
            $shutdown($remaining);
        });
    }

    /**
     * 健康探测。
     */
    private function health(ServerCall $call): void
    {
        $this->read($call, new V1\HealthRequest());
        $call->send($this->currentHealth()->serializeToString());
    }

    /**
     * 健康推送：先发当前状态，之后状态或健康项变化时各发一条；停止时发完最后一条后结束（lifecycle.md §4）。
     */
    private function watchHealth(ServerCall $call): void
    {
        $this->read($call, new V1\HealthRequest());
        $last = $this->currentHealth()->serializeToString();
        $call->send($last);
        while (!$this->draining->isCancelled()) {
            try {
                $this->lifecycle->changed()->await(new CompositeCancellation(
                    new TimeoutCancellation(self::HEALTH_POLL),
                    $call->cancellation(),
                    $this->draining->getCancellation(),
                ));
            } catch (CancelledException) {
                // 到了核对间隔、宿主取消了订阅或开始停止：都重新算一次
            }
            if ($call->cancellation()->isRequested()) {
                return;
            }
            $current = $this->currentHealth()->serializeToString();
            if ($current !== $last) {
                $call->send($current);
                $last = $current;
            }
        }
    }

    /**
     * 调用一个一元操作：未声明的操作以 UNIMPLEMENTED 结束，未就绪返回 PLUGINS_UNAVAILABLE_001，其余经调用器与完整管道执行。
     *
     * @throws GrpcException 操作未声明（UNIMPLEMENTED）
     */
    private function invoke(ServerCall $call): void
    {
        $request = new V1\InvokeRequest();
        $this->read($call, $request);
        $id = $request->getOperation();
        if ($this->invoker->registry()->find($id) === null) {
            throw new GrpcException(GrpcStatus::UNIMPLEMENTED, isset($this->streams[$id])
                ? sprintf("The operation '%s' is a streaming operation; call it with InvokeStream.", $id)
                : sprintf("The operation '%s' is not provided by this plugin.", $id));
        }
        if (!$this->serving()) {
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

                return new Response(Result::fail(OperationErrors::unhandledException($exception)), null);
            }
        };
        $response = $this->within($call, $request->getMetadata(), $run);
        $call->send((new V1\InvokeResponse())
            ->setStatus(Protocol::toStatus($response->outcome))
            ->setPayload($response->payload ?? '')
            ->serializeToString());
    }

    /**
     * 调用一个流式操作（invocation.md §1）：第一帧是 start，之后转交 chunk，调用方以 end 结束；本端发 chunk，最后发 end。
     *
     * @throws GrpcException 第一帧不是 start、start 出现在后面或流在 end 之前结束（INVALID_ARGUMENT），操作未声明（UNIMPLEMENTED）
     */
    private function invokeStream(ServerCall $call): void
    {
        $first = $this->frame($call);
        if ($first === null || $first->getFrame() !== 'start') {
            throw new GrpcException(GrpcStatus::INVALID_ARGUMENT, 'The first frame of a stream must be start.');
        }
        $request = $first->getStart() ?? new V1\InvokeRequest();
        $id = $request->getOperation();
        $operation = $this->streams[$id] ?? null;
        if ($operation === null) {
            throw new GrpcException(
                GrpcStatus::UNIMPLEMENTED,
                sprintf("The streaming operation '%s' is not provided by this plugin.", $id),
            );
        }
        if (!$this->serving()) {
            $end = (new V1\StreamFrame())->setEnd(Protocol::toStatus(Result::fail($this->unavailable())));
            $call->send($end->serializeToString());

            return;
        }
        $violation = null;
        $receive = function () use ($call, &$violation): string|Error|null {
            try {
                $frame = $this->frame($call);
            } catch (GrpcException $exception) {
                $violation = $exception;

                return null;
            }
            if ($frame !== null && $frame->getFrame() === 'chunk') {
                return $frame->getChunk();
            }
            if ($frame !== null && $frame->getFrame() === 'end') {
                $status = $frame->getEnd();

                // 调用方以 ok 结束是正常结束，否则是中止，原因交给处理器
                return $status !== null && $status->getOk()
                    ? null
                    : Protocol::fromStatus($status, $this->manifest->id)->error();
            }
            // 流在 end 之前结束、start 出现在后面或帧是空的：帧序不对
            $violation = new GrpcException(GrpcStatus::INVALID_ARGUMENT, $frame === null
                ? 'The stream ended before an end frame.'
                : 'A start frame may only come first.');

            return null;
        };
        $send = static function (string $json) use ($call): void {
            $call->send((new V1\StreamFrame())->setChunk($json)->serializeToString());
        };
        $handler = $operation->handler;
        $run = function (Context $context) use ($handler, $request, $receive, $send, $id): Result {
            try {
                return $handler($context, new OperationStream($request->getPayload(), $receive, $send))->toUnit();
            } catch (GrpcException $exception) {
                // 写 chunk 时流已断开：没有人收得到结果
                throw $exception;
            } catch (\Throwable $exception) {
                $this->logger->error('The streaming operation threw an exception.', [
                    'operation' => $id,
                    'exception' => $exception,
                ]);

                return Result::fail(OperationErrors::unhandledException($exception));
            }
        };
        $outcome = $this->within($call, $request->getMetadata(), $run);
        if ($violation instanceof GrpcException) {
            throw $violation;
        }
        $call->send((new V1\StreamFrame())->setEnd(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 投递一条消息：按（主题，方式）找订阅，再看类型（invocation.md §4）；找不到时返回错误，不改交给另一种方式的处理器。
     */
    private function handleEvent(ServerCall $call): void
    {
        $request = new V1\HandleEventRequest();
        $this->read($call, $request);
        if (!$this->serving()) {
            $call->send((new V1\HandleEventResponse())
                ->setStatus(Protocol::toStatus(Result::fail($this->unavailable())))
                ->serializeToString());

            return;
        }
        $envelope = Protocol::envelope($request->getEnvelope());
        $mode = SubscriptionMode::fromWire($request->getMode());
        $subscription = null;
        foreach ($this->options->subscriptions as $candidate) {
            if ($mode !== null && $candidate->accepts($envelope, $mode)) {
                $subscription = $candidate;
                break;
            }
        }
        if ($subscription === null) {
            $error = PluginErrors::noSubscription($envelope->topic, $envelope->type, $request->getMode());
            $outcome = Result::fail($error);
        } else {
            $handler = $subscription->handler;
            $attempt = $request->getAttempt();
            $run = function (Context $context) use ($handler, $envelope, $attempt): Result {
                try {
                    return $handler($context, $envelope, $attempt)->toUnit();
                } catch (\Throwable $exception) {
                    $this->logger->error('The event handler threw an exception.', [
                        'topic' => $envelope->topic,
                        'exception' => $exception,
                    ]);

                    return Result::fail(OperationErrors::unhandledException($exception));
                }
            };
            $outcome = $this->within($call, $request->getMetadata(), $run);
        }
        $call->send((new V1\HandleEventResponse())->setStatus(Protocol::toStatus($outcome))->serializeToString());
    }

    /**
     * 在一次调用的上下文里执行：上下文由元数据还原，宿主重置流即取消，截止时间到了令牌准时触发；调用期间登记租户。
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
        $subscription = $call->cancellation()->subscribe(static fn () => $source->cancel());
        $context = Protocol::context($metadata, $call->timeout(), $source->cancellation(), $this->clock);
        if ($this->host !== null) {
            $context->items()->set(Host::ITEM_KEY, $this->host);
        }
        $timer = null;
        $token = $context->cancellation();
        $remaining = $context->remainingTime($this->clock->now());
        if ($token instanceof DeadlineCancellation && $remaining !== null) {
            // 同步执行模型下截止令牌在轮询时才核对时钟：接入事件循环后在截止时刻主动核对，登记的回调准时执行
            $timer = EventLoop::delay($remaining, static function () use ($token): void {
                $token->poll();
            });
        }
        $tenant = $context->tenant();
        $this->tenants->enter($tenant);
        $this->inFlight++;
        try {
            return $run($context);
        } finally {
            $this->inFlight--;
            $this->tenants->leave($tenant);
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }
            $call->cancellation()->unsubscribe($subscription);
            if ($this->inFlight === 0 && $this->idle !== null && !$this->idle->isComplete()) {
                $this->idle->complete();
            }
        }
    }

    /**
     * 计算当前健康（lifecycle.md §1、§4）：运行中健康回调报的 Ready、Degraded、Failed 迁移状态，停止与失败之后以运行时的
     * 状态为准；健康项只保留清单声明了的名字。
     */
    private function currentHealth(): V1\HealthResponse
    {
        $state = $this->lifecycle->state();
        $hook = $this->options->hooks->health;
        $items = [];
        $error = null;
        $configured = !$this->lifecycle->is(
            LifecycleState::Declared,
            LifecycleState::Resolved,
            LifecycleState::Configured,
        );
        if ($hook !== null && $configured) {
            $reported = $this->reportedHealth($hook);
            $items = $this->declaredItems($reported);
            $error = $reported->error;
            $adopted = [LifecycleState::Ready, LifecycleState::Degraded, LifecycleState::Failed];
            if ($this->serving() && in_array($reported->state, $adopted, true)) {
                $this->lifecycle->transition($reported->state, $reported->error);
            }
            $state = $this->lifecycle->state();
        }
        return Protocol::toProtoHealth($state, $items, $error ?? $this->lifecycle->error());
    }

    /**
     * 调用健康回调；回调抛出或返回的不是 Health 时按 Degraded 上报，原因是 PLUGINS_UNEXPECTED_001。
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
            $reason = 'The health hook did not return a Health.';
        } catch (\Throwable $exception) {
            $this->logger->error('The health hook threw an exception.', ['exception' => $exception]);
            $reason = sprintf('The health hook threw %s.', $exception::class);
        }

        return new Health(LifecycleState::Degraded, [], PluginErrors::failed($this->manifest->id, 'health', $reason));
    }

    /**
     * 只保留清单 contributes.health 声明了的健康项；没声明的每个名字告警一次。
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
     * 握手的操作声明：一元操作在前（按 ID 的字节序），流式操作在后。
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
                false,
            );
        }
        foreach ($this->streams as $operation) {
            $declarations[] = self::declaration(
                $operation->contract,
                $operation->schema?->request,
                $operation->schema?->response,
                true,
            );
        }

        return $declarations;
    }

    private static function declaration(
        Contract $contract,
        ?string $requestSchema,
        ?string $responseSchema,
        bool $streaming,
    ): V1\OperationDeclaration {
        return (new V1\OperationDeclaration())
            ->setId($contract->id)
            ->setStability($contract->stability->value)
            ->setExpose(array_map(static fn (Expose $expose): string => $expose->value, $contract->expose))
            ->setPermission($contract->permission ?? '')
            ->setPolicy($contract->policy ?? '')
            ->setRequestSchema($requestSchema ?? '')
            ->setResponseSchema($responseSchema ?? '')
            ->setStreaming($streaming);
    }

    /**
     * 握手的消息目录：区域按名字排序。
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
     * 读出配置节：空即 `{}`，必须是 JSON 对象。
     */
    private function section(string $text): JsonObject|Error
    {
        if ($text === '') {
            return JsonObject::of();
        }
        try {
            $section = Wire::decode($text, 'configuration');
        } catch (InvalidArgumentException) {
            $section = null;
        }

        return $section instanceof JsonObject
            ? $section
            : PluginErrors::failed($this->manifest->id, 'configure', 'The configuration section is not a JSON object.');
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
            'message' => $error->message(),
        ]);
    }

    /**
     * 执行生命周期回调：抛出的异常记进日志，交给宿主的是 PLUGINS_UNEXPECTED_001，不带异常的消息。
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
            $reason = sprintf('The %s hook did not return a Result.', $phase);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('The %s hook threw an exception.', $phase), ['exception' => $exception]);
            $reason = sprintf('The %s hook threw %s.', $phase, $exception::class);
        }

        return Result::fail(PluginErrors::failed($this->manifest->id, $phase, $reason));
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
     * 读流式调用的下一帧；流结束时为 null。
     *
     * @throws GrpcException 流已断开或帧解不开
     */
    private function frame(ServerCall $call): ?V1\StreamFrame
    {
        $bytes = $call->receive();
        if ($bytes === null) {
            return null;
        }
        $frame = new V1\StreamFrame();
        try {
            $frame->mergeFromString($bytes);
        } catch (\Exception $exception) {
            throw new GrpcException(GrpcStatus::INVALID_ARGUMENT, 'A stream frame cannot be decoded.', [], $exception);
        }

        return $frame;
    }

    /**
     * 判断是否接受调用：Start 之后、停止之前。
     */
    private function serving(): bool
    {
        return $this->lifecycle->is(LifecycleState::Started, LifecycleState::Ready, LifecycleState::Degraded);
    }

    /**
     * 未就绪或正在停止的失败。
     */
    private function unavailable(): Error
    {
        return PluginErrors::unavailable($this->manifest->id, $this->lifecycle->state());
    }

    /**
     * 返回停止的取消：Stop 或 stdin 关闭时触发。
     */
    public function drainingCancellation(): AmpCancellation
    {
        return $this->draining->getCancellation();
    }
}
