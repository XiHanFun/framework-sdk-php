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
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageScope;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Tests\Invocation\ManualClock;

/**
 * 协议消息与 SDK 类型的转换：错误的写出与读入（invocation.md §3，RFC-0001）、上下文的还原与回调的元数据
 * （invocation.md §2、§5，RFC-0003）、信封与健康。
 */
final class ProtocolTest extends TestCase
{
    public function testErrorsAreWrittenWithoutRenderingTheirKeys(): void
    {
        // 进程默认区域能解析这个键，写出时照样不渲染：由宿主按目录翻译成调用方的语言
        MessageScope::setDefault(new MessageCatalog(['en-US' => ['orders.failed' => 'Order {id} failed.']]), 'en-US');
        try {
            $inner = Error::validation('ORDERS_VALIDATION_001', 'orders.quantity.invalid', ['min' => 1, 'ratio' => 2.0, 'tags' => ['a']]);
            $error = Error::unexpected('ORDERS_UNEXPECTED_001', 'orders.failed', ['id' => 'o1'], $inner)->withText('Order o1 failed.');
            $encoded = Protocol::toProtoError($error);
            self::assertSame('ORDERS_UNEXPECTED_001', $encoded->getCode());
            self::assertSame('orders.failed', $encoded->getMessageKey());
            self::assertSame('', $encoded->getText());
            self::assertSame('{"id":"o1"}', $encoded->getDetails());
            self::assertSame('{"min":1,"ratio":2.0,"tags":["a"]}', $encoded->getInner()?->getDetails());
        } finally {
            MessageScope::setDefault(null, 'en-US');
        }
        // 只有自由文本错误写 text；没有细节时 details 为空
        $literal = Protocol::toProtoError(Error::literal('ORDERS_CONFLICT_001', 'stale'));
        self::assertSame('stale', $literal->getText());
        self::assertSame('', $literal->getMessageKey());
        self::assertSame('', $literal->getDetails());
        $kernel = Protocol::toProtoError(Error::notFound('KERNEL_NOTFOUND_001', 'kernel.operation.unknown', ['id' => 'x.y']));
        self::assertSame('', $kernel->getText());
    }

    public function testErrorsFromTheHostAreReadPerTheRules(): void
    {
        $decoded = Protocol::fromProtoError((new V1\Error())
            ->setCode('ORDERS_UNEXPECTED_001')
            ->setMessageKey('orders.failed')
            ->setText('rendered')
            ->setDetails('{"id":"o1"}')
            ->setInner((new V1\Error())->setCode('ORDERS_VALIDATION_001')->setMessageKey('orders.quantity.invalid')->setDetails('{"min":1}')), 'p');
        self::assertSame('orders.failed', $decoded->messageKey);
        // 带键的错误按键处理，不取 text
        self::assertNull($decoded->text);
        self::assertSame(['id' => 'o1'], $decoded->details);
        self::assertSame(['min' => 1], $decoded->inner?->details);

        // 码不合文法：整个错误换成 plugins.process.failed，原来的细节与内部错误都不要
        $invalid = Protocol::fromProtoError((new V1\Error())->setCode('not a code')->setText('boom')->setDetails('{"a":1}'), 'p');
        self::assertSame('PLUGINS_UNEXPECTED_001', $invalid->code);
        self::assertSame('plugins.process.failed', $invalid->messageKey);
        self::assertSame(['id' => 'p', 'phase' => 'invoke', 'reason' => "invalid error code 'not a code'"], $invalid->details);
        self::assertNull($invalid->inner);

        // 键不合文法：自由文本错误，text 为空时取码；details 为空、不合 JSON、重复成员或不是对象都当作没有
        foreach (['', '{"a":', '{"a":1,"a":2}', '[1]', '"x"', 'null'] as $details) {
            $literal = Protocol::fromProtoError((new V1\Error())->setCode('ORDERS_CONFLICT_001')->setMessageKey('Not A Key')->setDetails($details), 'p');
            self::assertNull($literal->messageKey, $details);
            self::assertSame('ORDERS_CONFLICT_001', $literal->text, $details);
            self::assertSame([], $literal->details, $details);
        }
        self::assertSame('stale', Protocol::fromProtoError((new V1\Error())->setCode('ORDERS_CONFLICT_001')->setText('stale'), 'p')->text);

        self::assertTrue(Protocol::fromStatus((new V1\Status())->setOk(true), 'p', 'orders.get')->isOk());
        $missing = Protocol::fromStatus((new V1\Status())->setOk(false), 'p', 'orders.get')->error();
        self::assertSame(['id' => 'p', 'phase' => 'orders.get', 'reason' => 'the host reported a failure without an error'], $missing?->details);
        self::assertSame('PLUGINS_UNEXPECTED_001', Protocol::fromStatus(null, 'p', 'publish')->error()?->code);
        self::assertTrue(Protocol::toStatus(Result::success())->getOk());
    }

    public function testContextsAreRestoredFromInvocationMetadata(): void
    {
        $clock = new ManualClock();
        $metadata = (new V1\InvocationMetadata())
            ->setTraceParent('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')
            ->setTraceState('vendor=opaque')
            ->setDeadline('2026-11-17T10:00:00.1234567+00:00')
            ->setBaggage(['xihan.tenant' => 't1', 'xihan.principal' => 'alice', 'xihan.unregistered' => 'x']);
        $context = Protocol::context($metadata, null, (new CancellationSource())->cancellation(), $clock);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId());
        self::assertSame('b7ad6b7169203331', $context->spanId());
        self::assertSame('2026-11-17T10:00:00.123456+00:00', $context->deadline()?->format('Y-m-d\TH:i:s.uP'));
        // 只留已登记的键；xihan.principal 原样交给作者
        self::assertSame(['xihan.tenant' => 't1', 'xihan.principal' => 'alice'], $context->baggage());

        // traceparent 不合文法：没有父 Span，开始新的追踪
        $fresh = Protocol::context((new V1\InvocationMetadata())->setTraceParent('00-XYZ-1-01'), null, (new CancellationSource())->cancellation(), $clock);
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $fresh->traceId());
        self::assertSame(32, strlen($fresh->traceId()));
        self::assertNull(Protocol::context(null, null, (new CancellationSource())->cancellation(), $clock)->deadline());
    }

    public function testCallbackMetadataComesFromTheContext(): void
    {
        $context = Context::create(
            deadline: new \DateTimeImmutable('2026-11-17T18:00:00.5+08:00'),
            traceParent: '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00',
            baggage: ['xihan.tenant' => 't1', 'xihan.other' => 'kept-in-process'],
        );
        $metadata = Protocol::metadata($context);
        self::assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00', $metadata->getTraceParent());
        // UTC、七位小数、+00:00
        self::assertSame('2026-11-17T10:00:00.5000000+00:00', $metadata->getDeadline());
        self::assertSame('', $metadata->getTraceState());
        self::assertSame(['xihan.tenant' => 't1'], iterator_to_array($metadata->getBaggage()));
        self::assertSame('', Protocol::metadata(Context::create())->getDeadline());
    }

    public function testEnvelopesAndHealthAreEncoded(): void
    {
        // 承载租户的扩展属性名是公开常量（RFC-0009「信封」）
        self::assertSame('xihantenant', Envelope::EXTENSION_TENANT);
        $proto = Protocol::toProtoEnvelope(new Envelope('echo.events', 'e1', 'urn:x', 'x', '{}', '', extensions: [Envelope::EXTENSION_TENANT => 't1']));
        self::assertSame('1.0', $proto->getSpecVersion());
        self::assertSame('application/json', $proto->getDataContentType());
        $envelope = Protocol::envelope($proto);
        self::assertNotNull($envelope);
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
