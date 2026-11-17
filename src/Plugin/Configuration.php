<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;

/**
 * Configure 下发的内容（lifecycle.md §2、§7.2）。
 *
 * 配置节是清单 contributes.config.section 节的 JSON 对象，按值模型原样交给作者（空等同 `{}`，没有声明配置节为 `{}`）。
 * .NET 宿主的配置源是键值字符串，叶子一律写成 JSON 字符串，所以另有类型化读取（value()、string()、int()、float()、bool()）：
 * 键按 `:` 分段，成员名按 ASCII 不区分大小写匹配（与 IConfiguration 一致），字符串叶子读成数字时按 JSON 数字的文法，读成布尔
 * 时只认 `true` 与 `false`（ASCII 不区分大小写）。配置变更时宿主不重新调用 Configure，而是重启插件进程。
 */
final readonly class Configuration
{
    /** JSON 数字的文法（RFC 8259 §6） */
    private const string NUMBER = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D';

    /** JSON 数字里的整数写法：没有小数与指数 */
    private const string INTEGER = '/^-?(?:0|[1-9][0-9]*)$/D';

    /** 数组的下标段：从 0 起的十进制，不带前导零 */
    private const string INDEX = '/^(?:0|[1-9][0-9]*)$/D';

    /**
     * @param JsonObject $section 配置节
     * @param list<string> $grantedPermissions 宿主授予的权限；回调前的权限核对以它为准（invocation.md §5）
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
     * 按键取配置值：键按 `:` 分段（如 `Retry:Count`），对象的成员名按 ASCII 不区分大小写匹配（只差大小写的同名成员取文本里
     * 靠前的那个），数组的段是从 0 起的十进制下标；找不到时为 null，JSON 的 null 同样为 null。
     */
    public function value(string $key): mixed
    {
        $current = $this->section;
        foreach (explode(':', $key) as $segment) {
            if ($current instanceof JsonObject) {
                $current = self::member($current, $segment);
            } elseif (is_array($current) && preg_match(self::INDEX, $segment) === 1) {
                $current = $current[(int) $segment] ?? null;
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * 按键读字符串：字符串原样，数字与布尔写成它们的 JSON 文本；找不到或为 null 时为 null。
     *
     * @throws InvalidArgumentException 值是对象或数组（PRIMITIVES_VALIDATION_001）
     */
    public function string(string $key): ?string
    {
        $value = $this->value($key);

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::floatText($value),
            default => throw self::unreadable($key, 'a string'),
        };
    }

    /**
     * 按键读整数：JSON 的整数，或按 JSON 数字文法写的整数字符串（不带小数与指数，在 64 位范围内）；找不到或为 null 时为 null。
     *
     * @throws InvalidArgumentException 值读不成整数（PRIMITIVES_VALIDATION_001）
     */
    public function int(string $key): ?int
    {
        $value = $this->value($key);
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match(self::INTEGER, $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            // 64 位范围之外 filter_var 返回 false：越界的整数报错，不静默截断
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw self::unreadable($key, 'an integer');
    }

    /**
     * 按键读数字：JSON 的数字，或按 JSON 数字文法写的字符串；找不到或为 null 时为 null。
     *
     * @throws InvalidArgumentException 值读不成数字（PRIMITIVES_VALIDATION_001）
     */
    public function float(string $key): ?float
    {
        $value = $this->value($key);
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match(self::NUMBER, $value) === 1) {
            return (float) $value;
        }

        throw self::unreadable($key, 'a number');
    }

    /**
     * 按键读布尔：JSON 的布尔，或字符串 `true`、`false`（ASCII 不区分大小写）；找不到或为 null 时为 null。
     *
     * @throws InvalidArgumentException 值读不成布尔（PRIMITIVES_VALIDATION_001）
     */
    public function bool(string $key): ?bool
    {
        $value = $this->value($key);
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_string($value) && strcasecmp($value, 'true') === 0) {
            return true;
        }
        if (is_string($value) && strcasecmp($value, 'false') === 0) {
            return false;
        }

        throw self::unreadable($key, 'a boolean');
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

    /**
     * 按 ASCII 不区分大小写找成员；strcasecmp 自 PHP 8.2 起与区域设置无关，只折叠 ASCII 字母。
     */
    private static function member(JsonObject $object, string $name): mixed
    {
        // 逐字相同的成员优先，其次按文本顺序取第一个只差 ASCII 大小写的成员
        if ($object->has($name)) {
            return $object->get($name);
        }
        foreach ($object as $candidate => $value) {
            if (strcasecmp($candidate, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 浮点写成最短的往返文本，整数值的浮点带 `.0`，与 JSON 文本一致。
     */
    private static function floatText(float $value): string
    {
        $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return $text === false ? (string) $value : $text;
    }

    private static function unreadable(string $key, string $what): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf("The configuration value '%s' cannot be read as %s.", $key, $what),
        );
    }
}
