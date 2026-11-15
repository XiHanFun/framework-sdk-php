<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Invocation;

use XiHan\Framework\Invocation\Clock;

/**
 * 测试用的可控时钟：只在 advance() 时前进。
 */
final class ManualClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-16T00:00:00.000000Z')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * 前进给定的毫秒数。
     */
    public function advance(int $milliseconds): void
    {
        $interval = new \DateInterval('PT' . intdiv($milliseconds, 1000) . 'S');
        $interval->f = ($milliseconds % 1000) / 1000;
        $this->now = $this->now->add($interval);
    }
}
