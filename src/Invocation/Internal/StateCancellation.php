<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation\Internal;

use XiHan\Framework\Invocation\Cancellation;
use XiHan\Framework\Invocation\CancellationReason;

/**
 * CancellationSource 交出的令牌：只读地反映来源的状态。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final readonly class StateCancellation implements Cancellation
{
    public function __construct(private CancellationState $state)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isRequested(): bool
    {
        return $this->state->reason !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function reason(): ?CancellationReason
    {
        return $this->state->reason;
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(\Closure $callback): string
    {
        return $this->state->subscribe($callback);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $id): void
    {
        $this->state->unsubscribe($id);
    }
}
