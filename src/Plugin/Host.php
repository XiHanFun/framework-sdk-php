<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use Amp\DeferredCancellation;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Operation\Response;
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
 * 每次回调带会话令牌。所需权限必须在清单 permissions 里声明并已被宿主授予，租户只能是宿主此刻交给插件处理的某个调用的
 * 租户：本端先按这两条核对，不合的不发给宿主，结果与宿主拒绝时相同（PLUGINS_DENIED_002）。gRPC 状态按 invocation.md §3
 * 的映射表转成错误。运行时在每次调用与投递的上下文里放入它，取用 Host::of($context)。
 */
final class Host
{
    /** Host.Invoke 需要的权限。 */
    public const string PERMISSION_INVOKE = 'capability:kernel.pipeline';

    /** Host.Publish 需要的权限。 */
    public const string PERMISSION_PUBLISH = 'capability:messaging.queue';

    /** 上下文对象袋里的键。 */
    public const string ITEM_KEY = self::class;

    /** 信封里表示租户的 CloudEvents 扩展（RFC-0009「信封」） */
    private const string TENANT_EXTENSION = 'xihantenant';

    private const string INVOKE_PATH = '/xihan.plugin.v1.Host/Invoke';

    private const string PUBLISH_PATH = '/xihan.plugin.v1.Host/Publish';

    private readonly ?GrpcClient $client;

    /** @var list<string>|null 宿主在 Configure 里授予的权限；Configure 之前为 null */
    private ?array $granted = null;

    /**
     * 由运行时创建。
     *
     * @param list<string> $declared 清单 permissions
     *
     * @internal
     */
    public function __construct(
        private readonly Endpoint $endpoint,
        string $session,
        private readonly string $pluginId,
        private readonly array $declared,
        private readonly TenantScope $tenants,
    ) {
        $uri = $endpoint->socketUri();
        $this->client = $uri === null ? null : new GrpcClient(
            $uri,
            'localhost',
            ['xihan-plugin-session' => $session],
            FrameworkInfo::SDK_NAME . '/' . FrameworkInfo::SDK_VERSION,
        );
    }

    /**
     * 取运行时放在上下文里的宿主客户端；宿主没有给回调端点时为 null。
     */
    public static function of(Context $context): ?self
    {
        $host = $context->items()->get(self::ITEM_KEY);

        return $host instanceof self ? $host : null;
    }

    /**
     * 返回宿主的回调端点。
     */
    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    /**
     * 以插件身份经宿主的完整管道调用一个操作（需要权限 capability:kernel.pipeline）。
     *
     * 上下文的截止时间、追踪与已登记的 Baggage 随调用传给宿主，调用方的取消即取消回调。
     *
     * @param string $payload 请求的 JSON；空等价于 `{}`
     */
    public function invoke(Context $context, string $operation, string $payload = ''): Response
    {
        $denied = $this->deny($context, self::PERMISSION_INVOKE);
        if ($denied !== null) {
            return new Response(Result::fail($denied), null);
        }
        $request = (new V1\InvokeRequest())
            ->setOperation($operation)
            ->setPayload($payload)
            ->setMetadata(Protocol::metadata($context));
        try {
            $response = new V1\InvokeResponse();
            $response->mergeFromString($this->call(self::INVOKE_PATH, $request->serializeToString(), $context));
        } catch (GrpcException $exception) {
            $error = $this->fromGrpc($exception, $context, self::PERMISSION_INVOKE, $operation);

            return new Response(Result::fail($error), null);
        } catch (\Exception $exception) {
            return new Response(Result::fail($this->invalidResponse($exception)), null);
        }
        $outcome = Protocol::fromStatus($response->getStatus(), $this->pluginId);

        return new Response($outcome, $outcome->isOk() ? $response->getPayload() : null);
    }

    /**
     * 经宿主的消息代理发布消息（需要权限 capability:messaging.queue），结果与信封一一对应；空列表不发给宿主。
     *
     * 信封的 xihantenant 扩展逐项核对，越界的那一项为 PLUGINS_DENIED_002，其余照常发布。
     *
     * @param list<Envelope> $envelopes
     *
     * @return list<Result<Unit>>
     */
    public function publish(Context $context, array $envelopes): array
    {
        if ($envelopes === []) {
            return [];
        }
        $denied = $this->deny($context, self::PERMISSION_PUBLISH);
        if ($denied !== null) {
            return array_fill(0, count($envelopes), Result::fail($denied));
        }
        $results = [];
        $sent = [];
        foreach ($envelopes as $index => $envelope) {
            $tenant = $envelope->extensions[self::TENANT_EXTENSION] ?? null;
            if ($tenant !== null && !$this->tenants->admits($tenant)) {
                $results[$index] = Result::fail(PluginErrors::tenantDenied($this->pluginId, $tenant));
            } else {
                $sent[$index] = Protocol::toProtoEnvelope($envelope);
            }
        }
        if ($sent !== []) {
            $request = (new V1\PublishRequest())
                ->setEnvelopes(array_values($sent))
                ->setMetadata(Protocol::metadata($context));
            $outcomes = $this->publishBatch($request, $context, count($sent));
            foreach (array_keys($sent) as $position => $index) {
                $results[$index] = $outcomes[$position];
            }
        }
        ksort($results);

        return array_values($results);
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
     * 发布一批信封；整批失败时每一项都是同一个错误。
     *
     * @return list<Result<Unit>>
     */
    private function publishBatch(V1\PublishRequest $request, Context $context, int $count): array
    {
        try {
            $response = new V1\PublishResponse();
            $response->mergeFromString($this->call(self::PUBLISH_PATH, $request->serializeToString(), $context));
        } catch (GrpcException $exception) {
            $error = $this->fromGrpc($exception, $context, self::PERMISSION_PUBLISH, null);

            return array_fill(0, $count, Result::fail($error));
        } catch (\Exception $exception) {
            return array_fill(0, $count, Result::fail($this->invalidResponse($exception)));
        }
        $outcomes = [];
        $statuses = iterator_to_array($response->getResults(), false);
        for ($index = 0; $index < $count; $index++) {
            $status = $statuses[$index] ?? null;
            $outcomes[] = $status instanceof V1\Status
                ? Protocol::fromStatus($status, $this->pluginId)
                : Result::fail(PluginErrors::failed(
                    $this->pluginId,
                    'callback',
                    'The host returned fewer results than envelopes.',
                ));
        }

        return $outcomes;
    }

    /**
     * 发出一元回调：截止时间取上下文的剩余时间，上下文的取消令牌触发即取消回调。
     *
     * @throws GrpcException
     */
    private function call(string $path, string $request, Context $context): string
    {
        if ($this->client === null) {
            throw new GrpcException(
                GrpcStatus::UNAVAILABLE,
                sprintf("The PHP plugin runtime cannot connect to the endpoint '%s'.", $this->endpoint),
            );
        }
        $cancellation = new DeferredCancellation();
        // 令牌已经取消时 subscribe 立即回调，回调随即以取消结束、不发给宿主
        $subscription = $context->cancellation()->subscribe(static fn () => $cancellation->cancel());
        try {
            return $this->client->unary(
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
     * 本端先核对的两条：权限已声明并已授予，上下文的租户是在途调用的租户。
     */
    private function deny(Context $context, string $permission): ?Error
    {
        $granted = $this->granted === null || in_array($permission, $this->granted, true);
        if (!$granted || !in_array($permission, $this->declared, true)) {
            return PluginErrors::callbackDenied($this->pluginId, $permission);
        }
        $tenant = $context->tenant();
        if ($tenant !== null && !$this->tenants->admits($tenant)) {
            return PluginErrors::tenantDenied($this->pluginId, $tenant);
        }

        return null;
    }

    /**
     * 把回调的 gRPC 状态转成错误，按 invocation.md §3 的映射表；取消时按调用方的令牌归因。
     */
    private function fromGrpc(GrpcException $exception, Context $context, string $permission, ?string $operation): Error
    {
        return match ($exception->status) {
            GrpcStatus::DEADLINE_EXCEEDED => CancellationReason::DeadlineExceeded->toError(),
            GrpcStatus::CANCELLED => $context->cancellation()->reason()?->toError()
                ?? PluginErrors::failed($this->pluginId, 'callback', $exception->detail, PluginErrors::UNAVAILABLE),
            GrpcStatus::UNAVAILABLE => PluginErrors::failed(
                $this->pluginId,
                'callback',
                $exception->detail,
                PluginErrors::UNAVAILABLE,
            ),
            GrpcStatus::UNAUTHENTICATED => PluginErrors::sessionInvalid(),
            GrpcStatus::PERMISSION_DENIED => PluginErrors::callbackDenied($this->pluginId, $permission),
            GrpcStatus::UNIMPLEMENTED => $operation === null
                ? PluginErrors::failed($this->pluginId, 'callback', $exception->getMessage())
                : OperationErrors::notFound($operation),
            GrpcStatus::INVALID_ARGUMENT => $operation === null
                ? PluginErrors::failed($this->pluginId, 'callback', $exception->getMessage())
                : OperationErrors::invalidPayload($operation, '$'),
            default => PluginErrors::failed($this->pluginId, 'callback', $exception->getMessage()),
        };
    }

    /**
     * 宿主的应答解不开。
     */
    private function invalidResponse(\Exception $exception): Error
    {
        return PluginErrors::failed(
            $this->pluginId,
            'callback',
            sprintf('The host sent a response that cannot be decoded (%s).', $exception::class),
        );
    }
}
