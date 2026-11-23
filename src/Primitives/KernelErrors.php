<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 内核错误码（conventions/error-codes.md，对照 .NET 的 KernelErrors）与调用层面的内核错误。
 *
 * 只列本 SDK 会产生或核对的码：清单读取、管道排序、调用的截止时间与取消、操作的登记与调用。宿主独有的码（能力解析、
 * 宿主生命周期、限流与熔断、权限登记等）不在这里，登记表见规范。消息键在 KernelMessages，码、键与参数的对应见
 * spec/conformance/vectors/primitives/kernel-messages.json（RFC-0011「错误码」，ADR-0233）。
 *
 * 调用层面的四个错误（超时、开始前已超时、取消、未处理的异常）由这里创建；操作自己的错误由 Operation\OperationErrors
 * 创建，插件运行时的错误由 Plugin\PluginErrors 创建。
 */
final class KernelErrors
{
    /** 清单读取失败：文件不存在、不是 JSON、为 null、不合 Schema 或跨字段约束不满足（RFC-0002 解析规则 2）。 */
    public const string INVALID_MANIFEST = 'KERNEL_CAPABILITY_006';

    /** 中间件顺序约束成环（RFC-0004 排序规则 8）。 */
    public const string PIPELINE_CYCLE = 'KERNEL_PIPELINE_001';

    /** 阶段名未知。 */
    public const string UNKNOWN_STAGE = 'KERNEL_PIPELINE_002';

    /** 中间件重复登记，约束主体声明时未登记，或顺序约束的目标未登记、有歧义或跨阶段。 */
    public const string PIPELINE_REGISTRATION = 'KERNEL_PIPELINE_003';

    /** 调用超过截止时间；截止令牌已触发时下游抛出或写下的取消都报为它（RFC-0003 规则 1）。 */
    public const string DEADLINE_EXCEEDED = 'KERNEL_TIMEOUT_001';

    /** 调用在截止时间到达之前被调用方取消。 */
    public const string CANCELLED = 'KERNEL_CANCELLED_001';

    /** 调用期间发生未处理的异常，或管道以成功结束却没有响应。 */
    public const string UNEXPECTED = 'KERNEL_UNEXPECTED_001';

    /** 操作未登记。 */
    public const string UNKNOWN_OPERATION = 'KERNEL_NOTFOUND_001';

    /** 操作载荷不合法：非 JSON、null 或类型不匹配。 */
    public const string INVALID_OPERATION_PAYLOAD = 'KERNEL_VALIDATION_001';

    /** 类型化调用的请求或响应类型与登记不符。 */
    public const string OPERATION_TYPE_MISMATCH = 'KERNEL_VALIDATION_002';

    /** 操作 ID 重复登记（配置期）。 */
    public const string DUPLICATE_OPERATION = 'KERNEL_OPERATION_001';

    /** 操作登记表已满（配置期）。 */
    public const string OPERATION_REGISTRY_FULL = 'KERNEL_OPERATION_002';

    private function __construct()
    {
    }

    /**
     * 截止时间在调用途中到达：KERNEL_TIMEOUT_001 + kernel.invocation.deadline_exceeded，没有参数（RFC-0003 规则 1）。
     */
    public static function deadlineExceeded(): Error
    {
        return Error::timeout(self::DEADLINE_EXCEEDED, KernelMessages::DEADLINE_EXCEEDED);
    }

    /**
     * 调用开始时截止时间已过：KERNEL_TIMEOUT_001 + kernel.invocation.deadline_before_start，没有参数（RFC-0011 规则 10）。
     */
    public static function deadlineBeforeStart(): Error
    {
        return Error::timeout(self::DEADLINE_EXCEEDED, KernelMessages::DEADLINE_BEFORE_START);
    }

    /**
     * 截止时间之前调用方取消：KERNEL_CANCELLED_001 + kernel.invocation.cancelled，没有参数（RFC-0003 规则 1）。
     */
    public static function cancelled(): Error
    {
        return Error::cancelled(self::CANCELLED, KernelMessages::CANCELLED);
    }

    /**
     * 调用期间抛出的异常：KERNEL_UNEXPECTED_001 + kernel.invocation.unhandled_exception（RFC-0011 规则 5）。
     *
     * 框架异常的错误作为内部错误带上；其他异常除了「发生过」什么都不上线（消息可能含路径、连接串），原始异常要记日志时在
     * Observe 阶段的中间件里捕获。
     */
    public static function unhandledException(\Throwable $exception): Error
    {
        $inner = $exception instanceof FrameworkException ? $exception->toError() : null;

        return Error::unexpected(self::UNEXPECTED, KernelMessages::UNHANDLED_EXCEPTION, [], $inner);
    }
}
