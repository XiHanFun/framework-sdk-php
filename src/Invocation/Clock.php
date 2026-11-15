<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

/**
 * 时钟：截止时间的判定都经过它，测试可以注入可控的实现。
 *
 * 方法与 PSR-20 的 ClockInterface 相同，适配一行即可。
 */
interface Clock
{
    /**
     * 返回当前时刻。
     */
    public function now(): \DateTimeImmutable;
}
