<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Invocation\Internal\CancellationState;
use XiHan\Framework\Invocation\Internal\StateCancellation;

/**
 * 外部取消的来源：持有者调用 cancel()，交出去的令牌随之取消（原因为 Cancelled）。
 *
 * 相当于 amphp 的 DeferredCancellation：连接断开、宿主停止、调用方放弃时由载体或宿主调用 cancel()。
 */
final class CancellationSource
{
    private readonly CancellationState $state;

    private readonly Cancellation $cancellation;

    /**
     * 创建未取消的来源。
     */
    public function __construct()
    {
        $this->state = new CancellationState();
        $this->cancellation = new StateCancellation($this->state);
    }

    /**
     * 返回交给调用的令牌。
     */
    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    /**
     * 请求取消：令牌的原因定为 Cancelled，并同步调用已登记的回调；重复调用什么都不做。
     */
    public function cancel(): void
    {
        $this->state->trigger(CancellationReason::Cancelled);
    }

    /**
     * 判断是否已请求取消。
     */
    public function isCancelled(): bool
    {
        return $this->state->reason !== null;
    }
}
