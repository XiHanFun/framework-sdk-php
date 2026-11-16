<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Codec;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Vectors;

/**
 * 回放 spec/conformance/vectors/operations/payload-reasons.json：载荷的解码结果与不合法时 reason 的路径（RFC-0011 规则 2）。
 */
final class PayloadReasonsTest extends TestCase
{
    public function testDecodingFollowsTheVector(): void
    {
        $cases = Vectors::object('operations/payload-reasons')->cases ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $invoker = self::invoker();
        foreach ($cases as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            $name = $case->name ?? null;
            $payload = $case->payload ?? null;
            self::assertIsString($name);
            self::assertIsString($payload);
            self::assertTrue(property_exists($case, 'reason'), $name);
            $operation = match ($case->request ?? null) {
                'counts' => 'counts.check',
                'order' => 'orders.check',
                'profile' => 'profiles.check',
                default => throw new \LogicException('Unknown request type in the vector.'),
            };
            $outcome = $invoker->invoke(Context::create(), new Request($operation, $payload))->outcome;
            if ($case->reason === null) {
                self::assertTrue($outcome->isOk(), $name . ': ' . $outcome);

                continue;
            }
            $error = $outcome->error();
            self::assertNotNull($error, $name);
            self::assertSame(OperationErrors::INVALID_PAYLOAD, $error->code, $name);
            self::assertSame('kernel.operation.invalid_payload', $error->messageKey, $name);
            self::assertSame(['id' => $operation, 'reason' => $case->reason], $error->details, $name);
        }
    }

    public function testDecodedValuesKeepTheirTypes(): void
    {
        $codec = Codec::typed(Profile::class, Profile::shape(), Profile::create(...), 'mixed');
        $profile = $codec->decode('profiles.check', '{"name":null,"ratio":5,"tags":["x",null],"inner":{"n":-0},'
            . '"meta":{"a":[],"b":{}},"count":9223372036854775807,"flag":true}')->valueOrThrow();
        self::assertNull($profile->name);
        self::assertSame(5.0, $profile->ratio, 'floats accept integer literals');
        self::assertSame(['x', null], $profile->tags);
        self::assertSame(['n' => 0], $profile->inner);
        self::assertEquals((object) ['a' => [], 'b' => new \stdClass()], $profile->meta, 'any JSON keeps {} and []');
        self::assertSame(PHP_INT_MAX, $profile->count);
        self::assertTrue($profile->flag);
        self::assertSame(INF, $codec->decode('p', '{"name":"a","ratio":1e400}')->valueOrThrow()->ratio);
        $nullable = Codec::typed(Profile::class, Shape::object(['count' => Shape::int64()->nullable()]), static fn (mixed $value): Profile => new Profile(null), 'mixed');
        self::assertTrue($nullable->decode('p', '{"count":null}')->isOk(), 'nullable() accepts null');
        self::assertTrue(Codec::typed(Counts::class, Counts::shape(), Counts::create(...), 'array')->decode('c', '')->isOk(), 'an empty payload is {}');
    }

    public function testPathsQuoteOnlyTheListedCharacters(): void
    {
        self::assertSame('$.a', JsonPath::member(JsonPath::ROOT, 'a'));
        self::assertSame('$.', JsonPath::member(JsonPath::ROOT, ''));
        self::assertSame("\$['a b']", JsonPath::member(JsonPath::ROOT, 'a b'));
        self::assertSame("\$['unit price'].x", JsonPath::member(JsonPath::member(JsonPath::ROOT, 'unit price'), 'x'));
        self::assertSame('$.lines[2]', JsonPath::index(JsonPath::member(JsonPath::ROOT, 'lines'), 2));
        self::assertSame('$.a:b', JsonPath::member(JsonPath::ROOT, 'a:b'));
    }

    private static function invoker(): Invoker
    {
        $registry = new Registry();
        $registry->add(
            new Contract('counts.check'),
            static fn (Context $context, Counts $request): Result => Result::ok($request->values),
            Codec::typed(Counts::class, Counts::shape(), Counts::create(...), 'array'),
        );
        $registry->add(
            new Contract('orders.check'),
            static fn (Context $context, Order $request): Result => Result::ok($request->quantities),
            Codec::typed(Order::class, Order::shape(), Order::create(...), 'array'),
        );
        $registry->add(
            new Contract('profiles.check'),
            static fn (Context $context, Profile $request): Result => Result::ok(['name' => $request->name]),
            Codec::typed(Profile::class, Profile::shape(), Profile::create(...), 'array'),
        );

        return new Invoker($registry);
    }
}
