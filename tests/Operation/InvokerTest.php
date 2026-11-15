<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Codec;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Current;
use XiHan\Framework\Operation\Expose;
use XiHan\Framework\Operation\Invoker;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Operation\OperationId;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Operation\Schema;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;

/**
 * 操作的登记、经完整管道的调用、类型化调用与登记表的约束。
 */
final class InvokerTest extends TestCase
{
    public function testInvokeRunsThePipelineAndEncodesTheResponse(): void
    {
        $seen = null;
        $builder = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Observe', static function (Context $context, \Closure $next) use (&$seen): Result {
                $seen = Current::descriptor($context)?->contract->id;

                return $next($context);
            }),
            PipelineStage::Observe,
        );
        $invoker = self::invoker($builder);
        $context = Context::create(baggage: [BaggageKeys::CORRELATION => 'c1']);

        $response = $invoker->invoke($context, new Request('orders.create', '{"quantity":2}'));
        self::assertTrue($response->outcome->isOk());
        self::assertSame('{"id":"o-c1","quantity":2}', $response->payload);
        self::assertSame('orders.create', $seen);

        $failed = $invoker->invoke(Context::create(), new Request('orders.create', '{"quantity":0}'));
        self::assertSame('ORDERS_VALIDATION_001', $failed->outcome->error()?->code);
        self::assertNull($failed->payload);

        $missing = $invoker->invoke(Context::create(), new Request('orders.missing'))->outcome->error();
        self::assertNotNull($missing);
        self::assertSame(OperationErrors::NOT_FOUND, $missing->code);
        self::assertSame(['id' => 'orders.missing'], $missing->details);

        foreach (['null', '[1]', '{', '"x"', '{"quantity":"two"}'] as $payload) {
            $invalid = $invoker->invoke(Context::create(), new Request('orders.create', $payload))->outcome->error();
            self::assertNotNull($invalid, $payload);
            self::assertSame(OperationErrors::INVALID_PAYLOAD, $invalid->code, $payload);
            self::assertSame(OperationErrors::MESSAGE_INVALID_PAYLOAD, $invalid->messageKey);
        }
        $empty = $invoker->invoke(Context::create(), new Request('orders.create'));
        self::assertSame('ORDERS_VALIDATION_001', $empty->outcome->error()?->code, 'an empty payload is {}');
        $blank = $invoker->invoke(Context::create(), new Request('orders.create', " \n"));
        self::assertSame('ORDERS_VALIDATION_001', $blank->outcome->error()?->code, 'a blank payload is {}');
    }

    public function testTypedInvokeChecksTypes(): void
    {
        $invoker = self::invoker(null);
        $context = Context::create(baggage: [BaggageKeys::CORRELATION => 'c2']);
        $created = $invoker->invokeTyped($context, 'orders.create', new CreateOrder(3), OrderCreated::class)->valueOrThrow();
        self::assertInstanceOf(OrderCreated::class, $created);
        self::assertSame('o-c2', $created->id);
        self::assertSame(3, $created->quantity);

        $mismatch = $invoker->invokeTyped(Context::create(), 'orders.create', new CreateOrder(3), 'string');
        self::assertSame(OperationErrors::TYPE_MISMATCH, $mismatch->error()?->code);
        self::assertSame('orders.create', $mismatch->error()->details['id'] ?? null);
        $wrongRequest = $invoker->invokeTyped(Context::create(), 'orders.create', ['quantity' => 3], OrderCreated::class);
        self::assertSame(OperationErrors::TYPE_MISMATCH, $wrongRequest->error()?->code);
        $missing = $invoker->invokeTyped(Context::create(), 'orders.missing', new CreateOrder(3), OrderCreated::class);
        self::assertSame(OperationErrors::NOT_FOUND, $missing->error()?->code);
        $failed = $invoker->invokeTyped(Context::create(), 'orders.create', new CreateOrder(0), OrderCreated::class);
        self::assertSame('ORDERS_VALIDATION_001', $failed->error()?->code);
    }

    public function testRegistryRejectsDuplicatesAndBadIds(): void
    {
        foreach (['bad ID' => ['Orders.Create', null, null], 'permission and policy' => ['orders.create', 'p', 'q']] as $name => [$id, $permission, $policy]) {
            try {
                new Contract($id, permission: $permission, policy: $policy);
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
            }
        }
        $registry = new Registry();
        $handler = static fn (Context $context, array $request): Result => Result::ok($request);
        $registry->add(new Contract('orders.create'), $handler);
        try {
            $registry->add(new Contract('orders.create'), $handler);
            self::fail('a duplicate must be rejected');
        } catch (ConfigurationException $exception) {
            self::assertSame(OperationErrors::DUPLICATE, $exception->errorCode());
            self::assertSame("The operation 'orders.create' is already registered.", $exception->getMessage());
        }
        self::assertCount(1, $registry);
        self::assertSame(PipelineBuilder::APP_OWNER, $registry->list()[0]->owner);
        self::assertTrue(OperationId::isValid('order-lines.list'));
        self::assertFalse(OperationId::isValid('orders'));
        self::assertFalse(OperationId::isValid("orders.list\n"));
    }

    public function testRegistryCapacity(): void
    {
        $registry = new Registry();
        $handler = static fn (Context $context, array $request): Result => Result::ok($request);
        for ($index = 0; $index < Registry::CAPACITY; $index++) {
            $registry->add(new Contract('items.op' . $index), $handler);
        }
        self::assertCount(10000, $registry);
        try {
            $registry->add(new Contract('items.overflow'), $handler);
            self::fail('a full registry must reject');
        } catch (ConfigurationException $exception) {
            self::assertSame(OperationErrors::REGISTRY_FULL, $exception->errorCode());
            self::assertSame('The operation registry is full (10000 operations).', $exception->getMessage());
        }
        try {
            $registry->add(new Contract('items.op0'), $handler);
            self::fail('a duplicate in a full registry is still a duplicate');
        } catch (ConfigurationException $exception) {
            self::assertSame(OperationErrors::DUPLICATE, $exception->errorCode());
        }
    }

    public function testListIsSortedAndContractsKeepTheirShape(): void
    {
        $registry = new Registry();
        $handler = static fn (Context $context, array $request): Result => Result::ok($request);
        $registry->add(new Contract('orders.list'), $handler, owner: 'xihan.sales.orders');
        $registry->add(
            new Contract('invoices.get', Stability::Preview, [Expose::Http, Expose::Ai], permission: 'invoices.read'),
            $handler,
            schema: new Schema('{"type":"object"}', '{}'),
        );
        $registry->add(new Contract('order-lines.list', policy: 'sales'), $handler);
        self::assertSame(
            ['invoices.get', 'order-lines.list', 'orders.list'],
            array_map(static fn ($descriptor): string => $descriptor->contract->id, $registry->list()),
        );
        $invoices = $registry->find('invoices.get');
        self::assertNotNull($invoices);
        self::assertSame([Expose::Http, Expose::Ai], $invoices->contract->expose);
        self::assertSame('invoices.read', $invoices->contract->permission);
        self::assertSame(Stability::Preview, $invoices->contract->stability);
        self::assertSame('{"type":"object"}', $invoices->schema?->request);
        $orders = $registry->find('orders.list');
        self::assertNotNull($orders);
        self::assertSame('xihan.sales.orders', $orders->owner);
        self::assertSame(Stability::Stable, $orders->contract->stability);
        self::assertNull($registry->find('orders.missing'));

        $this->expectException(InvalidArgumentException::class);
        new Schema('[]', '{}');
    }

    public function testTheJsonCodecPassesArrays(): void
    {
        $registry = new Registry();
        $registry->add(
            new Contract('echoes.create'),
            static fn (Context $context, array $request): Result => Result::ok(['echo' => $request, 'tenant' => $context->tenant()]),
        );
        $invoker = new Invoker($registry);
        $response = $invoker->invoke(Context::create(baggage: [BaggageKeys::TENANT => 't1']), new Request('echoes.create', '{"text":"hi","tags":[]}'));
        self::assertSame('{"echo":{"text":"hi","tags":[]},"tenant":"t1"}', $response->payload);
        $typed = $invoker->invokeTyped(Context::create(), 'echoes.create', ['text' => 'x'], 'mixed');
        self::assertSame(['echo' => ['text' => 'x'], 'tenant' => null], $typed->valueOrThrow());
    }

    public function testEncodingFailuresAreUnexpected(): void
    {
        $registry = new Registry();
        $registry->add(new Contract('numbers.get'), static fn (Context $context, array $request): Result => Result::ok(['value' => INF]));
        $response = (new Invoker($registry))->invoke(Context::create(), new Request('numbers.get'));
        $error = $response->outcome->error();
        self::assertNotNull($error);
        self::assertSame(OperationErrors::UNEXPECTED, $error->code);
        self::assertStringStartsWith("The response of 'numbers.get' could not be encoded: ", $error->message());
        self::assertNull($response->payload);
    }

    public function testCallsRestoreTheItemsTheyReplace(): void
    {
        $registry = new Registry();
        /** @var Invoker|null $invoker 先登记处理函数、再建调用器，处理函数里经引用取到它 */
        $invoker = null;
        $registry->add(new Contract('inner.get'), static fn (Context $context, array $request): Result => Result::ok([
            'inner' => Current::descriptor($context)?->contract->id,
        ]));
        $registry->add(new Contract('outer.get'), static function (Context $context, array $request) use (&$invoker): Result {
            if (!$invoker instanceof Invoker) {
                throw new \LogicException('The invoker is built before the first call.');
            }
            $inner = $invoker->invoke($context, new Request('inner.get'));

            return Result::ok([
                'inner' => $inner->payload,
                'outer' => Current::descriptor($context)?->contract->id,
            ]);
        });
        $invoker = new Invoker($registry);
        $context = Context::create();
        $response = $invoker->invoke($context, new Request('outer.get'));
        self::assertSame('{"inner":"{\"inner\":\"inner.get\"}","outer":"outer.get"}', $response->payload);
        self::assertNull(Current::descriptor($context), 'the context is clean after the call');
        self::assertNull(Current::request($context));
        self::assertNull(Current::responsePayload($context));

        $typed = $invoker->invokeTyped($context, 'inner.get', [], 'mixed');
        self::assertTrue($typed->isOk());
        $again = $invoker->invoke($context, new Request('inner.get'));
        self::assertSame('{"inner":"inner.get"}', $again->payload, 'a typed call leaves no terminal behind');
    }

    public function testMiddlewareCanEndTheCallWithAResponse(): void
    {
        $registry = new Registry();
        $calls = 0;
        $registry->add(new Contract('orders.get'), static function (Context $context, array $request) use (&$calls): Result {
            $calls++;

            return Result::ok(['fresh' => true]);
        });
        $replay = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Replay', static function (Context $context, \Closure $next): Result {
                Current::setResponsePayload($context, '{"replayed":true}');

                return Result::success();
            }),
            PipelineStage::Idempotency,
        );
        $invoker = new Invoker($registry, $replay);
        $response = $invoker->invoke(Context::create(), new Request('orders.get'));
        self::assertSame('{"replayed":true}', $response->payload);
        self::assertSame(0, $calls);

        $typed = $invoker->invokeTyped(Context::create(), 'orders.get', [], 'mixed');
        self::assertSame(ErrorCode::UNINITIALIZED_RESULT, $typed->error()?->code);

        $denied = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Deny', static fn (Context $context, \Closure $next): Result => Result::fail(
                Error::denied('ORDERS_DENIED_001', 'orders.denied'),
            )),
            PipelineStage::Authorize,
        );
        $invoker = new Invoker($registry, $denied);
        self::assertSame('ORDERS_DENIED_001', $invoker->invokeTyped(Context::create(), 'orders.get', [], 'mixed')->error()?->code);
        self::assertSame('ORDERS_DENIED_001', $invoker->invoke(Context::create(), new Request('orders.get'))->outcome->error()?->code);
    }

    public function testCodecTypesMustExist(): void
    {
        $decode = static fn (array $json): Result => Result::ok(new CreateOrder(1));
        foreach ([['Missing\\Type', OrderCreated::class], [CreateOrder::class, 'Missing\\Type']] as [$request, $response]) {
            try {
                // @phpstan-ignore argument.type
                Codec::typed($request, $decode, $response);
                self::fail('unknown types must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('array', Codec::typed(CreateOrder::class, $decode, 'array')->responseType);
    }

    private static function invoker(?PipelineBuilder $builder): Invoker
    {
        $registry = new Registry();
        $registry->add(
            new Contract('orders.create', Stability::Stable, [Expose::Http]),
            static function (Context $context, CreateOrder $request): Result {
                if ($request->quantity <= 0) {
                    return Result::fail(Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['quantity' => $request->quantity]));
                }

                return Result::ok(new OrderCreated('o-' . $context->correlation(), $request->quantity));
            },
            Codec::typed(CreateOrder::class, CreateOrder::decode(...), OrderCreated::class),
            new Schema('{}', '{}'),
        );

        return new Invoker($registry, $builder);
    }
}
