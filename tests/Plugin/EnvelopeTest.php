<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Protocol\V1;

/**
 * 信封的 `dataschema`（invocation.md §4，ADR-0305）：原样写出与交出，空串是没有。
 */
final class EnvelopeTest extends TestCase
{
    public function testTheDataSchemaIsCarriedAsIs(): void
    {
        $schema = 'https://example.com/schemas/order.json';
        $envelope = new Envelope('orders', 'e1', 'urn:test', 'orders.created', dataSchema: $schema);
        self::assertSame($schema, Protocol::toProtoEnvelope($envelope)->getDataSchema());
        self::assertSame('', Protocol::toProtoEnvelope(new Envelope('orders', 'e1', 'urn:test', 'x'))->getDataSchema());
        // 不裁剪、不核对：只有空白的值由宿主拒绝那一项
        $spaced = new Envelope('orders', 'e1', 'urn:test', 'x', dataSchema: ' urn:x ');
        self::assertSame(' urn:x ', Protocol::toProtoEnvelope($spaced)->getDataSchema());
        // 收到的原样交出，空串是没有
        foreach ([$schema, '', ' '] as $text) {
            $proto = (new V1\Envelope())->setTopic('orders')->setId('e1')->setSource('urn:test')->setType('orders.created')
                ->setDataSchema($text);
            self::assertSame($text, Protocol::envelope($proto)->dataSchema, $text);
        }
    }
}
