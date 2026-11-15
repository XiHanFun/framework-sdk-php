<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

/**
 * 永不取消的令牌：没有外部取消的调用用它。
 */
final readonly class NullCancellation implements Cancellation
{
    /**
     * {@inheritDoc}
     */
    public function isRequested(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function reason(): ?CancellationReason
    {
        return null;
    }

    /**
     * 永不调用回调；返回的 ID 撤销时什么都不做。
     */
    public function subscribe(\Closure $callback): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $id): void
    {
    }
}
