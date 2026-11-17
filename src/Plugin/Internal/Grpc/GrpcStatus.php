<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

/**
 * gRPC 的状态码（grpc/doc/statuscodes.md）与 HTTP/2 上的写法（grpc/doc/PROTOCOL-HTTP2.md）。
 *
 * 插件协议里 gRPC 状态只表示传输或协议层失败，业务失败走 Status.error（invocation.md §3）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class GrpcStatus
{
    public const int OK = 0;
    public const int CANCELLED = 1;
    public const int UNKNOWN = 2;
    public const int INVALID_ARGUMENT = 3;
    public const int DEADLINE_EXCEEDED = 4;
    public const int NOT_FOUND = 5;
    public const int ALREADY_EXISTS = 6;
    public const int PERMISSION_DENIED = 7;
    public const int RESOURCE_EXHAUSTED = 8;
    public const int FAILED_PRECONDITION = 9;
    public const int ABORTED = 10;
    public const int OUT_OF_RANGE = 11;
    public const int UNIMPLEMENTED = 12;
    public const int INTERNAL = 13;
    public const int UNAVAILABLE = 14;
    public const int DATA_LOSS = 15;
    public const int UNAUTHENTICATED = 16;

    /** 状态码的 PascalCase 名字（invocation.md §3）：reason 里写 `<状态名>: <说明>` 时用它 */
    private const array NAMES = [
        'OK', 'Cancelled', 'Unknown', 'InvalidArgument', 'DeadlineExceeded', 'NotFound', 'AlreadyExists',
        'PermissionDenied', 'ResourceExhausted', 'FailedPrecondition', 'Aborted', 'OutOfRange', 'Unimplemented',
        'Internal', 'Unavailable', 'DataLoss', 'Unauthenticated',
    ];

    /** grpc-timeout 的写法：至多 8 位数字加单位 */
    private const string TIMEOUT = '/^([0-9]{1,8})([HMSmun])$/D';

    private function __construct()
    {
    }

    /**
     * 返回状态码的 PascalCase 名字；0–16 之外的码规范没有名字，写成十进制数。
     */
    public static function name(int $code): string
    {
        return self::NAMES[$code] ?? (string) $code;
    }

    /**
     * 按 gRPC 的规定对 grpc-message 做百分号编码：0x20–0x7E 之外的字节与 `%` 本身写成 `%XX`。
     */
    public static function encodeMessage(string $message): string
    {
        return (string) preg_replace_callback(
            '/[^\x20-\x24\x26-\x7E]/',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $message,
        );
    }

    /**
     * 还原百分号编码的 grpc-message；不完整的转义原样保留。
     */
    public static function decodeMessage(string $message): string
    {
        return (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static fn (array $match): string => chr((int) hexdec($match[1])),
            $message,
        );
    }

    /**
     * 解析 grpc-timeout，返回秒数；写法不合法时为 null。
     */
    public static function parseTimeout(string $value): ?float
    {
        if (preg_match(self::TIMEOUT, $value, $match) !== 1) {
            return null;
        }
        $amount = (float) $match[1];

        return match ($match[2]) {
            'H' => $amount * 3600.0,
            'M' => $amount * 60.0,
            'S' => $amount,
            'm' => $amount / 1e3,
            'u' => $amount / 1e6,
            default => $amount / 1e9,
        };
    }

    /**
     * 把秒数写成 grpc-timeout：取能放进 8 位数字的最细单位，向上取整，不会比实际剩余的时间短。
     */
    public static function formatTimeout(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        foreach (['n' => 1e9, 'u' => 1e6, 'm' => 1e3, 'S' => 1.0, 'M' => 1 / 60, 'H' => 1 / 3600] as $unit => $scale) {
            $amount = (int) ceil($seconds * $scale);
            if ($amount <= 99_999_999) {
                return $amount . $unit;
            }
        }

        return '99999999H';
    }

    /**
     * 把非 200 的 HTTP 状态映射成 gRPC 状态码（grpc/doc/http-grpc-status-mapping.md）。
     */
    public static function fromHttpStatus(int $status): int
    {
        return match ($status) {
            400 => self::INTERNAL,
            401 => self::UNAUTHENTICATED,
            403 => self::PERMISSION_DENIED,
            404 => self::UNIMPLEMENTED,
            429, 502, 503, 504 => self::UNAVAILABLE,
            default => self::UNKNOWN,
        };
    }
}
