<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Invocation\Internal\CancellationState;

/**
 * 带截止时间的令牌：父令牌取消或到达截止时间时取消（RFC-0003 规则 1）。
 *
 * 归因只看哪个先触发：到达截止时间为 DeadlineExceeded；父令牌取消时截止时间已到也算超时
 * （截止「定时器」那时已经触发），否则为 Cancelled。原因一经确定不再改变。
 *
 * 同步执行模型下没有定时器：截止时间在下一次轮询（isRequested / reason / subscribe / poll）时核对时钟并触发；
 * 接入事件循环时，宿主在截止时刻调用 poll()，让回调准时执行。
 */
final class DeadlineCancellation implements Cancellation
{
    private readonly CancellationState $state;

    private readonly string $parentSubscription;

    /**
     * 创建令牌。
     *
     * @param Cancellation $parent 外部取消的令牌
     * @param \DateTimeImmutable $deadline 截止时间
     * @param Clock $clock 判定截止时间用的时钟
     */
    public function __construct(
        private readonly Cancellation $parent,
        private readonly \DateTimeImmutable $deadline,
        private readonly Clock $clock,
    ) {
        $state = new CancellationState();
        $this->state = $state;
        // 回调只捕获状态与时钟，不捕获 $this：本令牌没人引用时照常析构，析构时撤销在父令牌上的登记
        $this->parentSubscription = $parent->subscribe(
            static function () use ($state, $deadline, $clock): void {
                $state->trigger(
                    $clock->now() >= $deadline ? CancellationReason::DeadlineExceeded : CancellationReason::Cancelled,
                );
            },
        );
    }

    /**
     * 撤销在父令牌上的登记，父令牌长寿时不留下回调。
     */
    public function __destruct()
    {
        $this->parent->unsubscribe($this->parentSubscription);
    }

    /**
     * 返回截止时间。
     */
    public function deadline(): \DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * 核对时钟：已到截止时间且还没有取消时，以 DeadlineExceeded 触发。
     */
    public function poll(): void
    {
        if ($this->state->reason === null && $this->clock->now() >= $this->deadline) {
            $this->state->trigger(CancellationReason::DeadlineExceeded);
        }
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
}
