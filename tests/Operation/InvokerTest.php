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
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Operation\Request;
use XiHan\Framework\Operation\Schema;
use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Pipeline\Placement;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\OperationId;
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

        $reasons = [
            'null' => '$',
            '[1]' => '$',
            '{' => '$',
            '"x"' => '$',
            '{"quantity":"two"}' => '$.quantity',
        ];
        foreach ($reasons as $payload => $reason) {
            $invalid = $invoker->invoke(Context::create(), new Request('orders.create', (string) $payload))->outcome->error();
            self::assertNotNull($invalid, (string) $payload);
            self::assertSame(OperationErrors::INVALID_PAYLOAD, $invalid->code, (string) $payload);
            self::assertSame('kernel.operation.invalid_payload', $invalid->messageKey);
            self::assertSame(['id' => 'orders.create', 'reason' => $reason], $invalid->details, (string) $payload);
        }
        self::assertSame("The payload of operation 'orders.create' is not valid: $.quantity", $invoker->invoke(
            Context::create(),
            new Request('orders.create', '{"quantity":"two"}'),
        )->outcome->error()?->message());
        $empty = $invoker->invoke(Context::create(), new Request('orders.create'));
        self::assertSame('ORDERS_VALIDATION_001', $empty->outcome->error()?->code, 'an empty payload is {}');
        // 只有空白不是 JSON（RFC-0001「JSON 文本」），只有空载荷等价于 {}
        $blank = $invoker->invoke(Context::create(), new Request('orders.create', " \n"))->outcome->error();
        self::assertSame(['id' => 'orders.create', 'reason' => '$'], $blank?->details);
        $duplicate = $invoker->invoke(Context::create(), new Request('orders.create', '{"quantity":1,"quantity":2}'));
        self::assertSame(['id' => 'orders.create', 'reason' => '$.quantity'], $duplicate->outcome->error()?->details);
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
        self::assertSame(['id' => 'orders.create'], $mismatch->error()->details, 'only the id, no language type names');
        self::assertSame(
            "Operation 'orders.create' was invoked with request or response types that do not match its registration.",
            $mismatch->error()->message(),
        );
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
        $handler = static fn (Context $context, JsonObject $request): Result => Result::ok($request);
        $registry->add(new Contract('orders.create'), $handler);
        try {
            $registry->add(new Contract('orders.create'), $handler);
            self::fail('a duplicate must be rejected');
        } catch (ConfigurationException $exception) {
            self::assertSame(OperationErrors::DUPLICATE, $exception->errorCode());
            self::assertSame("Operation 'orders.create' is already registered by 'app'.", $exception->getMessage());
            self::assertSame(['id' => 'orders.create', 'owner' => 'app'], $exception->toError()->details);
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
        $handler = static fn (Context $context, JsonObject $request): Result => Result::ok($request);
        for ($index = 0; $index < Registry::CAPACITY; $index++) {
            $registry->add(new Contract('items.op' . $index), $handler);
        }
        self::assertCount(10000, $registry);
        try {
            $registry->add(new Contract('items.overflow'), $handler);
            self::fail('a full registry must reject');
        } catch (ConfigurationException $exception) {
            self::assertSame(OperationErrors::REGISTRY_FULL, $exception->errorCode());
            self::assertSame('The operation registry has reached its capacity of 10000.', $exception->getMessage());
            self::assertSame(OperationErrors::MESSAGE_REGISTRY_FULL, $exception->toError()->messageKey);
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
        $handler = static fn (Context $context, JsonObject $request): Result => Result::ok($request);
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

    public function testTheJsonCodecReadsByTheValueModel(): void
    {
        $registry = new Registry();
        $registry->add(
            new Contract('echoes.create'),
            static fn (Context $context, JsonObject $request): Result => Result::ok(['echo' => $request, 'tenant' => $context->tenant()]),
        );
        $seen = null;
        $registry->add(new Contract('echoes.inspect'), static function (Context $context, JsonObject $request) use (&$seen): Result {
            $seen = $request;

            return Result::fail(Error::validation('ECHOES_VALIDATION_001', 'echoes.rejected', ['request' => $request]));
        });
        $invoker = new Invoker($registry);
        $response = $invoker->invoke(Context::create(baggage: [BaggageKeys::TENANT => 't1']), new Request('echoes.create', '{"text":"hi","tags":[]}'));
        self::assertSame('{"echo":{"text":"hi","tags":[]},"tenant":"t1"}', $response->payload);
        $typed = $invoker->invokeTyped(Context::create(), 'echoes.create', JsonObject::of(['text' => 'x']), 'mixed');
        self::assertEquals(['echo' => JsonObject::of(['text' => 'x']), 'tenant' => null], $typed->valueOrThrow());
        self::assertSame(OperationErrors::TYPE_MISMATCH, $invoker->invokeTyped(Context::create(), 'echoes.create', ['text' => 'x'], 'mixed')->error()?->code);

        // 无类型的路径同样按值模型读（ADR-0236）：{} 与 [] 不混淆，纯数字与以 U+0000 开头的成员名原样，64 位整数精确
        $payload = '{"o":{},"l":[],"0":{"1":[{}]},"\u0000x":1,"n":9007199254740993,"f":2.0}';
        $echo = $invoker->invoke(Context::create(), new Request('echoes.create', $payload));
        self::assertSame('{"echo":' . $payload . ',"tenant":null}', $echo->payload);

        // 上溢的数字读成无穷：放进错误细节时按值模型写成 "Infinity"，作为响应写不出
        $error = $invoker->invoke(Context::create(), new Request('echoes.inspect', '{"big":1e400,"tiny":1e-400}'))->outcome->error();
        self::assertInstanceOf(JsonObject::class, $seen);
        self::assertSame(INF, $seen['big']);
        self::assertSame(0.0, $seen->get('tiny'));
        self::assertSame(
            '{"code":"ECHOES_VALIDATION_001","message":"echoes.rejected (request={\"big\":Infinity,\"tiny\":0})",'
            . '"messageKey":"echoes.rejected","details":{"request":{"big":"Infinity","tiny":0.0}}}',
            $error?->toJson(),
        );
        $overflow = $invoker->invoke(Context::create(), new Request('echoes.create', '{"big":1e400}'));
        self::assertSame('kernel.invocation.unhandled_exception', $overflow->outcome->error()?->messageKey);
        self::assertNull($overflow->payload);
    }

    public function testEncodingFailuresAreUnexpected(): void
    {
        $registry = new Registry();
        $registry->add(new Contract('names.get'), static fn (Context $context, JsonObject $request): Result => Result::ok(['value' => "\xff"]));
        $registry->add(new Contract('numbers.get'), static fn (Context $context, JsonObject $request): Result => Result::ok(['ratio' => NAN]));
        $registry->add(new Contract('floats.get'), static fn (Context $context, JsonObject $request): Result => Result::ok([2.0, -0.0, 1e17]));
        $registry->add(new Contract('broken.get'), static fn (Context $context, CreateOrder $request): Result => Result::ok([]), Codec::typed(
            CreateOrder::class,
            CreateOrder::shape(),
            CreateOrder::create(...),
            'array',
            static fn (mixed $response): mixed => throw new ConfigurationException('The encoder is not configured.', 'ORDERS_UNEXPECTED_001'),
        ));
        $seen = [];
        $egress = (new PipelineBuilder())->useAt(new ClosureMiddleware('Egress', static function (Context $context, \Closure $next) use (&$seen): Result {
            $outcome = $next($context);
            // 管道结束之后才编码：中间件看到的是响应对象，还没有载荷（RFC-0011 规则 10「编码」）
            $seen[] = [Current::responseObject($context), Current::responsePayload($context)];

            return $outcome;
        }), PipelineStage::Egress);
        $invoker = new Invoker($registry, $egress);
        // 写不出（非法 UTF-8、NaN 与无穷）：kernel.invocation.unhandled_exception，不抛给调用方，没有载荷
        foreach (['names.get', 'numbers.get'] as $id) {
            $response = $invoker->invoke(Context::create(), new Request($id));
            $error = $response->outcome->error();
            self::assertNotNull($error, $id);
            self::assertSame(OperationErrors::UNEXPECTED, $error->code, $id);
            self::assertSame('kernel.invocation.unhandled_exception', $error->messageKey, $id);
            self::assertSame([], $error->details, $id);
            self::assertNull($error->inner, $id);
            self::assertNull($response->payload, $id);
        }
        self::assertCount(2, $seen);
        self::assertSame([['value' => "\xff"], null], $seen[0]);
        /** @var array{array{ratio: float}, ?string} $numbers */
        $numbers = $seen[1];
        self::assertNan($numbers[0]['ratio']);
        self::assertNull($numbers[1]);
        // 编码函数抛出框架异常：它的错误放进 inner
        $broken = $invoker->invoke(Context::create(), new Request('broken.get'))->outcome->error();
        self::assertSame('kernel.invocation.unhandled_exception', $broken?->messageKey);
        self::assertSame('ORDERS_UNEXPECTED_001', $broken->inner?->code);
        // 整数值的浮点写成浮点
        self::assertSame('[2.0,-0.0,1.0e+17]', $invoker->invoke(Context::create(), new Request('floats.get'))->payload);
    }

    public function testCallsRestoreTheItemsTheyReplace(): void
    {
        $registry = new Registry();
        /** @var Invoker|null $invoker 先登记处理函数、再建调用器，处理函数里经引用取到它 */
        $invoker = null;
        $registry->add(new Contract('inner.get'), static fn (Context $context, JsonObject $request): Result => Result::ok([
            'inner' => Current::descriptor($context)?->contract->id,
        ]));
        $registry->add(new Contract('outer.get'), static function (Context $context, JsonObject $request) use (&$invoker): Result {
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

        $typed = $invoker->invokeTyped($context, 'inner.get', JsonObject::of(), 'mixed');
        self::assertTrue($typed->isOk());
        $again = $invoker->invoke($context, new Request('inner.get'));
        self::assertSame('{"inner":"inner.get"}', $again->payload, 'a typed call leaves no terminal behind');
    }

    public function testMiddlewareCanEndTheCallWithAResponse(): void
    {
        $registry = new Registry();
        $calls = 0;
        $registry->add(new Contract('orders.get'), static function (Context $context, JsonObject $request) use (&$calls): Result {
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

        // 类型化调用回放不了记录的载荷：没有响应对象，按规则 9 报 no_response
        $typed = $invoker->invokeTyped(Context::create(), 'orders.get', JsonObject::of(), 'mixed')->error();
        self::assertNotNull($typed);
        self::assertSame(OperationErrors::UNEXPECTED, $typed->code);
        self::assertSame('kernel.operation.no_response', $typed->messageKey);
        self::assertSame(['id' => 'orders.get'], $typed->details);

        // 中间件以成功短路、也没有交出响应：两种入口都报 no_response，不返回成功的空载荷
        $silent = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Silent', static fn (Context $context, \Closure $next): Result => Result::success()),
            PipelineStage::Authorize,
        );
        $invoker = new Invoker($registry, $silent);
        $response = $invoker->invoke(Context::create(), new Request('orders.get'));
        self::assertSame('kernel.operation.no_response', $response->outcome->error()?->messageKey);
        self::assertNull($response->payload);
        self::assertSame("Operation 'orders.get' completed without producing a response.", $response->outcome->error()->message());
        self::assertSame('kernel.operation.no_response', $invoker->invokeTyped(Context::create(), 'orders.get', JsonObject::of(), 'mixed')->error()?->messageKey);

        $denied = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Deny', static fn (Context $context, \Closure $next): Result => Result::fail(
                Error::denied('ORDERS_DENIED_001', 'orders.denied'),
            )),
            PipelineStage::Authorize,
        );
        $invoker = new Invoker($registry, $denied);
        self::assertSame('ORDERS_DENIED_001', $invoker->invokeTyped(Context::create(), 'orders.get', JsonObject::of(), 'mixed')->error()?->code);
        self::assertSame('ORDERS_DENIED_001', $invoker->invoke(Context::create(), new Request('orders.get'))->outcome->error()?->code);
    }

    public function testExpiredDeadlinesEndTheCallInTheResilienceStage(): void
    {
        $registry = new Registry();
        $calls = [];
        $registry->add(new Contract('orders.get'), static function (Context $context, JsonObject $request) use (&$calls): Result {
            $calls[] = 'handler';

            return Result::ok(['id' => 'o1']);
        });
        $record = static function (string $name) use (&$calls): ClosureMiddleware {
            return new ClosureMiddleware($name, static function (Context $context, \Closure $next) use (&$calls, $name): Result {
                $calls[] = $name;

                return $next($context);
            });
        };
        $builder = (new PipelineBuilder())
            ->useAt($record('Observe'), PipelineStage::Observe)
            ->useAt($record('EarlyResilience'), PipelineStage::Resilience, Placement::Beginning)
            ->useAt($record('Authorize'), PipelineStage::Authorize);
        $invoker = new Invoker($registry, $builder);
        $clock = new \XiHan\Framework\Tests\Invocation\ManualClock();
        // 构造上下文时不判断截止时间：已过期的上下文照样建得出来
        $expired = Context::create(timeout: 0.0, clock: $clock);
        $errors = [
            $invoker->invoke($expired, new Request('orders.get'))->outcome->error(),
            $invoker->invokeTyped($expired, 'orders.get', JsonObject::of(), 'mixed')->error(),
        ];
        foreach ($errors as $error) {
            self::assertNotNull($error);
            self::assertSame('KERNEL_TIMEOUT_001', $error->code);
            self::assertSame('kernel.invocation.deadline_before_start', $error->messageKey);
            self::assertSame([], $error->details);
        }
        // 截止时间在 Resilience 阶段的 Beginning 上按归属 xihan.kernel.core 核对：之前的阶段照常执行，
        // 同位置上归属 app 排在它前面，之后的中间件与处理函数不执行（RFC-0011 规则 10）
        self::assertSame(['Observe', 'EarlyResilience', 'Observe', 'EarlyResilience'], $calls);

        $calls = [];
        $live = Context::create(timeout: 1.0, clock: $clock);
        self::assertSame('{"id":"o1"}', $invoker->invoke($live, new Request('orders.get'))->payload);
        self::assertSame(['Observe', 'EarlyResilience', 'Authorize', 'handler'], $calls);
        self::assertSame('KERNEL_NOTFOUND_001', $invoker->invoke($expired, new Request('orders.missing'))->outcome->error()?->code);
        // 截止时间先于解码：载荷不合法的过期调用报超时
        self::assertSame('KERNEL_TIMEOUT_001', $invoker->invoke($expired, new Request('orders.get', '['))->outcome->error()?->code);
    }

    public function testDecodingHappensAtTheStartOfTheValidateStage(): void
    {
        $registry = new Registry();
        $registry->add(
            new Contract('orders.create'),
            static fn (Context $context, CreateOrder $request): Result => Result::ok(new OrderCreated('o1', $request->quantity)),
            Codec::typed(CreateOrder::class, CreateOrder::shape(), CreateOrder::create(...), OrderCreated::class),
        );
        $seen = [];
        $peek = static function (string $name) use (&$seen): ClosureMiddleware {
            return new ClosureMiddleware($name, static function (Context $context, \Closure $next) use (&$seen, $name): Result {
                $seen[$name] = Current::requestObject($context);

                return $next($context);
            });
        };
        $builder = (new PipelineBuilder())
            ->useAt($peek('BeforeDecoding'), PipelineStage::Validate, Placement::Beginning)
            ->useAt($peek('AfterDecoding'), PipelineStage::Validate)
            ->useAt($peek('Authorize'), PipelineStage::Authorize);
        $invoker = new Invoker($registry, $builder);
        self::assertSame('{"id":"o1","quantity":4}', $invoker->invoke(Context::create(), new Request('orders.create', '{"quantity":4}'))->payload);
        self::assertNull($seen['Authorize'], 'Authorize runs before the payload is read');
        self::assertNull($seen['BeforeDecoding'], 'app sorts before xihan.kernel.core at the Beginning of Validate');
        self::assertEquals(new CreateOrder(4), $seen['AfterDecoding']);

        // 解码失败时后面的中间件不执行
        $seen = [];
        $invalid = $invoker->invoke(Context::create(), new Request('orders.create', '{"quantity":"x"}'))->outcome->error();
        self::assertSame(['id' => 'orders.create', 'reason' => '$.quantity'], $invalid?->details);
        self::assertSame(['Authorize', 'BeforeDecoding'], array_keys($seen));

        // 类型化调用跳过解码：请求对象就是调用方给的
        $seen = [];
        $invoker->invokeTyped(Context::create(), 'orders.create', new CreateOrder(7), OrderCreated::class);
        /** @var array<string, mixed> $seen 由中间件经引用写入 */
        self::assertEquals(new CreateOrder(7), $seen['Authorize'] ?? null);
    }

    public function testOnlyExceptionsAfterTheObserveStepAreTranslated(): void
    {
        $registry = new Registry();
        $registry->add(new Contract('orders.get'), static fn (Context $context, JsonObject $request): Result => Result::ok([]));
        $crash = static fn (string $name): ClosureMiddleware => new ClosureMiddleware(
            $name,
            static fn (Context $context, \Closure $next): Result => throw new \RuntimeException($name),
        );
        $outside = [
            'ingress' => (new PipelineBuilder())->useAt($crash('Ingress'), PipelineStage::Ingress),
            'observe beginning, owner before the kernel' => (new PipelineBuilder())
                ->useAt($crash('Early'), PipelineStage::Observe, Placement::Beginning, 'app'),
        ];
        foreach ($outside as $name => $builder) {
            try {
                (new Invoker($registry, $builder))->invoke(Context::create(), new Request('orders.get'));
                self::fail(sprintf('%s: exceptions before the Observe step reach the caller', $name));
            } catch (\RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage(), $name);
            }
        }
        $inside = [
            'observe beginning, owner after the kernel' => (new PipelineBuilder())
                ->useAt($crash('Late'), PipelineStage::Observe, Placement::Beginning, 'zeta.plugin'),
            'observe end' => (new PipelineBuilder())->useAt($crash('End'), PipelineStage::Observe),
        ];
        foreach ($inside as $name => $builder) {
            $error = (new Invoker($registry, $builder))->invoke(Context::create(), new Request('orders.get'))->outcome->error();
            self::assertSame('kernel.invocation.unhandled_exception', $error?->messageKey, $name);
        }
        // 传入的构建器不变：内置步骤登记在副本上
        $builder = new PipelineBuilder();
        new Invoker($registry, $builder);
        self::assertSame('', $builder->dump());
        try {
            new Invoker($registry, (new PipelineBuilder())->useAt(
                new ClosureMiddleware(Invoker::DEADLINE_MIDDLEWARE, static fn (Context $context, \Closure $next): Result => $next($context)),
                PipelineStage::Resilience,
            ));
            self::fail('a middleware with the name of a built-in step is a duplicate');
        } catch (ConfigurationException $exception) {
            self::assertSame(PipelineBuilder::ERROR_CODE_REGISTRATION, $exception->errorCode());
        }
    }

    public function testThrownExceptionsBecomeUnexpectedErrors(): void
    {
        $registry = new Registry();
        $registry->add(new Contract('orders.crash'), static fn (Context $context, JsonObject $request): Result => throw new \RuntimeException('boom'));
        $registry->add(new Contract('orders.misconfigured'), static fn (Context $context, JsonObject $request): Result => throw new ConfigurationException(
            'The orders feature is not configured.',
            'ORDERS_UNEXPECTED_001',
        ));
        // 故意登记不返回 Result 的处理函数，核对运行期的类型边界
        // @phpstan-ignore argument.type
        $registry->add(new Contract('orders.untyped'), static fn (Context $context, JsonObject $request): mixed => 'not a result');
        $seen = [];
        $observe = (new PipelineBuilder())->useAt(
            new ClosureMiddleware('Observe', static function (Context $context, \Closure $next) use (&$seen): Result {
                try {
                    return $next($context);
                } catch (\Throwable $exception) {
                    // 应用要记日志时在 Observe 阶段接住原始异常，再交给调用器翻译
                    $seen[] = $exception::class;

                    throw $exception;
                }
            }),
            PipelineStage::Observe,
        );
        $invoker = new Invoker($registry, $observe);
        $context = Context::create();

        $crash = $invoker->invoke($context, new Request('orders.crash'))->outcome->error();
        self::assertNotNull($crash);
        self::assertSame(OperationErrors::UNEXPECTED, $crash->code);
        self::assertSame('kernel.invocation.unhandled_exception', $crash->messageKey);
        self::assertSame('An unhandled exception occurred during the invocation.', $crash->message());
        self::assertNull($crash->inner, 'only framework exceptions are carried as the inner error');

        $misconfigured = $invoker->invokeTyped($context, 'orders.misconfigured', JsonObject::of(), 'mixed')->error();
        self::assertSame('ORDERS_UNEXPECTED_001', $misconfigured?->inner?->code);

        $untyped = $invoker->invoke($context, new Request('orders.untyped'))->outcome->error();
        self::assertSame(OperationErrors::UNEXPECTED, $untyped?->code, 'a handler that does not return a result is a defect');
        self::assertSame([\RuntimeException::class, ConfigurationException::class, \TypeError::class], $seen);
        self::assertNull(Current::descriptor($context), 'the item bag is restored after a thrown exception');
    }

    public function testCodecTypesMustExist(): void
    {
        $create = static fn (mixed $value): CreateOrder => new CreateOrder(1);
        foreach ([['Missing\\Type', OrderCreated::class], [CreateOrder::class, 'Missing\\Type']] as [$request, $response]) {
            try {
                // @phpstan-ignore argument.type
                Codec::typed($request, CreateOrder::shape(), $create, $response);
                self::fail('unknown types must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('array', Codec::typed(CreateOrder::class, CreateOrder::shape(), $create, 'array')->responseType);
        $this->expectException(InvalidArgumentException::class);
        Codec::typed(CreateOrder::class, Shape::listOf(Shape::int32()), $create, 'array');
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
            Codec::typed(CreateOrder::class, CreateOrder::shape(), CreateOrder::create(...), OrderCreated::class),
            new Schema('{}', '{}'),
        );

        return new Invoker($registry, $builder);
    }
}
