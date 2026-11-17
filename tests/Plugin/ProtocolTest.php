<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationSource;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\HealthStatus;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Invocation\ManualClock;

/**
 * 协议消息与 SDK 类型的转换：错误（invocation.md §3，RFC-0001）与上下文（invocation.md §2，RFC-0003）。
 */
final class ProtocolTest extends TestCase
{
    public function testErrorsRoundTripThroughTheProtocol(): void
    {
        $error = Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['min' => 1, 'ratio' => 2.0, 'tags' => ['a']]);
        $error = Error::unexpected('ORDERS_UNEXPECTED_001', 'orders.failed', ['id' => 'o1'], $error);
        $encoded = Protocol::toProtoError($error);
        self::assertSame('ORDERS_UNEXPECTED_001', $encoded->getCode());
        self::assertSame('orders.failed', $encoded->getMessageKey());
        self::assertSame('{"id":"o1"}', $encoded->getDetails());
        self::assertSame('{"min":1,"ratio":2.0,"tags":["a"]}', $encoded->getInner()?->getDetails());
        // 没有目录解析得出时不写文案，宿主按键翻译
        self::assertSame('', $encoded->getText());

        $decoded = Protocol::fromProtoError($encoded);
        self::assertSame('ORDERS_UNEXPECTED_001', $decoded->code);
        self::assertSame('orders.failed', $decoded->messageKey);
        self::assertSame(['id' => 'o1'], $decoded->details);
        self::assertSame(2.0, $decoded->inner?->details['ratio']);
        self::assertSame('ORDERS_VALIDATION_001', $decoded->inner->code);
    }

    public function testKernelAndLiteralErrorsCarryText(): void
    {
        $encoded = Protocol::toProtoError(Error::literal('ORDERS_CONFLICT_001', 'stale'));
        self::assertSame('stale', $encoded->getText());
        self::assertSame('', $encoded->getMessageKey());
        $kernel = Protocol::toProtoError(Error::notFound('KERNEL_NOTFOUND_001', 'kernel.operation.unknown', ['id' => 'x.y']));
        self::assertSame("Operation 'x.y' is not registered.", $kernel->getText());
    }

    public function testInvalidRemoteErrorsAreKeptReadable(): void
    {
        $remote = (new V1\Error())->setCode('not a code')->setText('boom')->setDetails('{"a":1,"a":2}');
        $decoded = Protocol::fromProtoError($remote);
        self::assertSame('PLUGINS_UNEXPECTED_001', $decoded->code);
        self::assertSame('boom', $decoded->text);
        self::assertSame(['code' => 'not a code'], $decoded->details);

        self::assertTrue(Protocol::fromStatus((new V1\Status())->setOk(true), 'p')->isOk());
        self::assertSame('PLUGINS_UNEXPECTED_001', Protocol::fromStatus((new V1\Status())->setOk(false), 'p')->error()?->code);
        self::assertTrue(Protocol::toStatus(Result::success())->getOk());
    }

    public function testContextsAreRestoredFromInvocationMetadata(): void
    {
        $clock = new ManualClock();
        $metadata = (new V1\InvocationMetadata())
            ->setTraceParent('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')
            ->setDeadline('2026-11-17T10:00:00.1234567Z')
            ->setBaggage(['xihan.tenant' => 't1', 'xihan.correlation' => 'c1', 'xihan.unregistered' => 'x']);
        $context = Protocol::context($metadata, 30.0, (new CancellationSource())->cancellation(), $clock);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId());
        self::assertSame('2026-11-17T10:00:00.123456+00:00', $context->deadline()?->format('Y-m-d\TH:i:s.uP'));
        // 只收已登记的 Baggage 键
        self::assertSame(['xihan.tenant' => 't1', 'xihan.correlation' => 'c1'], $context->baggage());

        // 没有 deadline 时取 grpc-timeout
        $fallback = Protocol::context(new V1\InvocationMetadata(), 1.5, (new CancellationSource())->cancellation(), $clock);
        self::assertEqualsWithDelta(1.5, $fallback->remainingTime($clock->now()), 0.001);
        self::assertNull(Protocol::context(null, null, (new CancellationSource())->cancellation(), $clock)->deadline());
    }

    public function testDeadlinesFollowRfc3339(): void
    {
        self::assertSame('2026-11-17T02:00:00.000000Z', Protocol::formatDeadline(Protocol::parseDeadline('2026-11-17T10:00:00+08:00') ?? self::fail()));
        self::assertNotNull(Protocol::parseDeadline('2026-11-17t10:00:00z'));
        foreach (['', '2026-11-17', '2026-13-01T00:00:00Z', '2026-11-17T25:00:00Z', '2026-11-17T10:00:00', "2026-11-17T10:00:00Z\n"] as $text) {
            self::assertNull(Protocol::parseDeadline($text), $text);
        }
    }

    public function testContextsAreWrittenAsInvocationMetadata(): void
    {
        $context = Context::create(
            deadline: new \DateTimeImmutable('2026-11-17T10:00:00.5+00:00'),
            traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00',
            baggage: ['xihan.tenant' => 't1', 'xihan.other' => 'kept-in-process'],
        );
        $metadata = Protocol::metadata($context);
        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00', $metadata->getTraceParent());
        self::assertSame('2026-11-17T10:00:00.500000Z', $metadata->getDeadline());
        self::assertSame(['xihan.tenant' => 't1'], iterator_to_array($metadata->getBaggage()));
    }

    public function testEnvelopesAndHealthAreEncoded(): void
    {
        $proto = Protocol::toProtoEnvelope(new Envelope('echo.events', 'e1', 'urn:x', 'x', '{}', '', extensions: ['xihantenant' => 't1']));
        self::assertSame('1.0', $proto->getSpecVersion());
        self::assertSame('application/json', $proto->getDataContentType());
        $envelope = Protocol::envelope($proto);
        self::assertSame(['xihantenant' => 't1'], $envelope->extensions);
        self::assertSame('e1', $envelope->id);

        $health = Protocol::toProtoHealth(LifecycleState::Degraded, [new HealthItem('echo', HealthStatus::Unhealthy, 'down')], null);
        self::assertSame(5, $health->getState());
        $item = iterator_to_array($health->getItems())[0] ?? null;
        self::assertInstanceOf(V1\HealthItem::class, $item);
        self::assertSame(3, $item->getStatus());
        self::assertSame('down', $item->getDescription());
    }
}
