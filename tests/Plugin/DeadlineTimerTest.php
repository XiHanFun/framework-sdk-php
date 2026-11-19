<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\DeadlineCancellation;
use XiHan\Framework\Invocation\NullCancellation;
use XiHan\Framework\Plugin\Internal\DeadlineTimer;

use function Amp\delay;

/**
 * 截止令牌的定时器：事件循环的定时器按单调时钟触发，令牌按上下文的时钟核对，两者不一致时令牌仍在时钟的截止时间触发。
 */
final class DeadlineTimerTest extends TestCase
{
    public function testTheTokenFiresAtTheDeadlineOfItsClockWhenTheLoopTimerComesFirst(): void
    {
        $clock = new OffsetClock();
        $deadline = $clock->now()->modify('+50 milliseconds');
        $token = new DeadlineCancellation(new NullCancellation(), $deadline, $clock);
        $fired = null;
        // 只登记回调、不再轮询令牌：isRequested 与 reason 会顺带核对时钟，测试里一读就替定时器触发了令牌
        $token->subscribe(static function (CancellationReason $reason) use (&$fired, $clock): void {
            $fired = [$reason, $clock->now()];
        });
        $timer = DeadlineTimer::start($token, $clock);
        try {
            // 定时器排好之后时钟落后 250 毫秒：定时器在 50 毫秒后触发（事件循环可能晚几十毫秒），那时时钟离截止时间
            // 还远；落后量远大于定时器可能晚到的时间，令牌只能靠重排的定时器在时钟到达截止时间时触发
            $clock->offsetMicroseconds = -250_000;
            delay(0.8);
        } finally {
            $timer->cancel();
        }
        self::assertIsArray($fired, 'the token fires once its clock reaches the deadline');
        [$reason, $at] = $fired;
        self::assertSame(CancellationReason::DeadlineExceeded, $reason);
        self::assertGreaterThanOrEqual($deadline, $at);
    }

    public function testACancelledTimerNeverFiresTheToken(): void
    {
        $clock = new OffsetClock();
        $token = new DeadlineCancellation(new NullCancellation(), $clock->now()->modify('+50 milliseconds'), $clock);
        $fired = false;
        $token->subscribe(static function () use (&$fired): void {
            $fired = true;
        });
        DeadlineTimer::start($token, $clock)->cancel();
        delay(0.15);
        self::assertFalse($fired);
    }
}
