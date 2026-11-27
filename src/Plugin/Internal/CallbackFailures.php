<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Primitives\Error;

/**
 * 插件一侧回调失败的映射（invocation.md §5 的表，向量 conformance/vectors/plugin/callback-status.json）：gRPC 状态、状态的
 * 消息与回调上下文的取消信号是否已触发，到错误。连接失败按 Unavailable 传入。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class CallbackFailures
{
    /** Host.Invoke。 */
    public const string INVOKE = 'invoke';

    /** Host.Publish。 */
    public const string PUBLISH = 'publish';

    /**
     * 要写进回调元数据的 Baggage 值不是合法的 UTF-8 时 plugins.process.failed 的 reason 模板（plugin-messages.json，
     * invocation.md §5，ADR-0347）：`{key}` 换成键名，不带值；以字节存放字符串的实现也写这一段，与宿主一侧逐字相同。
     */
    public const string REASON_UNENCODABLE_BAGGAGE =
        "baggage '{key}' has a lone surrogate and cannot be encoded as UTF-8";

    private function __construct()
    {
    }

    /**
     * 回调元数据的核对（invocation.md §5，ADR-0347）：要写进 metadata.baggage 的值（Protocol::metadata() 写出的已登记的键）不是
     * 合法的 UTF-8 即不发请求，结果是 PLUGINS_UNEXPECTED_001 + plugins.process.failed，`phase` 与回调的其他失败相同
     * （Host.Invoke 为操作 ID，Host.Publish 为 publish）；都合法时为 null。
     *
     * PHP 的字符串是字节串，孤立的代理项在这里就是不合法的 UTF-8（如 `ED A0 80`）；protobuf 的 setBaggage 遇到它会抛出。只看是不是
     * 合法的 UTF-8，不按 Baggage 值的完整约束判定：幂等键不受长度与控制字符的约束，完整判定会误拒。几个键都是这样时点名先遇到的一个。
     */
    public static function unencodableBaggage(
        Context $context,
        string $rpc,
        string $pluginId,
        string $operation,
    ): ?Error {
        foreach ($context->withoutUnregisteredBaggage()->baggage() as $key => $value) {
            // PCRE 的 UTF-8 校验同样拒绝过长编码、代理项与 U+10FFFF 以上的码位
            if (preg_match('//u', $value) !== 1) {
                return PluginErrors::failed(
                    $pluginId,
                    $rpc === self::PUBLISH ? 'publish' : $operation,
                    str_replace('{key}', (string) $key, self::REASON_UNENCODABLE_BAGGAGE),
                );
            }
        }

        return null;
    }

    /**
     * 返回回调 RPC 所需的权限：Host.Invoke 为 capability:kernel.pipeline，Host.Publish 为 capability:messaging.queue。
     */
    public static function permission(string $rpc): string
    {
        return $rpc === self::PUBLISH ? Host::PERMISSION_PUBLISH : Host::PERMISSION_INVOKE;
    }

    /**
     * 写 reason 参数里的 `<状态名>: <说明>`：状态名是 PascalCase，说明原样取状态的消息（invocation.md §3）。
     */
    public static function reason(int $status, string $message): string
    {
        return GrpcStatus::name($status) . ': ' . $message;
    }

    /**
     * 把回调的 gRPC 失败映射成错误；$operation 是 Host.Invoke 的操作 ID（Host.Publish 时不用）。
     */
    public static function map(
        string $rpc,
        int $status,
        string $message,
        bool $contextCancelled,
        string $pluginId,
        string $operation,
    ): Error {
        return match (true) {
            $status === GrpcStatus::UNAUTHENTICATED => PluginErrors::sessionInvalid(),
            $status === GrpcStatus::PERMISSION_DENIED => PluginErrors::callbackDenied(
                $pluginId,
                self::permission($rpc),
            ),
            $status === GrpcStatus::DEADLINE_EXCEEDED => CancellationReason::DeadlineExceeded->toError(),
            // 调用方确实取消了才算取消；超时的归因交给插件自己管道里的截止时间步骤（RFC-0003），不按时钟改判
            $status === GrpcStatus::CANCELLED && $contextCancelled => CancellationReason::Cancelled->toError(),
            // 调用方没有取消的 Cancelled 是连接被宿主或网络切断，与 Unavailable 同样是连不上宿主
            $status === GrpcStatus::CANCELLED, $status === GrpcStatus::UNAVAILABLE => PluginErrors::hostUnavailable(
                $pluginId,
                self::reason($status, $message),
            ),
            // Unimplemented 与 InvalidArgument 只对 Host.Invoke 有操作层面的含义，Host.Publish 的落到最后一项
            $status === GrpcStatus::UNIMPLEMENTED && $rpc === self::INVOKE => OperationErrors::notFound($operation),
            $status === GrpcStatus::INVALID_ARGUMENT && $rpc === self::INVOKE => OperationErrors::invalidPayload(
                $operation,
                $message,
            ),
            default => PluginErrors::failed(
                $pluginId,
                $rpc === self::PUBLISH ? 'publish' : $operation,
                self::reason($status, $message),
            ),
        };
    }
}
