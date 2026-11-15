<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * W3C Trace Context 的 traceparent：`version-traceid-parentid-flags`（RFC-0003「字段」）。
 *
 * trace-id 是 32 位、parent-id（Span ID）是 16 位小写十六进制，都不能全为零；本 SDK 写出的版本是 00。
 *
 * trace-flags 原样保留：是否采样只看最低位（W3C 的 sampled），其余位不解释、写出时照旧（RFC-0003 规则 7）。
 * 没有上游的调用由本 SDK 生成追踪，标志写 01，即按已采样传下去、由下游的采样器决定。
 */
final readonly class TraceParent implements \Stringable
{
    /** 采样位。 */
    public const int FLAG_SAMPLED = 0x01;

    /** traceparent 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D';

    /**
     * 创建 traceparent。
     *
     * @param string $traceId 32 位小写十六进制
     * @param string $spanId 16 位小写十六进制
     * @param int $flags trace-flags，0–255；缺省为采样（01）
     *
     * @throws InvalidArgumentException 追踪 ID、Span ID 或标志不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $traceId, public string $spanId, public int $flags = self::FLAG_SAMPLED)
    {
        if ($flags < 0 || $flags > 0xFF) {
            throw new InvalidArgumentException(sprintf('The trace flags must be between 0 and 255, got %d.', $flags));
        }
        if (!self::isTraceId($traceId)) {
            throw new InvalidArgumentException(sprintf(
                "The trace ID '%s' must be 32 lowercase hexadecimal digits and not all zeros.",
                $traceId,
            ));
        }
        if (!self::isSpanId($spanId)) {
            throw new InvalidArgumentException(sprintf(
                "The span ID '%s' must be 16 lowercase hexadecimal digits and not all zeros.",
                $spanId,
            ));
        }
    }

    /**
     * 解析 traceparent；不合法时为 null（来自载体的不可信文本先用它判断，不靠捕获异常）。
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $match) !== 1) {
            return null;
        }
        if (!self::isTraceId($match[2]) || !self::isSpanId($match[3])) {
            return null;
        }

        return new self($match[2], $match[3], (int) hexdec($match[4]));
    }

    /**
     * 判断是否采样：标志的最低位。
     */
    public function isSampled(): bool
    {
        return ($this->flags & self::FLAG_SAMPLED) === self::FLAG_SAMPLED;
    }

    /**
     * 判断是否合法的追踪 ID：32 位小写十六进制且不全为零。
     */
    public static function isTraceId(string $traceId): bool
    {
        return preg_match('/^[0-9a-f]{32}$/D', $traceId) === 1 && trim($traceId, '0') !== '';
    }

    /**
     * 判断是否合法的 Span ID：16 位小写十六进制且不全为零。
     */
    public static function isSpanId(string $spanId): bool
    {
        return preg_match('/^[0-9a-f]{16}$/D', $spanId) === 1 && trim($spanId, '0') !== '';
    }

    /**
     * 生成新的追踪 ID。
     */
    public static function newTraceId(): string
    {
        return self::randomHex(16);
    }

    /**
     * 生成新的 Span ID。
     */
    public static function newSpanId(): string
    {
        return self::randomHex(8);
    }

    /**
     * 返回 `00-<trace-id>-<span-id>-<flags>`，标志按两位小写十六进制原样写出。
     */
    public function __toString(): string
    {
        return '00-' . $this->traceId . '-' . $this->spanId . '-' . sprintf('%02x', $this->flags);
    }

    private static function randomHex(int $bytes): string
    {
        // 全零是 W3C 规定的非法值，碰上（概率在 2^-64 以下）就重新生成
        do {
            $hex = bin2hex(random_bytes(max(1, $bytes)));
        } while (trim($hex, '0') === '');

        return $hex;
    }
}
