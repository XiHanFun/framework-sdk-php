<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/primitives/wire-encoding.json：Result 与 Error 的线上编码。
 */
final class WireEncodingTest extends TestCase
{
    /** 把向量里的 JSON 值写回文本：整数值的浮点保留小数点，按值模型仍是浮点 */
    private const int FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function testValidResultsReadAndWriteBackCanonically(): void
    {
        foreach (self::cases('results', 'valid') as $case) {
            $name = self::string($case, 'name');
            $json = json_encode($case->json, self::FLAGS);
            $expected = property_exists($case, 'canonical') ? $case->canonical : $case->json;
            $result = self::read(self::string($case, 'kind'), $json);
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson($result), $name);
            // SDK 自己写出的文本读回同一个值：64 位整数精确，整数值的浮点仍是浮点
            self::assertSame(
                Vectors::canonicalJson($expected),
                Vectors::canonicalJson(json_decode($result->toJson(), false, 512, JSON_THROW_ON_ERROR)),
                $name . ' (toJson)',
            );
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
                self::read(self::string($case, 'kind'), json_encode($case->json, self::FLAGS));
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
            $error = Error::fromJson(json_encode($case->json, self::FLAGS));
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson($error), $name);
            self::assertSame(Vectors::canonicalJson($expected), Vectors::canonicalJson(Error::fromWire($case->json)), $name);
            self::assertSame(
                Vectors::canonicalJson($expected),
                Vectors::canonicalJson(json_decode($error->toJson(), false, 512, JSON_THROW_ON_ERROR)),
                $name . ' (toJson)',
            );
        }
    }

    public function testInvalidErrorsAreRejected(): void
    {
        foreach (self::cases('errors', 'invalid') as $case) {
            $name = self::string($case, 'name');
            try {
                Error::fromJson(json_encode($case->json, self::FLAGS));
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

    public function testWireValuesFollowTheValueModelWithoutStdClass(): void
    {
        // 值与细节按值模型读（ADR-0236）：对象是 JsonObject，成员名原样（以 U+0000 开头、纯数字的都行），{} 与 [] 不混淆，
        // 64 位整数精确，1e400 是无穷；再写出时 JSON 值相同，无穷按值模型写成 "Infinity"
        $value = '{"\u0000a":1,"o":{},"l":[],"0":{"1":[{}]},"n":9007199254740993,"f":2.0,"z":-0.0}';
        $result = Result::fromJson('{"ok":true,"value":' . $value . ',"\u0000unknown":true}');
        $read = $result->valueOrThrow();
        self::assertInstanceOf(JsonObject::class, $read);
        self::assertSame(["\0a", 'o', 'l', '0', 'n', 'f', 'z'], $read->names());
        self::assertSame(1, $read["\0a"]);
        self::assertEquals(JsonObject::of(), $read['o']);
        self::assertSame([], $read['l']);
        self::assertSame(9007199254740993, $read['n']);
        self::assertSame('{"ok":true,"value":' . $value . '}', $result->toJson());
        self::assertSame([INF], Result::fromJson('{"ok":true,"value":[1e400]}')->valueOrThrow());
        self::assertSame('{"ok":true,"value":["Infinity"]}', Result::fromJson('{"ok":true,"value":[1e400]}')->toJson());

        $error = Error::fromJson(
            '{"code":"KERNEL_UNEXPECTED_001","message":"x","details":{"\u0000k":{"\u0000n":{}},"big":1e400,"0":[]},'
            . '"inner":{"code":"KERNEL_UNEXPECTED_001","message":"y","details":{"\u0000m":[{}]}},"\u0000extra":1}',
        );
        self::assertSame(["\0k", 'big', 0], array_keys($error->details));
        self::assertEquals(JsonObject::of(["\0n" => JsonObject::of()]), $error->details["\0k"]);
        self::assertSame(INF, $error->details['big']);
        self::assertSame(
            '{"code":"KERNEL_UNEXPECTED_001","message":"x","details":{"\u0000k":{"\u0000n":{}},"big":"Infinity","0":[]},'
            . '"inner":{"code":"KERNEL_UNEXPECTED_001","message":"y","details":{"\u0000m":[{}]}}}',
            $error->toJson(),
        );
        // 自己 json_decode 的结果照样接受
        self::assertTrue(Result::fromWire(json_decode('{"ok":true,"value":{"id":"o1"}}'))->isOk());
    }

    public function testJsonObjectsAreImmutableOrderedMaps(): void
    {
        $object = JsonObject::of(['b' => 1, "\0a" => null, '7' => 2]);
        self::assertSame(['b', "\0a", '7'], $object->names());
        self::assertSame(['b' => 1, "\0a" => null, '7' => 2], iterator_to_array($object));
        self::assertTrue($object->has("\0a"));
        self::assertFalse($object->has('missing'));
        self::assertNull($object->get('missing'));
        self::assertCount(3, $object);
        $changed = $object->with('b', 3)->with('c', 4);
        self::assertSame(['b', "\0a", '7', 'c'], $changed->names());
        self::assertSame(1, $object['b'], 'with() leaves the original unchanged');
        self::assertSame('{}', json_encode(JsonObject::of(), JSON_THROW_ON_ERROR));
        self::assertSame('{"7":2}', json_encode(JsonObject::of(['7' => 2]), JSON_THROW_ON_ERROR));
        $this->expectException(\LogicException::class);
        $object['b'] = 2;
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
