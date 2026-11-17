<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

/**
 * 取消令牌：可轮询，也可以登记回调（RFC-0003「CancellationToken」）。
 *
 * 形状参照 amphp 的 Cancellation，但不依赖它：执行模型是同步的，回调在触发取消的那次调用里同步执行；
 * 带截止时间的令牌没有定时器，在下一次轮询（isRequested / reason / subscribe）时核对时钟并触发。
 * 回调不应抛出异常，抛出的异常会传给触发取消的调用方。
 */
interface Cancellation
{
    /**
     * 判断是否已请求取消。
     */
    public function isRequested(): bool;

    /**
     * 返回取消原因；没有取消时为 null。原因一经确定就不再改变。
     */
    public function reason(): ?CancellationReason;

    /**
     * 登记回调：取消时以原因为参数调用一次；已经取消时立即调用。返回用于撤销的登记 ID。
     *
     * @param \Closure(CancellationReason): void $callback
     */
    public function subscribe(\Closure $callback): string;

    /**
     * 撤销登记；不认识的 ID 什么都不做。
     */
    public function unsubscribe(string $id): void;
}
