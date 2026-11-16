<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\FrameworkException;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\KernelMessages;

/**
 * 操作相关的内核错误（RFC-0011「错误码」，ADR-0233）：码、消息键、参数与 SDK 与宿主共用的表一致，
 * 见 spec/conformance/vectors/primitives/kernel-messages.json。
 */
final class OperationErrors
{
    /** 操作未登记。 */
    public const string NOT_FOUND = 'KERNEL_NOTFOUND_001';

    /** 操作载荷不合法：非 JSON、null 或类型不匹配。 */
    public const string INVALID_PAYLOAD = 'KERNEL_VALIDATION_001';

    /** 类型化调用的请求或响应类型与登记不符。 */
    public const string TYPE_MISMATCH = 'KERNEL_VALIDATION_002';

    /** 操作 ID 重复登记（配置期）。 */
    public const string DUPLICATE = 'KERNEL_OPERATION_001';

    /** 操作登记表已满（配置期）。 */
    public const string REGISTRY_FULL = 'KERNEL_OPERATION_002';

    /** 调用期的意外失败：响应编码失败等。 */
    public const string UNEXPECTED = 'KERNEL_UNEXPECTED_001';

    /** 操作未登记的消息键，参数 id。 */
    public const string MESSAGE_NOT_FOUND = KernelMessages::OPERATION_UNKNOWN;

    /** 载荷不合法的消息键，参数 id、reason。 */
    public const string MESSAGE_INVALID_PAYLOAD = KernelMessages::OPERATION_INVALID_PAYLOAD;

    /** 类型不符的消息键，参数 id。 */
    public const string MESSAGE_TYPE_MISMATCH = KernelMessages::OPERATION_TYPE_MISMATCH;

    /** 重复登记的消息键，参数 id、owner。 */
    public const string MESSAGE_DUPLICATE = KernelMessages::OPERATION_DUPLICATE;

    /** 登记表已满的消息键，参数 capacity。 */
    public const string MESSAGE_REGISTRY_FULL = KernelMessages::OPERATION_REGISTRY_FULL;

    private function __construct()
    {
    }

    /**
     * 操作未登记：KERNEL_NOTFOUND_001。
     */
    public static function notFound(string $id): Error
    {
        return Error::notFound(self::NOT_FOUND, self::MESSAGE_NOT_FOUND, ['id' => $id]);
    }

    /**
     * 载荷不合法：KERNEL_VALIDATION_001。
     *
     * `$reason` 只写出错位置的 JSON 路径，不带类型名（RFC-0011 规则 2，ADR-0124、ADR-0235、ADR-0236）：语法错误、载荷是
     * null、不是对象或根上缺少必填成员时写 `$`，重复成员写第一处重复的路径，某个值的类型不符时写它的路径（用 JsonPath 拼，
     * 如 `$.lines[1].quantity`，根是 JsonPath::ROOT）。
     */
    public static function invalidPayload(string $id, string $reason): Error
    {
        return Error::validation(
            self::INVALID_PAYLOAD,
            self::MESSAGE_INVALID_PAYLOAD,
            ['id' => $id, 'reason' => $reason],
        );
    }

    /**
     * 类型化调用的请求或响应类型与登记不符：KERNEL_VALIDATION_002，只带 id（ADR-0236：实现语言的类型名各语言写不出同样的参数）。
     */
    public static function typeMismatch(string $id): Error
    {
        return Error::validation(self::TYPE_MISMATCH, self::MESSAGE_TYPE_MISMATCH, ['id' => $id]);
    }

    /**
     * 管道以成功结束却没有响应（中间件以成功短路、处理函数没有执行，又不是幂等回放）：KERNEL_UNEXPECTED_001（RFC-0011 规则 9）。
     */
    public static function noResponse(string $id): Error
    {
        return Error::unexpected(self::UNEXPECTED, KernelMessages::OPERATION_NO_RESPONSE, ['id' => $id]);
    }

    /**
     * 调用开始时截止时间已过：KERNEL_TIMEOUT_001 + kernel.invocation.deadline_before_start，没有参数（RFC-0011 规则 10）。
     */
    public static function deadlineBeforeStart(): Error
    {
        return Error::timeout(Context::ERROR_CODE_DEADLINE_EXCEEDED, KernelMessages::DEADLINE_BEFORE_START);
    }

    /**
     * 调用期间抛出的异常：KERNEL_UNEXPECTED_001 + kernel.invocation.unhandled_exception（RFC-0011 规则 5）。
     *
     * 框架异常的错误作为内部错误带上；其他异常只记类型之外什么都不上线，原始异常要记日志时在 Observe 阶段的中间件里捕获。
     */
    public static function unhandledException(\Throwable $exception): Error
    {
        $inner = $exception instanceof FrameworkException ? $exception->toError() : null;

        return Error::unexpected(self::UNEXPECTED, KernelMessages::UNHANDLED_EXCEPTION, [], $inner);
    }

    /**
     * 操作 ID 重复登记：KERNEL_OPERATION_001；owner 是已经登记了它的归属。
     */
    public static function duplicate(string $id, string $owner): Error
    {
        return Error::of(self::DUPLICATE, self::MESSAGE_DUPLICATE, ['id' => $id, 'owner' => $owner]);
    }

    /**
     * 操作登记表已满：KERNEL_OPERATION_002。
     */
    public static function registryFull(int $capacity): Error
    {
        return Error::of(self::REGISTRY_FULL, self::MESSAGE_REGISTRY_FULL, ['capacity' => $capacity]);
    }
}
