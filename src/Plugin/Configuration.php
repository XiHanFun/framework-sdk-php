<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\JsonObject;

/**
 * Configure 下发的内容（lifecycle.md §2）。
 *
 * 配置节是清单 contributes.config.section 节的 JSON 对象，按值模型读出（没有声明配置节为 `{}`）。.NET 宿主的配置源是
 * 键值字符串，数字与布尔多半以字符串下发，读取时按需要转换。配置变更时宿主不重新调用 Configure，而是重启插件进程。
 */
final readonly class Configuration
{
    /**
     * @param JsonObject $section 配置节
     * @param list<string> $grantedPermissions 宿主授予的权限；等于清单 permissions
     * @param list<ResolvedBinding> $bindings 已解析的资源绑定
     * @param string $workDirectory 宿主为插件分配的可写目录（宿主停止时不删除）
     */
    public function __construct(
        public JsonObject $section,
        public array $grantedPermissions = [],
        public array $bindings = [],
        public string $workDirectory = '',
    ) {
    }

    /**
     * 按名字取绑定；没有时为 null。
     */
    public function binding(string $name): ?ResolvedBinding
    {
        foreach ($this->bindings as $binding) {
            if ($binding->name === $name) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * 判断宿主是否授予了权限。
     */
    public function isGranted(string $permission): bool
    {
        return in_array($permission, $this->grantedPermissions, true);
    }
}
