<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Invocation;

use XiHan\Framework\Invocation\Cancellation;
use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\CancellationSource;

/**
 * 测试用的令牌：转给一个 CancellationSource，并记下当前登记数。
 */
final class SpyCancellation implements Cancellation
{
    public int $subscriptions = 0;

    public function __construct(private readonly CancellationSource $source)
    {
    }

    public function isRequested(): bool
    {
        return $this->source->cancellation()->isRequested();
    }

    public function reason(): ?CancellationReason
    {
        return $this->source->cancellation()->reason();
    }

    public function subscribe(\Closure $callback): string
    {
        $this->subscriptions++;

        return $this->source->cancellation()->subscribe($callback);
    }

    public function unsubscribe(string $id): void
    {
        $this->subscriptions--;
        $this->source->cancellation()->unsubscribe($id);
    }
}
