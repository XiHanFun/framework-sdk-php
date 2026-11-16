<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\MessageTemplate;
use XiHan\Framework\Primitives\Stability;
use XiHan\Framework\Primitives\Unit;
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
        // 按对象解码：参数里的 {} 与 [] 不混淆，整数写法的数字在 64 位范围内是 int，其余是 float，与值模型一致
        foreach (self::cases('format') as $case) {
            $name = $case->name ?? null;
            $template = $case->template ?? null;
            $locale = $case->locale ?? null;
            self::assertIsString($name);
            self::assertIsString($template);
            self::assertTrue($locale === null || is_string($locale), $name);
            $arguments = self::arguments($case);
            self::assertSame($case->lenient ?? null, MessageTemplate::format($template, $arguments, $locale), $name . ' (lenient)');
            self::assertSame($case->strict ?? null, MessageTemplate::tryFormat($template, $arguments, $locale), $name . ' (strict)');
        }
    }

    public function testDescribeFollowsTheVector(): void
    {
        foreach (self::cases('describe') as $case) {
            $key = $case->key ?? null;
            self::assertIsString($key);
            self::assertSame($case->text ?? null, MessageTemplate::describe($key, self::arguments($case)));
        }
    }

    public function testPhpValuesFollowTheValueModel(): void
    {
        $date = new \DateTimeImmutable('2026-11-16T10:00:00+08:00');
        $arguments = [
            'list' => [1, 'a'],
            'map' => ['b' => 1, 'a' => [2 => 'x']],
            'empty' => [],
            'object' => (object) ['z' => 1.0, 'y' => null],
            'enum' => Stability::Preview,
            'unit' => Unit::Value,
            'date' => $date,
            'id' => new OrderId('o-1'),
            'json' => new class () implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return ['n' => 2.5];
                }
            },
        ];
        self::assertSame(
            '[1,"a"] {"a":{"2":"x"},"b":1} [] {"y":null,"z":1} preview Value 2026-11-16T10:00:00+08:00 o-1 {"n":2.5}',
            MessageTemplate::format('{list} {map} {empty} {object} {enum} {unit} {date} {id} {json}', $arguments),
        );
        // 超过 64 层的容器写成 null
        $deep = [1];
        for ($depth = 1; $depth < 65; $depth++) {
            $deep = [$deep];
        }
        $text = MessageTemplate::format('{v}', ['v' => $deep]);
        self::assertSame(str_repeat('[', 64) . 'null' . str_repeat(']', 64), $text);
        self::assertSame(['.', ','], MessageTemplate::numberSymbols(null));
        self::assertSame([',', "\u{202F}"], MessageTemplate::numberSymbols('FR-fr'));
        self::assertSame(['.', ','], MessageTemplate::numberSymbols('it-IT'));
    }

    /**
     * @return list<\stdClass>
     */
    private static function cases(string $section): array
    {
        $cases = Vectors::primitivesAsObject('message-templates')->{$section} ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $checked = [];
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $checked[] = $case;
        }

        return $checked;
    }

    /**
     * 取用例的参数表：顶层是参数名到值，值保持对象解码的原样。
     *
     * @return array<array-key, mixed>|null
     */
    private static function arguments(\stdClass $case): ?array
    {
        $arguments = $case->arguments ?? null;
        self::assertTrue($arguments === null || $arguments instanceof \stdClass);

        return $arguments === null ? null : get_object_vars($arguments);
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
            [1e15, '1000000000000000'],
            [1e17, '1E+17'],
            [1.5e-5, '1.5E-05'],
            [INF, 'Infinity'],
            [-INF, '-Infinity'],
            [NAN, 'NaN'],
        ];
        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, MessageTemplate::format('{f}', ['f' => $value]), var_export($value, true));
        }
    }
}
