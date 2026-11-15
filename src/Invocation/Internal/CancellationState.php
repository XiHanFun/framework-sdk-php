<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation\Internal;

use XiHan\Framework\Invocation\CancellationReason;

/**
 * 一个取消令牌的状态：原因与回调。
 *
 * 与令牌对象分开，是为了让父令牌上的回调只捕获状态、不捕获子令牌本身：子令牌没人引用时照常析构，析构时撤销登记。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class CancellationState
{
    /** 原因；一经确定不再改变 */
    public ?CancellationReason $reason = null;

    /** @var array<string, \Closure(CancellationReason): void> 尚未触发的回调，按登记顺序 */
    private array $callbacks = [];

    private int $nextId = 0;

    /**
     * 确定原因并调用全部回调；已经取消时什么都不做。
     */
    public function trigger(CancellationReason $reason): void
    {
        if ($this->reason !== null) {
            return;
        }
        // 先定原因、再取走回调：回调里再轮询或再登记都看到已取消，且每个回调只调用一次
        $this->reason = $reason;
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback($reason);
        }
    }

    /**
     * 登记回调；已经取消时立即调用，不保留。
     *
     * @param \Closure(CancellationReason): void $callback
     */
    public function subscribe(\Closure $callback): string
    {
        // 加前缀让键始终是字符串，PHP 不会把它转成整数键
        $id = 'c' . $this->nextId++;
        if ($this->reason !== null) {
            $callback($this->reason);

            return $id;
        }
        $this->callbacks[$id] = $callback;

        return $id;
    }

    /**
     * 撤销登记。
     */
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    /**
     * 返回尚未触发的回调数。
     */
    public function subscriptions(): int
    {
        return count($this->callbacks);
    }
}
