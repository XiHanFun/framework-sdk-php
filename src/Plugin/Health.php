<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LifecycleState;

/**
 * 插件自报的健康（lifecycle.md §7.4）：状态与健康项。
 *
 * 只有 Ready、Degraded、Failed 改变运行时的状态（按合法迁移表，Failed 之后不再回来），自报的 Started、Draining 不改变状态；
 * 健康项名不在清单 contributes.health 里的丢掉、不发给宿主。
 */
final readonly class Health
{
    /** @var list<HealthItem> */
    public array $items;

    /**
     * @param LifecycleState $state 自报的状态：只能是 Started、Ready、Degraded、Draining 或 Failed
     * @param list<HealthItem> $items 健康项
     * @param Error|null $error Degraded 或 Failed 的原因
     *
     * @throws InvalidArgumentException 状态不能自报，或健康项不是 HealthItem 的列表（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public LifecycleState $state, array $items = [], public ?Error $error = null)
    {
        $selfReported = [
            LifecycleState::Started,
            LifecycleState::Ready,
            LifecycleState::Degraded,
            LifecycleState::Draining,
            LifecycleState::Failed,
        ];
        if (!in_array($state, $selfReported, true)) {
            throw new InvalidArgumentException(sprintf(
                "A plugin may only report Started, Ready, Degraded, Draining or Failed, got '%s'.",
                $state->value,
            ));
        }
        $this->items = self::checkedItems($items);
    }

    /**
     * 调用方不一定经过静态检查，运行期也核对健康项的类型。
     *
     * @param array<array-key, mixed> $items
     *
     * @return list<HealthItem>
     */
    private static function checkedItems(array $items): array
    {
        $checked = [];
        foreach ($items as $index => $item) {
            if ($index !== count($checked) || !$item instanceof HealthItem) {
                throw new InvalidArgumentException(
                    sprintf('The health items must be a list of %s.', HealthItem::class),
                );
            }
            $checked[] = $item;
        }

        return $checked;
    }
}
