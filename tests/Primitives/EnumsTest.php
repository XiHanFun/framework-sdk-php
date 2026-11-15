<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Stability;

/**
 * 生命周期、管道阶段、稳定性的名字、顺序与数值，以及 Baggage 值的约束。
 */
final class EnumsTest extends TestCase
{
    public function testLifecycleStatesHaveProtocolNumbersAndRoles(): void
    {
        self::assertTrue(LifecycleState::Ready->isServing());
        self::assertTrue(LifecycleState::Degraded->isServing());
        self::assertFalse(LifecycleState::Started->isServing());
        self::assertTrue(LifecycleState::Stopped->isTerminal());
        self::assertFalse(LifecycleState::Failed->isTerminal());
        self::assertSame(0, LifecycleState::Declared->number());
        self::assertSame(8, LifecycleState::Failed->number());
        foreach (LifecycleState::cases() as $index => $state) {
            self::assertSame($index, $state->number());
            self::assertSame($state, LifecycleState::fromNumber($index));
        }
        self::assertNull(LifecycleState::fromNumber(9));
        self::assertNull(LifecycleState::fromNumber(-1));
        self::assertNull(LifecycleState::tryFrom(self::text('ready')), 'state names are PascalCase');
        self::assertSame('"Draining"', json_encode(LifecycleState::Draining));
    }

    public function testPipelineStagesAreOrdered(): void
    {
        $names = [
            'ingress', 'observe', 'resilience', 'authenticate', 'tenant', 'authorize',
            'validate', 'idempotency', 'transaction', 'handle', 'egress',
        ];
        $stages = PipelineStage::cases();
        self::assertCount(count($names), $stages);
        foreach ($stages as $index => $stage) {
            self::assertSame($names[$index], $stage->manifestName());
            self::assertSame($index, $stage->order());
            self::assertSame($stage, PipelineStage::tryFrom($names[$index]));
        }
        self::assertNull(PipelineStage::tryFrom(self::text('Handle')), 'stage names are lowercase');
    }

    public function testStabilityNames(): void
    {
        $names = ['stable', 'preview', 'experimental', 'internal', 'deprecated'];
        $values = array_map(static fn (Stability $stability): string => $stability->value, Stability::cases());
        self::assertSame($names, $values);
        self::assertSame('"experimental"', json_encode(Stability::Experimental));
        self::assertNull(Stability::tryFrom(self::text('solid')));
    }

    public function testBaggageValuesAreBounded(): void
    {
        self::assertTrue(BaggageKeys::isAcceptableValue('t1'));
        self::assertTrue(BaggageKeys::isAcceptableValue('租户-1'));
        self::assertTrue(BaggageKeys::isAcceptableValue(str_repeat('a', BaggageKeys::MAX_VALUE_LENGTH)));
        self::assertTrue(BaggageKeys::isAcceptableValue(str_repeat('租', BaggageKeys::MAX_VALUE_LENGTH)));
        self::assertFalse(BaggageKeys::isAcceptableValue(''));
        self::assertFalse(BaggageKeys::isAcceptableValue(str_repeat('a', BaggageKeys::MAX_VALUE_LENGTH + 1)));
        self::assertFalse(BaggageKeys::isAcceptableValue("c1\nfake log line"));
        self::assertFalse(BaggageKeys::isAcceptableValue("c1\x7f"));
        self::assertFalse(BaggageKeys::isAcceptableValue("c1\u{85}"));
        self::assertFalse(BaggageKeys::isAcceptableValue("\xff"), 'invalid UTF-8 is not acceptable');
    }

    /**
     * 把字面量交给运行期：tryFrom 的结果不该在静态分析时就被定死。
     */
    private static function text(string $text): string
    {
        return $text;
    }
}
