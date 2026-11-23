<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation\Internal;

use XiHan\Framework\Invocation\Cancellation;
use XiHan\Framework\Invocation\CancellationReason;

/**
 * Context::withCancellation() 派生出的令牌：源上下文的令牌或另给的令牌任一触发即取消（RFC-0003 规则 1）。
 *
 * 归因以源上下文的令牌为先：触发时它有原因就取它的——截止时间已到而还没人轮询时另一个令牌取消，源令牌那时已经该触发，
 * 归为超时——否则取触发的那个令牌的原因。原因一经确定不再改变。
 *
 * 同步执行模型下没有定时器：轮询（isRequested / reason / subscribe）时先轮询两个上游令牌，带截止时间的上游在这时核对时钟。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class LinkedCancellation implements Cancellation
{
    private readonly CancellationState $state;

    /** 在源令牌上的登记 */
    private readonly string $sourceSubscription;

    /** 在另给的令牌上的登记 */
    private readonly string $linkedSubscription;

    /**
     * @param Cancellation $source 源上下文的令牌，归因时为先
     * @param Cancellation $linked 另给的令牌
     */
    public function __construct(private readonly Cancellation $source, private readonly Cancellation $linked)
    {
        $state = new CancellationState();
        $this->state = $state;
        // 回调只捕获状态与源令牌，不捕获 $this：本令牌没人引用时照常析构，析构时撤销在两个上游令牌上的登记
        $callback = static function (CancellationReason $reason) use ($state, $source): void {
            $state->trigger($source->reason() ?? $reason);
        };
        // 上游已经取消时 subscribe 立即回调：派生出来就已取消
        $this->sourceSubscription = $source->subscribe($callback);
        $this->linkedSubscription = $linked->subscribe($callback);
    }

    /**
     * 撤销在两个上游令牌上的登记，上游长寿时不留下回调。
     */
    public function __destruct()
    {
        $this->source->unsubscribe($this->sourceSubscription);
        $this->linked->unsubscribe($this->linkedSubscription);
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-impure
     */
    #[\Override]
    public function isRequested(): bool
    {
        return $this->reason() !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-impure
     */
    #[\Override]
    public function reason(): ?CancellationReason
    {
        $this->poll();

        return $this->state->reason;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $this->poll();

        return $this->state->subscribe($callback);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function unsubscribe(string $id): void
    {
        $this->state->unsubscribe($id);
    }

    /**
     * 轮询两个上游令牌，源令牌在先：上游在轮询时才触发的（到了截止时间）经登记的回调带动本令牌；上游只报原因、
     * 不调回调的实现也照样跟上。
     */
    private function poll(): void
    {
        if ($this->state->reason !== null) {
            return;
        }
        $reason = $this->source->reason() ?? $this->linked->reason();
        if ($reason !== null) {
            $this->state->trigger($reason);
        }
    }
}
