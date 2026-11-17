<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

/**
 * 宿主此刻交给插件处理的调用的租户（invocation.md §2、§5，ADR-0195）。
 *
 * 回调与发布能带的租户以它为限：调用进来时登记、结束时撤销，同一租户的并发调用按次数计；没有在途调用时回调不能带租户。
 * 条目数不超过并发的调用数，随调用结束回收。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class TenantScope
{
    /** @var array<string, int> 租户 → 在途调用数 */
    private array $tenants = [];

    /**
     * 登记一次调用的租户；没有租户的调用不登记。
     */
    public function enter(?string $tenant): void
    {
        if ($tenant !== null) {
            $this->tenants[$tenant] = ($this->tenants[$tenant] ?? 0) + 1;
        }
    }

    /**
     * 撤销一次调用的租户。
     */
    public function leave(?string $tenant): void
    {
        if ($tenant === null || !isset($this->tenants[$tenant])) {
            return;
        }
        if (--$this->tenants[$tenant] <= 0) {
            unset($this->tenants[$tenant]);
        }
    }

    /**
     * 判断租户是不是某个在途调用的租户。
     */
    public function admits(string $tenant): bool
    {
        return isset($this->tenants[$tenant]);
    }
}
