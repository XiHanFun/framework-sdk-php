<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Revolt\EventLoop;
use XiHan\Framework\Invocation\Clock;
use XiHan\Framework\Invocation\DeadlineCancellation;

/**
 * 在截止时刻核对截止令牌的定时器：同步执行模型下截止令牌在轮询时才核对时钟，接入事件循环后由它在截止时刻主动核对，
 * 登记在令牌上的回调准时执行。定时器先于令牌的时钟到达截止时间时按剩下的时间再等，直到时钟到达。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class DeadlineTimer
{
    /** 事件循环里排着的定时器；已触发或已撤下为 null */
    private ?string $id = null;

    /**
     * @param DeadlineCancellation $token 要核对的截止令牌
     * @param Clock $clock 判定截止时间的时钟，与令牌的相同
     */
    private function __construct(
        private readonly DeadlineCancellation $token,
        private readonly Clock $clock,
    ) {
    }

    /**
     * 为截止令牌起一个定时器。
     */
    public static function start(DeadlineCancellation $token, Clock $clock): self
    {
        $timer = new self($token, $clock);
        $timer->schedule();

        return $timer;
    }

    /**
     * 撤下定时器；调用结束时调用，重复调用没有效果。
     */
    public function cancel(): void
    {
        if ($this->id !== null) {
            EventLoop::cancel($this->id);
            $this->id = null;
        }
    }

    /**
     * 按时钟到截止时间的剩余时间排一个定时器。
     */
    private function schedule(): void
    {
        $this->id = EventLoop::delay($this->remaining(), function (): void {
            $this->id = null;
            // 事件循环的定时器按单调时钟触发，令牌按上下文的时钟核对，两者可能不一致：定时器先到时这次核对落空，
            // 之后不再有人核对，令牌就不会在截止时间触发，所以按时钟还没到就按剩下的时间再等
            if ($this->clock->now() < $this->token->deadline()) {
                $this->schedule();

                return;
            }
            $this->token->poll();
        });
    }

    /**
     * 按时钟到截止时间的剩余秒数，已过为 0。
     */
    private function remaining(): float
    {
        $remaining = (float) $this->token->deadline()->format('U.u') - (float) $this->clock->now()->format('U.u');

        return max(0.0, $remaining);
    }
}
