<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Error;

/**
 * 内核登记的操作错误码与消息键（RFC-0011「错误码」），以及调用期失败的工厂。
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

    /** 响应编码失败等调用期的意外失败。 */
    public const string UNEXPECTED = 'KERNEL_UNEXPECTED_001';

    /** 操作未登记的消息键，参数 id。 */
    public const string MESSAGE_NOT_FOUND = 'kernel.operation.unknown';

    /** 载荷不合法的消息键，参数 id、reason。 */
    public const string MESSAGE_INVALID_PAYLOAD = 'kernel.operation.payload_invalid';

    /** 类型不符的消息键，参数 id、expected、actual。 */
    public const string MESSAGE_TYPE_MISMATCH = 'kernel.operation.type_mismatch';

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
     * 载荷不合法：KERNEL_VALIDATION_001；原因只写 JSON 路径与问题，不带类型名（ADR-0124）。
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
     * 类型化调用的类型与登记不符：KERNEL_VALIDATION_002。
     */
    public static function typeMismatch(string $id, string $expected, string $actual): Error
    {
        return Error::validation(
            self::TYPE_MISMATCH,
            self::MESSAGE_TYPE_MISMATCH,
            ['id' => $id, 'expected' => $expected, 'actual' => $actual],
        );
    }
}
