<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/primitives/wire-encoding.json：Result 与 Error 的线上编码。
 */
final class WireEncodingTest extends TestCase
{
    public function testValidResultsReadAndWriteBackCanonically(): void
    {
        foreach (self::cases('results', 'valid') as $case) {
            $name = self::string($case, 'name');
            $json = json_encode($case->json, JSON_THROW_ON_ERROR);
            $expected = property_exists($case, 'canonical') ? $case->canonical : $case->json;
            $result = self::read(self::string($case, 'kind'), $json);
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson($result), $name);
            // 按关联数组解码的输入同样读得出
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($result->isOk(), self::readWire(self::string($case, 'kind'), $decoded)->isOk(), $name);
        }
    }

    public function testInvalidResultsAreRejected(): void
    {
        foreach (self::cases('results', 'invalid') as $case) {
            $name = self::string($case, 'name');
            try {
                self::read(self::string($case, 'kind'), json_encode($case->json, JSON_THROW_ON_ERROR));
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testValidErrorsReadAndWriteBackCanonically(): void
    {
        foreach (self::cases('errors', 'valid') as $case) {
            $name = self::string($case, 'name');
            $expected = property_exists($case, 'canonical') ? $case->canonical : $case->json;
            $error = Error::fromJson(json_encode($case->json, JSON_THROW_ON_ERROR));
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson($error), $name);
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson(Error::fromWire($case->json)), $name);
        }
    }

    public function testInvalidErrorsAreRejected(): void
    {
        foreach (self::cases('errors', 'invalid') as $case) {
            $name = self::string($case, 'name');
            try {
                Error::fromJson(json_encode($case->json, JSON_THROW_ON_ERROR));
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testDuplicateMembersAreRejected(): void
    {
        $duplicates = Vectors::primitivesAsObject('wire-encoding')->duplicates ?? null;
        self::assertIsArray($duplicates);
        self::assertNotEmpty($duplicates);
        foreach ($duplicates as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $name = self::string($case, 'name');
            $text = self::string($case, 'text');
            try {
                if (self::string($case, 'target') === 'error') {
                    Error::fromJson($text);
                } else {
                    self::read(self::string($case, 'kind'), $text);
                }
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
        // 解码后比较名字；字符串值与嵌套在数组里的同名成员不算重复
        $this->expectException(InvalidArgumentException::class);
        Error::fromJson('{"code":"KERNEL_UNEXPECTED_001","message":"x","message":"y"}');
    }

    public function testRepeatedNamesInDifferentObjectsAreFine(): void
    {
        $error = Error::fromJson(
            '{"code":"A_VALIDATION_001","message":"code","details":{"code":"x","list":[{"code":1},{"code":2}],"s":"\"code\""}}',
        );
        self::assertSame('code', $error->message());
        self::assertSame('x', $error->details['code']);
    }

    public function testTheSdkWritesTheCanonicalForms(): void
    {
        self::assertSame('{"ok":true}', json_encode(Result::success(), JSON_THROW_ON_ERROR));
        self::assertSame('{"ok":true}', json_encode(Result::ok(1)->toUnit(), JSON_THROW_ON_ERROR));
        self::assertSame('{"ok":true,"value":null}', json_encode(Result::ok(\XiHan\Framework\Primitives\Maybe::none()), JSON_THROW_ON_ERROR));
        self::assertSame('Ok()', (string) Result::success());
        self::assertTrue(Result::unitFromJson('{"ok":true}')->isOk());
    }

    /**
     * @return list<\stdClass>
     */
    private static function cases(string $group, string $validity): array
    {
        $vector = Vectors::primitivesAsObject('wire-encoding');
        $cases = $vector->{$group}->{$validity} ?? null;
        self::assertIsArray($cases);
        $checked = [];
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $checked[] = $case;
        }

        return $checked;
    }

    private static function string(\stdClass $case, string $member): string
    {
        $value = $case->{$member} ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return Result<mixed>
     */
    private static function read(string $kind, string $json): Result
    {
        return match ($kind) {
            'value' => Result::fromJson($json),
            'unit' => Result::unitFromJson($json),
            'maybe' => Result::maybeFromJson($json),
            default => throw new \LogicException(sprintf("Unknown result kind '%s'.", $kind)),
        };
    }

    /**
     * @return Result<mixed>
     */
    private static function readWire(string $kind, mixed $wire): Result
    {
        return match ($kind) {
            'value' => Result::fromWire($wire),
            'unit' => Result::unitFromWire($wire),
            'maybe' => Result::maybeFromWire($wire),
            default => throw new \LogicException(sprintf("Unknown result kind '%s'.", $kind)),
        };
    }
}
