<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Invocation;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Invocation\TraceParent;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/primitives/trace-context.json：traceparent 的解析与写出。
 */
final class TraceContextTest extends TestCase
{
    public function testValidTraceParentsParse(): void
    {
        foreach (self::section('parse', 'valid') as $case) {
            $text = self::text($case, 'text');
            $parsed = TraceParent::tryParse($text);
            self::assertNotNull($parsed, $text);
            self::assertSame($case['traceId'] ?? null, $parsed->traceId, $text);
            self::assertSame($case['spanId'] ?? null, $parsed->spanId, $text);
            self::assertSame($case['sampled'] ?? null, $parsed->sampled, $text);

            $context = Context::create(traceParent: $text);
            self::assertSame($parsed->traceId, $context->traceId(), $text);
            self::assertSame($parsed->sampled, $context->isSampled(), $text);
        }
    }

    public function testInvalidTraceParentsAreTreatedAsAbsent(): void
    {
        $parse = Vectors::primitives('trace-context')['parse'] ?? null;
        self::assertIsArray($parse);
        foreach (Vectors::strings($parse, 'invalid') as $text) {
            self::assertNull(TraceParent::tryParse($text), sprintf('%s must not parse', json_encode($text)));
            $context = Context::create(traceParent: $text);
            self::assertTrue(TraceParent::isTraceId($context->traceId()), 'a fresh trace replaces the invalid one');
            self::assertTrue($context->isSampled());
        }
    }

    public function testFormattingWritesOnlyTheSampledBit(): void
    {
        foreach (self::section('format', null) as $case) {
            $traceId = self::text($case, 'traceId');
            $spanId = self::text($case, 'spanId');
            $sampled = $case['sampled'] ?? null;
            // sampled 为 null 是没有上游标志的根调用：只给追踪 ID 与 Span ID 建上下文
            $text = $sampled === null
                ? Context::create(traceId: $traceId, spanId: $spanId)->traceParent()
                : (string) new TraceParent($traceId, $spanId, (bool) $sampled);
            self::assertSame($case['text'] ?? null, $text);
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function section(string $name, ?string $part): array
    {
        $vector = Vectors::primitives('trace-context');
        if ($part === null) {
            return Vectors::objects($vector, $name);
        }
        $section = $vector[$name] ?? null;
        self::assertIsArray($section);

        return Vectors::objects($section, $part);
    }

    /**
     * @param array<array-key, mixed> $case
     */
    private static function text(array $case, string $field): string
    {
        $value = $case[$field] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
