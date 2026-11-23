<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Invocation;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\CancellationSource;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Invocation\DeadlineCancellation;
use XiHan\Framework\Invocation\TraceParent;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\KernelErrors;

/**
 * 调用上下文：追踪、Baggage、对象袋、截止时间与取消的翻译，以及取消、截止时间的派生。
 */
final class ContextTest extends TestCase
{
    public function testUnacceptableRegisteredValuesAreDropped(): void
    {
        $context = Context::create(baggage: [
            BaggageKeys::TENANT => '',
            BaggageKeys::PRINCIPAL => "p1\nforged",
            BaggageKeys::CORRELATION => 'c1',
            BaggageKeys::IDEMPOTENCY_KEY => "k\n1",
            'xihan.feature-flag' => "on\noff",
        ]);
        self::assertNull($context->tenant());
        self::assertNull($context->principal());
        self::assertSame('c1', $context->correlation());
        self::assertSame("k\n1", $context->idempotencyKey(), 'the idempotency key is left to the idempotency stage');
        self::assertSame("on\noff", $context->baggageValue('xihan.feature-flag'), 'unregistered keys never leave the process');

        // 程序内逐项派生给出不合的值是编程错误：抛出，源上下文不变
        $withTenant = $context->withBaggage(BaggageKeys::TENANT, 't1');
        self::assertSame('t1', $withTenant->tenant());
        self::assertSame("k\n2", $withTenant->withBaggage(BaggageKeys::IDEMPOTENCY_KEY, "k\n2")->idempotencyKey());
        try {
            $withTenant->withBaggage(BaggageKeys::TENANT, str_repeat('t', 257));
            self::fail('an unacceptable value must be rejected when deriving');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("'xihan.tenant'", $exception->getMessage());
        }
        self::assertSame('t1', $withTenant->tenant(), 'the source context is unchanged');
    }

    public function testCreateGeneratesTraceAndKeepsBaggage(): void
    {
        $context = Context::create(baggage: [BaggageKeys::TENANT => 't1']);
        self::assertSame(32, strlen($context->traceId()));
        self::assertSame(16, strlen($context->spanId()));
        self::assertSame('t1', $context->tenant());
        self::assertNull($context->principal());

        $derived = $context->withBaggage(BaggageKeys::CORRELATION, 'c1')->withBaggage('xihan.feature-flag', 'on');
        self::assertSame('c1', $derived->correlation());
        self::assertNull($context->correlation(), 'derived baggage must not leak back');
        self::assertSame('on', $derived->baggageValue('xihan.feature-flag'));
        self::assertNull($derived->withoutUnregisteredBaggage()->baggageValue('xihan.feature-flag'), 'unregistered keys are stripped');
        self::assertSame(['xihan.tenant' => 't1', 'xihan.correlation' => 'c1'], $derived->withoutUnregisteredBaggage()->baggage());

        $derived->items()->set('key', 1);
        self::assertTrue($context->items()->has('key'), 'items are shared between derived contexts');
        self::assertSame(1, $context->items()->get('key'));
        $context->items()->remove('key');
        self::assertFalse($derived->items()->has('key'));
        self::assertNull($derived->items()->get('key'));

        $parsed = TraceParent::tryParse($context->traceParent());
        self::assertNotNull($parsed);
        self::assertSame($context->traceId(), $parsed->traceId);
        self::assertSame($context->spanId(), $parsed->spanId);
        self::assertTrue($parsed->sampled);
        self::assertSame($context->traceId(), $derived->traceId(), 'derived contexts keep the trace');

        $context = Context::create(baggage: [
            BaggageKeys::PRINCIPAL => 'p1',
            BaggageKeys::IDEMPOTENCY_KEY => 'k1',
            BaggageKeys::LOCALE => 'zh-CN',
        ]);
        self::assertSame('p1', $context->principal());
        self::assertSame('k1', $context->idempotencyKey());
        self::assertSame('zh-CN', $context->locale());
    }

    public function testDeadlineCancelsAndTranslates(): void
    {
        $clock = new ManualClock();
        $context = Context::create(timeout: 0.02, clock: $clock);
        self::assertTrue($context->hasDeadline());
        self::assertSame(0.02, round((float) $context->remainingTime($clock->now()), 6));
        self::assertFalse($context->cancellation()->isRequested());
        self::assertNull($context->cancellationError());
        self::assertFalse($context->isExpired($clock->now()));

        $clock->advance(20);
        self::assertTrue($context->cancellation()->isRequested());
        self::assertSame(CancellationReason::DeadlineExceeded, $context->cancellation()->reason());
        $timeout = $context->cancellationError();
        self::assertNotNull($timeout);
        self::assertSame(Context::ERROR_CODE_DEADLINE_EXCEEDED, $timeout->code);
        self::assertSame(Context::MESSAGE_DEADLINE_EXCEEDED, $timeout->messageKey);
        self::assertSame('TIMEOUT', $timeout->kind());
        self::assertTrue($context->isExpired($clock->now()));
        self::assertSame(0.0, $context->remainingTime($clock->now()));

        $source = new CancellationSource();
        $cancelled = Context::create(cancellation: $source->cancellation());
        self::assertFalse($cancelled->cancellation()->isRequested());
        $source->cancel();
        self::assertTrue($source->isCancelled());
        $cancellation = $cancelled->cancellationError();
        self::assertNotNull($cancellation);
        self::assertSame(Context::ERROR_CODE_CANCELLED, $cancellation->code);
        self::assertSame(Context::MESSAGE_CANCELLED, $cancellation->messageKey);
        self::assertSame('CANCELLED', $cancellation->kind());

        $background = Context::create();
        self::assertNull($background->cancellationError(), 'no error without cancellation');
        self::assertFalse($background->hasDeadline());
        self::assertNull($background->deadline());
        self::assertNull($background->remainingTime($clock->now()));
        self::assertFalse($background->isExpired($clock->now()));
    }

    public function testTheSystemClockExpiresDeadlines(): void
    {
        $context = Context::create(timeout: 0.01);
        self::assertFalse($context->cancellation()->isRequested());
        usleep(20_000);
        self::assertSame(Context::ERROR_CODE_DEADLINE_EXCEEDED, $context->cancellationError()?->code);
        $remaining = $context->remainingTime($context->clock()->now());
        self::assertSame(0.0, $remaining);
    }

    public function testTheFirstCauseWins(): void
    {
        // 截止时间之前被外部取消：之后时钟越过截止时间也仍是取消
        $clock = new ManualClock();
        $source = new CancellationSource();
        $context = Context::create(cancellation: $source->cancellation(), timeout: 1.0, clock: $clock);
        $source->cancel();
        $clock->advance(2000);
        self::assertSame(CancellationReason::Cancelled, $context->cancellation()->reason());

        // 截止时间已到但还没人轮询时外部取消：截止令牌那时已经触发，归为超时（RFC-0003 规则 1）
        $clock = new ManualClock();
        $source = new CancellationSource();
        $context = Context::create(cancellation: $source->cancellation(), timeout: 1.0, clock: $clock);
        $clock->advance(1000);
        $source->cancel();
        self::assertSame(CancellationReason::DeadlineExceeded, $context->cancellation()->reason());

        // 父令牌已经取消时建上下文，立即是取消
        $context = Context::create(cancellation: $source->cancellation(), timeout: 1.0, clock: new ManualClock());
        self::assertSame(CancellationReason::Cancelled, $context->cancellation()->reason());
    }

    public function testCallbacksRunOnceAndSubscriptionsAreReleased(): void
    {
        $source = new CancellationSource();
        $calls = [];
        $source->cancellation()->subscribe(static function (CancellationReason $reason) use (&$calls): void {
            $calls[] = 'first:' . $reason->name;
        });
        $removed = $source->cancellation()->subscribe(static function () use (&$calls): void {
            $calls[] = 'removed';
        });
        $source->cancellation()->unsubscribe($removed);
        $source->cancel();
        $source->cancel();
        $source->cancellation()->subscribe(static function (CancellationReason $reason) use (&$calls): void {
            $calls[] = 'late:' . $reason->name;
        });
        self::assertSame(['first:Cancelled', 'late:Cancelled'], $calls);

        $clock = new ManualClock();
        $spy = new SpyCancellation(new CancellationSource());
        $context = Context::create(cancellation: $spy, timeout: 0.5, clock: $clock);
        self::assertSame(1, $spy->subscriptions);
        $fired = [];
        $context->cancellation()->subscribe(static function (CancellationReason $reason) use (&$fired): void {
            $fired[] = $reason;
        });
        $clock->advance(500);
        $token = $context->cancellation();
        self::assertInstanceOf(DeadlineCancellation::class, $token);
        $token->poll();
        self::assertSame([CancellationReason::DeadlineExceeded], $fired);
        unset($context, $token);
        gc_collect_cycles();
        self::assertSame(0, $spy->subscriptions, 'the deadline token unsubscribes from its parent when released');
    }

    public function testTraceParentParsing(): void
    {
        self::assertNotNull(TraceParent::tryParse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'));
        $invalid = [
            '',
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331',
            '00-00000000000000000000000000000000-b7ad6b7169203331-01',
            '00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01',
            '00-0AF7651916CD43DD8448EB211C80319C-b7ad6b7169203331-01',
            "00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\n",
        ];
        foreach ($invalid as $text) {
            self::assertNull(TraceParent::tryParse($text), sprintf('%s must not parse', $text));
        }
        $context = Context::create(traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00');
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId());
        self::assertFalse($context->isSampled());
        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00', $context->traceParent());

        $explicit = Context::create(traceId: '0af7651916cd43dd8448eb211c80319c', spanId: 'b7ad6b7169203331');
        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $explicit->traceParent());
        self::assertTrue($explicit->isSampled());
        self::assertStringEndsWith('-01', Context::create()->traceParent(), 'a root call writes 01');
        self::assertTrue(TraceParent::isTraceId(TraceParent::newTraceId()));
        self::assertTrue(TraceParent::isSpanId(TraceParent::newSpanId()));

        // 只看最低位判断采样，其余位不进上下文，写出时只写采样位
        $other = Context::create(traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-03');
        self::assertTrue($other->isSampled());
        self::assertStringEndsWith('-01', $other->traceParent());
        $unsampled = Context::create(traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-fe');
        self::assertFalse($unsampled->isSampled());
        self::assertStringEndsWith('-00', $unsampled->traceParent());

        // 不合法的 traceparent 当作没有给出：生成新的追踪，不报错
        $ignored = Context::create(traceParent: 'ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $ignored->traceId());
        self::assertSame(32, strlen($ignored->traceId()));
        self::assertTrue($ignored->isSampled());
    }

    public function testInvalidOptionsAreProgrammingErrors(): void
    {
        $cases = [
            'malformed baggage key' => static fn (): Context => Context::create()->withBaggage('tenant', 't1'),
            'malformed baggage option' => static fn (): Context => Context::create(baggage: ['Tenant' => 't1']),
            'deadline and timeout' => static fn (): Context => Context::create(deadline: new \DateTimeImmutable(), timeout: 1.0),
            'negative timeout' => static fn (): Context => Context::create(timeout: -1.0),
            'infinite timeout' => static fn (): Context => Context::create(timeout: INF),
            'traceparent and trace ID' => static fn (): Context => Context::create(
                traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
                traceId: '0af7651916cd43dd8448eb211c80319c',
            ),
            'invalid trace ID' => static fn (): Context => Context::create(traceId: 'ABC'),
            'zero span ID' => static fn (): Context => Context::create(spanId: '0000000000000000'),
        ];
        foreach ($cases as $name => $create) {
            try {
                $create();
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode(), $name);
            }
        }
    }

    public function testDeadlinesAreKeptInUtc(): void
    {
        $deadline = new \DateTimeImmutable('2026-11-16T08:00:00+08:00');
        $context = Context::create(deadline: $deadline, clock: new ManualClock('2026-11-15T23:59:59Z'));
        self::assertSame('2026-11-16T00:00:00+00:00', $context->deadline()?->format(DATE_ATOM));
        self::assertSame(1.0, $context->remainingTime(new \DateTimeImmutable('2026-11-15T23:59:59Z')));
        self::assertFalse($context->cancellation()->isRequested());
    }

    public function testWithCancellationLinksAnotherToken(): void
    {
        $outer = new CancellationSource();
        $context = Context::create(cancellation: $outer->cancellation(), baggage: [BaggageKeys::TENANT => 't1']);
        $inner = new CancellationSource();
        $derived = $context->withCancellation($inner->cancellation());
        self::assertFalse($derived->isCancelled());
        self::assertNull($derived->cancellationError());
        // 其余照旧：追踪、Baggage、截止时间、时钟与对象袋
        self::assertSame($context->traceParent(), $derived->traceParent());
        self::assertSame('t1', $derived->tenant());
        self::assertNull($derived->deadline());
        self::assertSame($context->clock(), $derived->clock());
        self::assertSame($context->items(), $derived->items());

        $fired = [];
        $derived->cancellation()->subscribe(static function (CancellationReason $reason) use (&$fired): void {
            $fired[] = $reason;
        });
        // 取消派生上下文不影响源上下文
        $inner->cancel();
        self::assertTrue($derived->isCancelled());
        self::assertSame([CancellationReason::Cancelled], $fired);
        self::assertSame(KernelErrors::CANCELLED, $derived->cancellationError()?->code);
        self::assertFalse($context->isCancelled(), 'cancelling a derived context leaves its source alone');

        // 源上下文取消，派生上下文连带取消
        $downstream = $context->withCancellation((new CancellationSource())->cancellation());
        self::assertFalse($downstream->isCancelled());
        $outer->cancel();
        self::assertTrue($context->isCancelled());
        self::assertTrue($downstream->isCancelled());
        self::assertSame(CancellationReason::Cancelled, $downstream->cancellation()->reason());

        // 从已经取消的上下文派生，或给一个已经取消的令牌：派生出来就已取消
        self::assertTrue($context->withCancellation((new CancellationSource())->cancellation())->isCancelled());
        self::assertTrue(Context::create()->withCancellation($inner->cancellation())->isCancelled());
    }

    public function testWithCancellationKeepsTheDeadlineAttribution(): void
    {
        // 截止时间已到但还没人轮询时另一个令牌取消：截止令牌那时已经触发，归为超时（RFC-0003 规则 1）
        $clock = new ManualClock();
        $context = Context::create(timeout: 1.0, clock: $clock);
        $inner = new CancellationSource();
        $derived = $context->withCancellation($inner->cancellation());
        self::assertEquals($context->deadline(), $derived->deadline());
        $clock->advance(1000);
        $inner->cancel();
        self::assertSame(CancellationReason::DeadlineExceeded, $derived->cancellation()->reason());
        self::assertSame(KernelErrors::DEADLINE_EXCEEDED, $derived->cancellationError()?->code);

        // 截止时间之前取消：之后时钟越过截止时间也仍是取消；源上下文到点才超时
        $clock = new ManualClock();
        $context = Context::create(timeout: 1.0, clock: $clock);
        $inner = new CancellationSource();
        $derived = $context->withCancellation($inner->cancellation());
        $inner->cancel();
        $clock->advance(2000);
        self::assertSame(CancellationReason::Cancelled, $derived->cancellation()->reason());
        self::assertSame(CancellationReason::DeadlineExceeded, $context->cancellation()->reason());

        // 谁也没取消：到了截止时间派生上下文同样超时，登记的回调在轮询时执行
        $clock = new ManualClock();
        $derived = Context::create(timeout: 1.0, clock: $clock)->withCancellation((new CancellationSource())->cancellation());
        $fired = [];
        $derived->cancellation()->subscribe(static function (CancellationReason $reason) use (&$fired): void {
            $fired[] = $reason;
        });
        self::assertFalse($derived->isCancelled());
        $clock->advance(1000);
        self::assertTrue($derived->isCancelled());
        self::assertSame([CancellationReason::DeadlineExceeded], $fired);
    }

    public function testDerivedTokensUnsubscribeWhenReleased(): void
    {
        $spy = new SpyCancellation(new CancellationSource());
        $extra = new SpyCancellation(new CancellationSource());
        $context = Context::create(cancellation: $spy);
        self::assertSame(0, $spy->subscriptions);
        $derived = $context->withCancellation($extra);
        self::assertSame(1, $spy->subscriptions);
        self::assertSame(1, $extra->subscriptions);
        $bounded = $derived->withDeadline(new \DateTimeImmutable('+1 hour'));
        unset($derived, $bounded);
        gc_collect_cycles();
        self::assertSame(0, $spy->subscriptions, 'a derived token unsubscribes from the source token when released');
        self::assertSame(0, $extra->subscriptions, 'a derived token unsubscribes from the linked token when released');
    }

    public function testWithDeadlineOnlyTightensTheDeadline(): void
    {
        $clock = new ManualClock('2026-11-16T00:00:00Z');
        $context = Context::create(timeout: 10.0, clock: $clock);
        $tighter = $context->withDeadline(new \DateTimeImmutable('2026-11-16T08:00:02+08:00'));
        self::assertSame('2026-11-16T00:00:02+00:00', $tighter->deadline()?->format(DATE_ATOM), 'deadlines are kept in UTC');
        self::assertSame(2.0, $tighter->remainingTime($clock->now()));
        self::assertSame(10.0, $context->remainingTime($clock->now()), 'the source context keeps its deadline');
        self::assertSame($context->traceParent(), $tighter->traceParent());
        self::assertSame($context->items(), $tighter->items());

        // 下游的截止时间不得晚于上游（RFC-0003 规则 2）：给得更晚或一样时保持原来的
        $later = $context->withDeadline(new \DateTimeImmutable('2026-11-16T00:01:00Z'));
        self::assertEquals($context->deadline(), $later->deadline());
        self::assertEquals($context->deadline(), $context->withDeadline(new \DateTimeImmutable('2026-11-16T00:00:10Z'))->deadline());

        $clock->advance(2000);
        self::assertTrue($tighter->isCancelled());
        self::assertSame(CancellationReason::DeadlineExceeded, $tighter->cancellation()->reason());
        self::assertSame(KernelErrors::DEADLINE_EXCEEDED, $tighter->cancellationError()?->code);
        self::assertTrue($tighter->isExpired($clock->now()));
        self::assertFalse($context->isCancelled(), 'a derived deadline does not cancel its source');
        self::assertFalse($later->isCancelled());
        $clock->advance(8000);
        self::assertTrue($context->isCancelled());
        self::assertSame(CancellationReason::DeadlineExceeded, $later->cancellation()->reason());

        // 没有截止时间的上下文派生出截止时间；源上下文的取消连带取消，截止时间之前是取消
        $source = new CancellationSource();
        $background = Context::create(cancellation: $source->cancellation(), clock: $clock);
        $bounded = $background->withDeadline($clock->now()->add(new \DateInterval('PT5S')));
        self::assertTrue($bounded->hasDeadline());
        self::assertFalse($background->hasDeadline());
        self::assertFalse($bounded->isCancelled());
        $source->cancel();
        self::assertSame(CancellationReason::Cancelled, $bounded->cancellation()->reason());

        // 已经到了的截止时间：派生出来就已超时
        $expired = Context::create(clock: $clock)->withDeadline($clock->now());
        self::assertSame(CancellationReason::DeadlineExceeded, $expired->cancellation()->reason());
        self::assertSame(0.0, $expired->remainingTime($clock->now()));
    }

    public function testSampledTellsWhetherAnUpstreamDecided(): void
    {
        // 链路的根：没有上游的采样决定（RFC-0003 规则 7），写出时按已采样
        $root = Context::create();
        self::assertNull($root->sampled());
        self::assertTrue($root->isSampled());
        self::assertStringEndsWith('-01', $root->traceParent());

        $sampled = Context::create(traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        self::assertTrue($sampled->sampled());
        self::assertTrue($sampled->isSampled());
        $unsampled = Context::create(traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00');
        self::assertFalse($unsampled->sampled());
        self::assertFalse($unsampled->isSampled());
        self::assertStringEndsWith('-00', $unsampled->traceParent());

        // 只给追踪 ID 的创建方按上游已采样处理；只给 Span ID 时追踪是新生成的，仍是链路的根
        self::assertTrue(Context::create(traceId: '0af7651916cd43dd8448eb211c80319c')->sampled());
        self::assertNull(Context::create(spanId: 'b7ad6b7169203331')->sampled());
        // 不合法的 traceparent 当作没有给出
        self::assertNull(Context::create(traceParent: 'ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')->sampled());

        // 派生上下文带着同一个决定
        self::assertNull($root->withBaggage(BaggageKeys::CORRELATION, 'c1')->sampled());
        self::assertFalse($unsampled->withBaggage(BaggageKeys::CORRELATION, 'c1')->sampled());
        self::assertFalse($unsampled->withoutUnregisteredBaggage()->sampled());
        self::assertFalse($unsampled->withCancellation((new CancellationSource())->cancellation())->sampled());
        self::assertFalse($unsampled->withDeadline(new \DateTimeImmutable('+1 hour'))->sampled());
        self::assertStringEndsWith('-00', $unsampled->withDeadline(new \DateTimeImmutable('+1 hour'))->traceParent());
    }
}
