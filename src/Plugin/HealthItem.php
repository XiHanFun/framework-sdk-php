<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 一个健康项：名字须在清单 contributes.health 里声明（lifecycle.md §4），没有声明的不上报。
 */
final readonly class HealthItem
{
    /**
     * @param string $name 健康项名
     * @param HealthStatus $status 状态
     * @param string $description 说明；可空
     */
    public function __construct(
        public string $name,
        public HealthStatus $status = HealthStatus::Healthy,
        public string $description = '',
    ) {
    }
}
