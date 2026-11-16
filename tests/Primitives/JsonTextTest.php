<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Internal\JsonText;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/primitives/json-text.json：XiHan 读取 JSON 文本的共同检查（RFC-0001「JSON 文本」）。
 */
final class JsonTextTest extends TestCase
{
    public function testTheCheckFollowsTheVector(): void
    {
        foreach (self::cases() as [$name, $text, $expect, $path]) {
            $problem = JsonText::check($text);
            if ($expect === 'valid') {
                self::assertNull($problem, $name);
                continue;
            }
            self::assertNotNull($problem, $name);
            self::assertSame($expect === 'syntax', $problem->syntax, $name);
            self::assertSame($expect === 'syntax' ? '$' : $path, $problem->path, $name);
        }
    }

    public function testWireEntryPointsRejectWhatTheCheckRejects(): void
    {
        foreach (self::cases() as [$name, $text, $expect]) {
            if ($expect === 'valid') {
                continue;
            }
            foreach ([static fn (): mixed => Result::fromJson($text), static fn (): mixed => Error::fromJson($text)] as $read) {
                try {
                    $read();
                    self::fail(sprintf('%s must be rejected', $name));
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString($expect === 'syntax' ? 'not valid JSON' : 'duplicate', $exception->getMessage(), $name);
                }
            }
        }
    }

    /**
     * @return list<array{string, string, string, ?string}>
     */
    private static function cases(): array
    {
        $cases = Vectors::primitivesAsObject('json-text')->cases ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $checked = [];
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $name = $case->name ?? null;
            $expect = $case->expect ?? null;
            self::assertIsString($name);
            self::assertIsString($expect);
            // hex 是原始字节，用于文本表达不了的非法 UTF-8
            $text = property_exists($case, 'hex') && is_string($case->hex) ? hex2bin($case->hex) : ($case->text ?? null);
            self::assertIsString($text, $name);
            $path = $case->path ?? null;
            self::assertTrue($path === null || is_string($path), $name);
            $checked[] = [$name, $text, $expect, $path];
        }

        return $checked;
    }
}
