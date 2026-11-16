<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\FailureException;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Maybe;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * Result、Maybe、TypedId 的语义与线上编码。
 */
final class ResultAndMaybeTest extends TestCase
{
    public function testResultWireEncoding(): void
    {
        $ok = Result::ok(['id' => 'o1']);
        self::assertSame('{"ok":true,"value":{"id":"o1"}}', json_encode($ok, JSON_THROW_ON_ERROR));

        $failed = Result::fail(Error::literal('ORDERS_CONFLICT_001', 'stale'));
        $json = json_encode($failed, JSON_THROW_ON_ERROR);
        self::assertSame('{"ok":false,"error":{"code":"ORDERS_CONFLICT_001","message":"stale"}}', $json);

        $decoded = Result::fromJson($json);
        self::assertTrue($decoded->isFailed());
        self::assertSame('ORDERS_CONFLICT_001', $decoded->error()?->code);

        // 按值模型读：对象是 JsonObject
        $value = Result::fromJson('{"ok":true,"value":{"id":"o1"}}', static fn (mixed $value): OrderId => new OrderId(
            $value instanceof JsonObject && is_string($value['id']) ? $value['id'] : '',
        ));
        self::assertTrue($value->valueOrThrow()->equals(new OrderId('o1')));

        self::assertSame('{"ok":true}', json_encode(Result::success(), JSON_THROW_ON_ERROR));
        self::assertSame(Unit::Value, Result::success()->valueOrThrow());
        self::assertSame(Unit::Value, Result::unitFromJson('{"ok":true}')->valueOrThrow());

        $maybe = Result::maybeFromJson('{"ok":true,"value":"x"}')->valueOrThrow();
        self::assertSame('x', $maybe->valueOrThrow());
        self::assertFalse(Result::maybeFromJson('{"ok":true,"value":null}')->valueOrThrow()->hasValue());
    }

    public function testInvalidResultWireEncodingsAreRejected(): void
    {
        $cases = [
            'no ok' => '{"value":1}',
            'ok type' => '{"ok":"yes","value":1}',
            'failure without error' => '{"ok":false}',
            'success without value' => '{"ok":true}',
            'success with null' => '{"ok":true,"value":null}',
            'bad error' => '{"ok":false,"error":{"code":"bad"}}',
            'not JSON' => '{',
            'not an object' => '1',
        ];
        foreach ($cases as $name => $json) {
            try {
                Result::fromJson($json);
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testResultComposition(): void
    {
        $doubled = Result::ok(21)->map(static fn (int $value): int => $value * 2);
        self::assertSame(42, $doubled->valueOrThrow());
        self::assertSame('Ok(42)', (string) $doubled);

        $failed = Result::fail(Error::literal('ORDERS_CONFLICT_001', 'stale'));
        self::assertTrue($failed->bind(static fn (mixed $value): Result => Result::ok(1))->isFailed());
        self::assertTrue($failed->map(static fn (mixed $value): int => 1)->isFailed());
        self::assertSame(0, $failed->valueOr(0));
        self::assertSame('Fail(ORDERS_CONFLICT_001: stale)', (string) $failed);
        self::assertSame('failed', $failed->match(
            static fn (mixed $value): string => 'ok',
            static fn (Error $error): string => 'failed',
        ));
        $incremented = Result::ok(1)->bind(static fn (int $value): Result => Result::ok($value + 1));
        self::assertSame(2, $incremented->valueOrThrow());
        self::assertSame(Unit::Value, Result::ok(1)->toUnit()->valueOrThrow());
        self::assertTrue($failed->toUnit()->isFailed());
        self::assertNull(Result::ok(1)->error());

        $exception = self::failure(static fn (): mixed => $failed->valueOrThrow());
        self::assertSame('ORDERS_CONFLICT_001', $exception->errorCode());
        self::assertSame($failed->error(), $exception->toError());
    }

    public function testSuccessValuesCannotBeNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Result::ok(null);
    }

    public function testMaybeWireEncoding(): void
    {
        self::assertSame('"x"', json_encode(Maybe::some('x'), JSON_THROW_ON_ERROR));
        self::assertSame('null', json_encode(Maybe::none(), JSON_THROW_ON_ERROR));
        self::assertFalse(Maybe::from(json_decode('null'))->hasValue());
        self::assertSame(5, Maybe::from(json_decode('5'))->valueOr(0));
        $missing = Error::literal('ORDERS_NOTFOUND_001', 'missing');
        self::assertTrue(Maybe::none()->toResult($missing)->isFailed());
        self::assertSame(5, Maybe::some(5)->toResult($missing)->valueOrThrow());
    }

    public function testMaybeComposition(): void
    {
        self::assertSame(10, Maybe::some(5)->map(static fn (int $value): int => $value * 2)->valueOrThrow());
        self::assertFalse(Maybe::some(5)->map(static fn (int $value): ?int => null)->hasValue());
        self::assertFalse(Maybe::none()->map(static fn (mixed $value): int => 1)->hasValue());
        $incremented = Maybe::some(5)->bind(static fn (int $value): Maybe => Maybe::some($value + 1));
        self::assertSame(6, $incremented->valueOrThrow());
        self::assertSame('none', Maybe::none()->match(
            static fn (mixed $value): string => 'some',
            static fn (): string => 'none',
        ));
        $exception = self::failure(static fn (): mixed => Maybe::none()->valueOrThrow());
        self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
        self::assertSame('The maybe has no value.', $exception->error()->message());
        $this->expectException(InvalidArgumentException::class);
        Maybe::some(null);
    }

    public function testTypedIdEncodesAsItsValue(): void
    {
        $id = new OrderId('o1');
        self::assertSame('"o1"', json_encode($id, JSON_THROW_ON_ERROR));
        self::assertSame('o1', (string) $id);
        self::assertTrue($id->equals(new OrderId('o1')));
        self::assertFalse($id->equals(new OrderId('o2')));
        self::assertFalse($id->equals(new CustomerId('o1')), 'the tag distinguishes the types');
    }

    /**
     * 执行并返回它抛出的 FailureException；没有抛出即测试失败。
     *
     * @param \Closure(): mixed $action
     */
    private static function failure(\Closure $action): FailureException
    {
        try {
            $action();
        } catch (FailureException $exception) {
            return $exception;
        }
        self::fail('a FailureException was expected');
    }
}
