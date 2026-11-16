<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * W3C Trace Context 的 traceparent：`00-<trace-id>-<parent-id>-<trace-flags>`（RFC-0003 规则 7，ADR-0235）。
 *
 * 只认版本 00 与小写十六进制，trace-id 或 parent-id（Span ID）全零不合法；其他版本（含 ff）、大写、多余的段都不合法，
 * 不合法的当作没有给出。是否采样只看标志的最低位，其余位不进上下文；写出时版本是 00，标志只写采样位（00 或 01）。
 */
final readonly class TraceParent implements \Stringable
{
    /** traceparent 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D';

    /**
     * 创建 traceparent。
     *
     * @param string $traceId 32 位小写十六进制
     * @param string $spanId 16 位小写十六进制
     * @param bool $sampled 是否采样；没有上游的调用按采样传下去，由下游的采样器决定
     *
     * @throws InvalidArgumentException 追踪 ID 或 Span ID 不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $traceId, public string $spanId, public bool $sampled = true)
    {
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
     * 解析 traceparent；不合法时为 null，调用方当作没有给出（来自载体的不可信文本用它判断，不靠捕获异常）。
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $match) !== 1) {
            return null;
        }
        if (!self::isTraceId($match[1]) || !self::isSpanId($match[2])) {
            return null;
        }

        // 只看最低位：其余位是 W3C 留给以后的标志，不解释、不带进上下文
        return new self($match[1], $match[2], (hexdec($match[3]) & 0x01) === 0x01);
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
     * 返回 `00-<trace-id>-<span-id>-<00|01>`。
     */
    public function __toString(): string
    {
        return '00-' . $this->traceId . '-' . $this->spanId . '-' . ($this->sampled ? '01' : '00');
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
