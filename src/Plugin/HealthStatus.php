<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 健康项的状态；数值与 plugin.proto 的 HealthStatus 一致。
 */
enum HealthStatus: int
{
    /** 正常。 */
    case Healthy = 1;

    /** 降级：宿主把插件映射为 Degraded（lifecycle.md §1）。 */
    case Degraded = 2;

    /** 不健康：宿主同样映射为 Degraded。 */
    case Unhealthy = 3;
}
