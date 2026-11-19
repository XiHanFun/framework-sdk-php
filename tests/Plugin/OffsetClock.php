<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use XiHan\Framework\Invocation\Clock;

/**
 * 测试用的时钟：系统时钟加上可调的偏移，模拟墙上时钟与事件循环的单调时钟走得不一致。
 */
final class OffsetClock implements Clock
{
    /** 加在系统时钟上的微秒数，负数即落后 */
    public int $offsetMicroseconds = 0;

    public function now(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->offsetMicroseconds === 0 ? $now : $now->modify(sprintf('%+d microseconds', $this->offsetMicroseconds));
    }
}
