<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

/**
 * 系统时钟：返回 UTC 的当前时刻（微秒精度）。
 */
final readonly class SystemClock implements Clock
{
    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
