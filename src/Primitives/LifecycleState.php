<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 九态生命周期（RFC-0001）。
 *
 * 枚举值是状态名（线上与 JSON 用名字）；`number()` 是与 .NET 枚举和插件协议 LifecycleState 一致的数值。
 * 合法迁移的完整列表是规范性内容（conformance/vectors/primitives/lifecycle-transitions.json），未列出的一律非法。
 */
enum LifecycleState: string
{
    case Declared = 'Declared';
    case Resolved = 'Resolved';
    case Configured = 'Configured';
    case Started = 'Started';
    case Ready = 'Ready';
    case Degraded = 'Degraded';
    case Draining = 'Draining';
    case Stopped = 'Stopped';
    case Failed = 'Failed';

    /**
     * 按协议数值解析状态；没有对应状态时为 null。
     */
    public static function fromNumber(int $number): ?self
    {
        return self::cases()[$number] ?? null;
    }

    /**
     * 返回协议数值：Declared 为 0，依声明顺序到 Failed 为 8。
     */
    public function number(): int
    {
        return match ($this) {
            self::Declared => 0,
            self::Resolved => 1,
            self::Configured => 2,
            self::Started => 3,
            self::Ready => 4,
            self::Degraded => 5,
            self::Draining => 6,
            self::Stopped => 7,
            self::Failed => 8,
        };
    }

    /**
     * 判断迁移是否合法。
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->successors(), true);
    }

    /**
     * 判断是否可服务：Ready 或 Degraded。
     */
    public function isServing(): bool
    {
        return $this === self::Ready || $this === self::Degraded;
    }

    /**
     * 判断是否终态：Stopped；Failed 可以重新解析，不是终态。
     */
    public function isTerminal(): bool
    {
        return $this === self::Stopped;
    }

    /**
     * 返回可以迁移到的状态。
     *
     * @return list<self>
     */
    private function successors(): array
    {
        // 与一致性向量逐条对应；Stopped 是终态没有后继，Failed 只能回到 Resolved 重新装配
        return match ($this) {
            self::Declared => [self::Resolved, self::Failed],
            self::Resolved => [self::Configured, self::Failed],
            self::Configured => [self::Started, self::Failed],
            self::Started => [self::Ready, self::Degraded, self::Draining, self::Failed],
            self::Ready => [self::Degraded, self::Draining, self::Failed],
            self::Degraded => [self::Ready, self::Draining, self::Failed],
            self::Draining => [self::Stopped, self::Failed],
            self::Stopped => [],
            self::Failed => [self::Resolved],
        };
    }
}
