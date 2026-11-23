<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\JsonReader;
use XiHan\Framework\Primitives\Internal\Numbers;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\JsonPath;

/**
 * Configure 下发的内容（lifecycle.md §2、§7.2）。
 *
 * 配置节是清单 contributes.config.section 节的 JSON 对象，按值模型原样交给作者（空等同 `{}`，没有声明配置节为 `{}`）。
 * .NET 宿主的配置源是键值字符串，叶子一律写成 JSON 字符串，所以另有类型化读取：按键路径逐个读（value()、string()、int()、
 * float()、bool()，键按 `:` 分段），或按形状整节读（decode()）。两种读法同一套规则：成员名按 ASCII 不区分大小写匹配
 * （与 IConfiguration 一致），字符串叶子读成数字时按 JSON 数字的文法，读成布尔时只认 `true` 与 `false`（ASCII 不区分
 * 大小写）。配置变更时宿主不重新调用 Configure，而是重启插件进程。
 */
final readonly class Configuration
{
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
        if (is_string($value) && JsonReader::isNumber($value)) {
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
     * 按形状读整个配置节（lifecycle.md §7.2）：与操作载荷同一套形状与核对规则（Shape），另按配置节的读法——对象的成员名按
     * ASCII 不区分大小写对上声明的成员（逐字相同的优先，其次取文本里靠前的那个，与 value() 一致），字符串叶子读成数字时按
     * JSON 数字的文法，读成布尔时只认 `true`、`false`（ASCII 不区分大小写）；字典的键原样保留，未知成员忽略。
     *
     * 返回核对过的值，与 Codec::typed() 交给创建函数的相同：对象是以声明的成员名为键的数组（只含出现了的成员），用它创建
     * 自己的配置类型。
     *
     * @param Shape $shape 配置节的形状：Shape::object() 或 Shape::mapOf()
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException 形状的根不是对象或字典，或配置节不合形状（消息带第一处问题的 JSON 路径，成员名照
     *     配置节里的写法；PRIMITIVES_VALIDATION_001）
     */
    public function decode(Shape $shape): array
    {
        if (!$shape->isObjectLike()) {
            throw new InvalidArgumentException('The shape of a configuration section must be an object or a map.');
        }
        try {
            // 形状按 JSON 文本核对（任意 JSON 按原文保留）：把配置节按值模型写回文本再读
            $text = Json::encode($this->section);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException(
                'The configuration section cannot be written as JSON.',
                previous: $exception,
            );
        }
        $path = $shape->read(JsonReader::parse($text), JsonPath::ROOT, $text, $decoded, true);
        if ($path !== null) {
            throw new InvalidArgumentException(
                sprintf("The configuration section does not match the shape at '%s'.", $path),
            );
        }

        // 根是 JSON 对象：按对象或字典的形状读出的是以成员名为键的数组
        /** @var array<array-key, mixed> */
        return $decoded;
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
     * 浮点写成最短的往返文本，整数值的浮点带 `.0`，与 JSON 文本一致；经 Json::encode 写，不随进程的 serialize_precision
     * 变化。非有限的浮点（配置节里超出双精度的数）在 JSON 里写不出，写成线上编码用的 `NaN`、`Infinity`、`-Infinity`。
     */
    private static function floatText(float $value): string
    {
        return is_finite($value) ? Json::encode($value) : Numbers::plain($value);
    }

    private static function unreadable(string $key, string $what): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf("The configuration value '%s' cannot be read as %s.", $key, $what),
        );
    }
}
