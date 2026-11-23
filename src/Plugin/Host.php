<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use Amp\DeferredCancellation;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Response;
use XiHan\Framework\Plugin\Internal\CallbackFailures;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcClient;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Internal\TenantScope;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\FrameworkInfo;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 宿主回调服务的客户端（invocation.md §5）：以插件身份经宿主的完整管道调用操作、经宿主的消息代理发布消息。
 *
 * 回调端点是 XIHAN_PLUGIN_HOST_ADDRESS 里第一个本 SDK 能连的项（handshake.md §4），启动时选定、之后不换；没有可用的端点时
 * 每次回调都以 PLUGINS_UNAVAILABLE_001 + plugins.host.unavailable 失败、不发请求。发出之前按宿主的顺序在本地核对：所需权限
 * 既在清单 permissions 里、也在 Configure 授予的权限里，回调上下文的租户是插件此刻正在处理的某个调用或投递的租户；不合的
 * 不发给宿主，结果与宿主拒绝时相同（PLUGINS_DENIED_002）。gRPC 状态按 invocation.md §5 的表转成错误。运行时在每次调用与
 * 投递的上下文里放入它，取用 Host::of($context)。
 */
final class Host
{
    /** Host.Invoke 需要的权限。 */
    public const string PERMISSION_INVOKE = 'capability:kernel.pipeline';

    /** Host.Publish 需要的权限。 */
    public const string PERMISSION_PUBLISH = 'capability:messaging.queue';

    /** 上下文对象袋里的键。 */
    public const string ITEM_KEY = self::class;

    private const string INVOKE_PATH = '/xihan.plugin.v1.Host/Invoke';

    private const string PUBLISH_PATH = '/xihan.plugin.v1.Host/Publish';

    private readonly ?GrpcClient $client;

    /** @var list<string> 宿主在 Configure 里授予的权限；Configure 之前什么都没有授予 */
    private array $granted = [];

    /**
     * 由运行时创建。
     *
     * @param Endpoint|null $endpoint 选定的回调端点；没有可用的端点时为 null
     * @param string $session 会话令牌（handshake.md §4）；标为敏感参数，不出现在异常的堆栈里
     * @param list<string> $declared 清单 permissions
     *
     * @internal
     */
    public function __construct(
        private readonly ?Endpoint $endpoint,
        #[\SensitiveParameter]
        string $session,
        private readonly string $pluginId,
        private readonly array $declared,
        private readonly TenantScope $tenants,
    ) {
        $uri = $endpoint?->socketUri();
        $this->client = $uri === null ? null : new GrpcClient(
            $uri,
            'localhost',
            [Runtime::SESSION_METADATA => $session],
            FrameworkInfo::SDK_NAME . '/' . FrameworkInfo::SDK_VERSION,
        );
    }

    /**
     * 取运行时放在上下文里的宿主客户端；上下文不是运行时交给处理器的时为 null。
     */
    public static function of(Context $context): ?self
    {
        $host = $context->items()->get(self::ITEM_KEY);

        return $host instanceof self ? $host : null;
    }

    /**
     * 返回选定的回调端点；没有可用的端点时为 null。
     */
    public function endpoint(): ?Endpoint
    {
        return $this->endpoint;
    }

    /**
     * 以插件身份经宿主的完整管道调用一个操作（需要权限 capability:kernel.pipeline）。
     *
     * 上下文的截止时间（gRPC 截止时间与它相同）、追踪与已登记的 Baggage 随调用传给宿主，调用方的取消即取消回调。
     *
     * @param string $payload 请求的 JSON；空等价于 `{}`
     */
    public function invoke(Context $context, string $operation, string $payload = ''): Response
    {
        $refused = $this->refuse($context, CallbackFailures::INVOKE, $operation);
        if ($refused !== null) {
            return new Response(Result::fail($refused), null);
        }
        $request = (new V1\InvokeRequest())
            ->setOperation($operation)
            ->setPayload($payload)
            ->setMetadata(Protocol::metadata($context));
        try {
            $response = new V1\InvokeResponse();
            $this->decode($response, $this->call(self::INVOKE_PATH, $request->serializeToString(), $context));
        } catch (GrpcException $exception) {
            $error = CallbackFailures::map(
                CallbackFailures::INVOKE,
                $exception->status,
                $exception->detail,
                $context->cancellation()->isRequested(),
                $this->pluginId,
                $operation,
            );

            return new Response(Result::fail($error), null);
        }
        $outcome = Protocol::fromStatus($response->getStatus(), $this->pluginId, $operation);

        return new Response($outcome, $outcome->isOk() ? $response->getPayload() : null);
    }

    /**
     * 经宿主的消息代理发布消息（需要权限 capability:messaging.queue），结果与信封一一对应；空列表不发给宿主。
     *
     * 宿主以回调上下文为调用上下文发布，从上下文补齐信封缺的扩展属性（租户、主体、关联 ID、区域、追踪，RFC-0009）。
     * 本地的两项核对与 RPC 的失败都是整批的结果；信封的 xihantenant 扩展不在本地核对，由宿主逐项判定。
     *
     * @param list<Envelope> $envelopes
     *
     * @return list<Result<Unit>>
     */
    public function publish(Context $context, array $envelopes): array
    {
        $count = count($envelopes);
        if ($count === 0) {
            return [];
        }
        $refused = $this->refuse($context, CallbackFailures::PUBLISH, '');
        if ($refused !== null) {
            return array_fill(0, $count, Result::fail($refused));
        }
        $request = (new V1\PublishRequest())
            ->setEnvelopes(array_map(Protocol::toProtoEnvelope(...), $envelopes))
            ->setMetadata(Protocol::metadata($context));
        try {
            $response = new V1\PublishResponse();
            $this->decode($response, $this->call(self::PUBLISH_PATH, $request->serializeToString(), $context));
        } catch (GrpcException $exception) {
            $error = CallbackFailures::map(
                CallbackFailures::PUBLISH,
                $exception->status,
                $exception->detail,
                $context->cancellation()->isRequested(),
                $this->pluginId,
                '',
            );

            return array_fill(0, $count, Result::fail($error));
        }
        $statuses = iterator_to_array($response->getResults(), false);
        if (count($statuses) !== $count) {
            $error = PluginErrors::failed($this->pluginId, 'publish', sprintf(
                'the host returned %d results for %d envelopes',
                count($statuses),
                $count,
            ));

            return array_fill(0, $count, Result::fail($error));
        }
        $outcomes = [];
        foreach ($statuses as $status) {
            $outcomes[] = Protocol::fromStatus($status, $this->pluginId, 'publish');
        }

        return $outcomes;
    }

    /**
     * 记下宿主在 Configure 里授予的权限。
     *
     * @param list<string> $permissions
     *
     * @internal
     */
    public function grant(array $permissions): void
    {
        $this->granted = $permissions;
    }

    /**
     * 断开与宿主的连接。
     *
     * @internal
     */
    public function close(): void
    {
        $this->client?->close();
    }

    /**
     * 发出之前的核对（invocation.md §5），依次是：有可用的回调端点（handshake.md §4，每次回调都以它失败）、权限、上下文的
     * 租户（与宿主的顺序相同），最后是回调上下文的截止时间与取消；都合时为 null。
     */
    private function refuse(Context $context, string $rpc, string $operation): ?Error
    {
        if ($this->client === null) {
            return PluginErrors::hostUnavailable($this->pluginId, PluginErrors::REASON_NO_ENDPOINT);
        }
        $permission = CallbackFailures::permission($rpc);
        if (!in_array($permission, $this->declared, true) || !in_array($permission, $this->granted, true)) {
            return PluginErrors::callbackDenied($this->pluginId, $permission);
        }
        $tenant = $context->tenant();
        if ($tenant !== null && !$this->tenants->admits($tenant)) {
            return PluginErrors::tenantDenied($this->pluginId, $tenant);
        }
        // 截止时间已过或已被取消也不发请求，结果与映射表相应的一行相同；先看截止时间：过期的截止令牌同样报已取消
        if ($context->isExpired($context->clock()->now())) {
            return CallbackFailures::map(
                $rpc,
                GrpcStatus::DEADLINE_EXCEEDED,
                'The deadline passed before the callback was sent.',
                true,
                $this->pluginId,
                $operation,
            );
        }
        if ($context->cancellation()->isRequested()) {
            return CallbackFailures::map(
                $rpc,
                GrpcStatus::CANCELLED,
                'The callback context was cancelled before the callback was sent.',
                true,
                $this->pluginId,
                $operation,
            );
        }

        return null;
    }

    /**
     * 发出一元回调：gRPC 截止时间取上下文的剩余时间，调用方取消即取消回调；截止时间到了由 gRPC 自己的截止时间结束回调。
     *
     * @throws GrpcException
     */
    private function call(string $path, string $request, Context $context): string
    {
        $client = $this->client ?? throw new GrpcException(GrpcStatus::UNAVAILABLE, PluginErrors::REASON_NO_ENDPOINT);
        $cancellation = new DeferredCancellation();
        // 令牌已经取消时 subscribe 立即回调，回调随即以取消结束、不发给宿主
        $subscription = $context->cancellation()->subscribe(
            static function (CancellationReason $reason) use ($cancellation): void {
                // 截止时间到了不在这里取消：gRPC 调用带着同一个截止时间，由 ClientCall 自己以 DEADLINE_EXCEEDED 结束。
                // 插件运行时在截止时刻核对上下文的截止令牌，它常常先于 ClientCall 的截止时间触发，这里一取消，
                // ClientCall 看到的是调用方取消，回调就报成 CANCELLED（invocation.md §5）
                if ($reason !== CancellationReason::DeadlineExceeded) {
                    $cancellation->cancel();
                }
            },
        );
        try {
            return $client->unary(
                $path,
                $request,
                [],
                $context->remainingTime($context->clock()->now()),
                $cancellation->getCancellation(),
            );
        } finally {
            $context->cancellation()->unsubscribe($subscription);
        }
    }

    /**
     * 解码宿主的应答；解不开时按 Internal 处理（invocation.md §5 的「其他」）。
     *
     * @throws GrpcException
     */
    private function decode(V1\InvokeResponse|V1\PublishResponse $message, string $bytes): void
    {
        try {
            $message->mergeFromString($bytes);
        } catch (\Exception $exception) {
            throw new GrpcException(
                GrpcStatus::INTERNAL,
                'The host sent a response that cannot be decoded.',
                [],
                $exception,
            );
        }
    }
}
