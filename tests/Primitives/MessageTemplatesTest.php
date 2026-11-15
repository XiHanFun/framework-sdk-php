<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\MessageTemplate;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/primitives/message-templates.json，并补充向量之外的数字边界。
 */
final class MessageTemplatesTest extends TestCase
{
    public function testKeysFollowTheVector(): void
    {
        $keys = Vectors::primitives('message-templates')['keys'] ?? null;
        self::assertIsArray($keys);
        foreach (Vectors::strings($keys, 'valid') as $key) {
            self::assertTrue(MessageTemplate::isValidKey($key), sprintf('%s must be valid', $key));
        }
        foreach (Vectors::strings($keys, 'invalid') as $key) {
            self::assertFalse(MessageTemplate::isValidKey($key), sprintf('%s must be invalid', $key));
        }
    }

    public function testFormattingFollowsTheVector(): void
    {
        foreach (Vectors::objects(Vectors::primitives('message-templates'), 'format') as $case) {
            $name = $case['name'] ?? null;
            $template = $case['template'] ?? null;
            $arguments = $case['arguments'] ?? null;
            self::assertIsString($name);
            self::assertIsString($template);
            self::assertTrue($arguments === null || is_array($arguments), $name);
            self::assertSame($case['lenient'] ?? null, MessageTemplate::format($template, $arguments), $name . ' (lenient)');
            self::assertSame($case['strict'] ?? null, MessageTemplate::tryFormat($template, $arguments), $name . ' (strict)');
        }
    }

    public function testDescribeFollowsTheVector(): void
    {
        foreach (Vectors::objects(Vectors::primitives('message-templates'), 'describe') as $case) {
            $key = $case['key'] ?? null;
            $arguments = $case['arguments'] ?? null;
            self::assertIsString($key);
            self::assertTrue($arguments === null || is_array($arguments));
            self::assertSame($case['text'] ?? null, MessageTemplate::describe($key, $arguments));
        }
    }

    public function testFixedPointUsesTheExactBinaryValue(): void
    {
        $cases = [
            ['{n:F2}', 1.005, '1.00'],       // 1.005 的二进制值略小于 1.005
            ['{n:F1}', 0.25, '0.2'],
            ['{n:F1}', 0.35, '0.3'],         // 0.35 的二进制值略小于 0.35
            ['{n:F0}', 0.5, '0'],
            ['{n:F0}', 1.5, '2'],
            ['{n:F0}', 9.5, '10'],
            ['{n:F2}', 99.995, '100.00'],    // 99.995 的二进制值略大于 99.995
            ['{n:F3}', 5e-324, '0.000'],
            ['{n:F2}', -0.001, '-0.00'],     // 与 .NET 一致：负值舍成零仍带负号（规范不作规定）
            ['{n:N0}', 999999.5, '1,000,000'],
            ['{n:N2}', -1234567.005, '-1,234,567.00'],
            ['{n:F2}', PHP_INT_MAX, '9223372036854775807.00'],
            ['{n:N0}', PHP_INT_MIN, '-9,223,372,036,854,775,808'],
            ['{n:D3}', PHP_INT_MIN, '-9223372036854775808'],
            ['{n:X}', PHP_INT_MAX, '7FFFFFFFFFFFFFFF'],
            ['{n:f1}', 2.25, '2.2'],
            ['{n:n1}', 1234.25, '1,234.2'],
            ['{n:d4}', 7, '0007'],
        ];
        foreach ($cases as [$template, $value, $expected]) {
            self::assertSame($expected, MessageTemplate::format($template, ['n' => $value]), $template . ' with ' . var_export($value, true));
        }
        // 大数的整数部分与 sprintf 的精确展开逐位相同
        $huge = MessageTemplate::format('{n:F2}', ['n' => 1e300]);
        self::assertSame(sprintf('%.0f', 1e300) . '.00', $huge);
        self::assertSame(304, strlen($huge));
    }

    public function testUnsupportedFormatsAndValuesPrintPlainly(): void
    {
        self::assertSame('7', MessageTemplate::format('{n:%03d}', ['n' => 7]), 'printf formats are not portable');
        self::assertSame('2026', MessageTemplate::format('{when:yyyy}', ['when' => 2026]));
        self::assertSame('1.5', MessageTemplate::format('{n:D2}', ['n' => 1.5]), 'D only applies to integers');
        self::assertSame('-1', MessageTemplate::format('{n:X}', ['n' => -1]), 'X only applies to non-negative integers');
        self::assertSame('abc', MessageTemplate::format('{n:F2}', ['n' => 'abc']));
        self::assertSame('NaN', MessageTemplate::format('{n:F2}', ['n' => NAN]));
    }

    public function testPlainFloatText(): void
    {
        $cases = [
            [0.1 + 0.2, '0.30000000000000004'],
            [1e14, '100000000000000'],
            [123456789012345.6, '123456789012345.6'],
            [0.00012, '0.00012'],
            [-2.5, '-2.5'],
            [0.0, '0'],
            [-0.0, '-0'],
            [1e15, '1E+15'],
            [1.5e-5, '1.5E-05'],
            [INF, '∞'],
        ];
        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, MessageTemplate::format('{f}', ['f' => $value]), var_export($value, true));
        }
    }
}
