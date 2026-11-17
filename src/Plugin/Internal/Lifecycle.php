<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\LifecycleState;

/**
 * 插件的生命周期状态机（lifecycle.md §1）：迁移按原语层的合法迁移表核对，每次变化唤醒等着的健康推送。
 *
 * 状态只在事件循环里改写，Fiber 只在挂起点切换，读写之间没有挂起点，不需要锁。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Lifecycle
{
    private LifecycleState $state = LifecycleState::Declared;

    private ?Error $error = null;

    /** @var DeferredFuture<null>|null 下一次变化 */
    private ?DeferredFuture $next = null;

    /**
     * 返回当前状态。
     */
    public function state(): LifecycleState
    {
        return $this->state;
    }

    /**
     * 返回进入当前状态的原因（Degraded、Failed）；没有时为 null。
     */
    public function error(): ?Error
    {
        return $this->error;
    }

    /**
     * 判断是否处于给定状态之一。
     */
    public function is(LifecycleState ...$states): bool
    {
        return in_array($this->state, $states, true);
    }

    /**
     * 迁移到新状态；迁移不合法时什么都不做并返回假。迁移到当前状态只更新原因。
     */
    public function transition(LifecycleState $to, ?Error $error = null): bool
    {
        if ($to === $this->state) {
            $changed = $error !== $this->error;
            $this->error = $error;
            if ($changed) {
                $this->notify();
            }

            return true;
        }
        if (!$this->state->canTransitionTo($to)) {
            return false;
        }
        $this->state = $to;
        $this->error = $error;
        $this->notify();

        return true;
    }

    /**
     * 返回下一次变化时完成的 Future。
     *
     * @return Future<null>
     */
    public function changed(): Future
    {
        $this->next ??= new DeferredFuture();

        return $this->next->getFuture();
    }

    private function notify(): void
    {
        $next = $this->next;
        $this->next = null;
        $next?->complete();
    }
}
