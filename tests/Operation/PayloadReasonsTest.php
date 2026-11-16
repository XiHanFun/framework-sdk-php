<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Codec;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\JsonPath;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Result;

/**
 * 回放 spec/conformance/vectors/operations/payload-reasons.json：载荷不合法时 reason 的路径。
 */
final class PayloadReasonsTest extends TestCase
{
    public function testReasonsFollowTheVector(): void
    {
        $vector = self::vector();
        $cases = $vector['cases'] ?? null;
        self::assertIsArray($cases);
        self::assertNotEmpty($cases);
        $invoker = self::invoker();
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $name = $case['name'] ?? null;
            $payload = $case['payload'] ?? null;
            self::assertIsString($name);
            self::assertIsString($payload);
            $error = self::invalid($invoker, self::operationOf($case), $payload, $name);
            self::assertSame($case['reason'] ?? null, $error->details['reason'] ?? null, $name);
        }
    }

    public function testSyntaxErrorReasonsStartAtTheRoot(): void
    {
        $syntaxErrors = self::vector()['syntaxErrors'] ?? null;
        self::assertIsArray($syntaxErrors);
        self::assertNotEmpty($syntaxErrors);
        $invoker = self::invoker();
        foreach ($syntaxErrors as $case) {
            self::assertIsArray($case);
            $payload = $case['payload'] ?? null;
            self::assertIsString($payload);
            $reason = self::invalid($invoker, self::operationOf($case), $payload, $payload)->details['reason'] ?? null;
            self::assertIsString($reason);
            self::assertStringStartsWith('$', $reason, $payload);
        }
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

    private static function invalid(Invoker $invoker, string $operation, string $payload, string $name): Error
    {
        $error = $invoker->invoke(Context::create(), new Request($operation, $payload))->outcome->error();
        self::assertNotNull($error, $name);
        self::assertSame(OperationErrors::INVALID_PAYLOAD, $error->code, $name);
        self::assertSame('kernel.operation.invalid_payload', $error->messageKey, $name);

        return $error;
    }

    /**
     * @param array<array-key, mixed> $case
     */
    private static function operationOf(array $case): string
    {
        return match ($case['request'] ?? null) {
            'counts' => 'counts.check',
            'order' => 'orders.check',
            default => throw new \LogicException('Unknown request type in the vector.'),
        };
    }

    private static function invoker(): Invoker
    {
        $registry = new Registry();
        $registry->add(
            new Contract('counts.check'),
            static fn (Context $context, Counts $request): Result => Result::ok($request->values),
            Codec::typed(Counts::class, Counts::decode(...), 'array'),
        );
        $registry->add(
            new Contract('orders.check'),
            static fn (Context $context, Order $request): Result => Result::ok($request->quantities),
            Codec::typed(Order::class, Order::decode(...), 'array'),
        );

        return new Invoker($registry);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function vector(): array
    {
        $path = dirname(__DIR__, 2) . '/spec/conformance/vectors/operations/payload-reasons.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($json, sprintf("The conformance vector '%s' is missing.", $path));
        $vector = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vector);

        return $vector;
    }
}
