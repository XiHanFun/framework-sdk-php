<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest\Internal;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 清单模型共用的校验工具。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Checks
{
    /** 接口版本的上限：32 位有符号整数（RFC-0002 解析规则 2，ADR-0236） */
    public const int MAX_VERSION = 2147483647;

    /** 版本文法：SemVer 2.0；D 修饰符让 $ 只匹配串尾（RFC-0001「文法的匹配」） */
    public const string SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/D';

    /** 激活事件文法 */
    public const string ACTIVATION_PATTERN = '/^(onStartup|onOperation:[a-z0-9.*-]+|onEvent:[a-z0-9.*-]+)$/D';

    /** 插件所需权限的文法；`.` 不匹配换行 */
    public const string PERMISSION_PATTERN = '/^(net\.outbound|fs\.read|fs\.write|capability):.+$/D';

    /** 绑定名文法 */
    public const string BINDING_NAME_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    /** 绑定类型文法 */
    public const string BINDING_TYPE_PATTERN = '/^[a-z]+(:[a-z]+)?$/D';

    /** 配置节文法 */
    public const string SECTION_PATTERN = '/^XiHan:[A-Z][A-Za-z0-9]*(:[A-Z][A-Za-z0-9]*)*$/D';

    /** 权限码文法（RFC-0008） */
    public const string PERMISSION_CODE_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/D';

    /** 健康项文法 */
    public const string HEALTH_PATTERN = '/^[a-z][a-z0-9.-]*$/D';

    private function __construct()
    {
    }

    /**
     * 判断文本是否整串符合文法。
     */
    public static function matches(string $pattern, string $text): bool
    {
        return preg_match($pattern, $text) === 1;
    }

    /**
     * 解析接口版本文本：不带前导零、符号与空白的正整数，不超过 2147483647；`$allowCaret` 时可带一个前缀 `^`
     * （`^N` 与 N 等价）。不合法时为 null。
     */
    public static function interfaceVersion(string $text, bool $allowCaret): ?int
    {
        $pattern = $allowCaret ? '/^\^?([1-9][0-9]*)$/D' : '/^([1-9][0-9]*)$/D';
        if (preg_match($pattern, $text, $match) !== 1 || strlen($match[1]) > 10) {
            return null;
        }
        $version = (int) $match[1];

        return $version <= self::MAX_VERSION ? $version : null;
    }

    /**
     * 判断接口版本的数值在范围内：1 到 2147483647。
     */
    public static function isVersion(int $version): bool
    {
        return $version >= 1 && $version <= self::MAX_VERSION;
    }

    /**
     * 核对数组是给定类型的列表；运行期也要核对，调用方不一定经过静态检查。
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<array-key, mixed> $values
     *
     * @return list<T>
     *
     * @throws InvalidArgumentException 不是列表或元素类型不对（PRIMITIVES_VALIDATION_001）
     */
    public static function listOf(string $class, array $values, string $field): array
    {
        $checked = [];
        foreach ($values as $index => $value) {
            if ($index !== count($checked) || !$value instanceof $class) {
                throw new InvalidArgumentException(sprintf("The field '%s' must be a list of %s.", $field, $class));
            }
            $checked[] = $value;
        }

        return $checked;
    }

    /**
     * 核对数组是字符串列表。
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException 不是列表或元素不是字符串（PRIMITIVES_VALIDATION_001）
     */
    public static function strings(array $values, string $field): array
    {
        $checked = [];
        foreach ($values as $index => $value) {
            if ($index !== count($checked) || !is_string($value)) {
                throw new InvalidArgumentException(sprintf("The field '%s' must be a list of strings.", $field));
            }
            $checked[] = $value;
        }

        return $checked;
    }

    /**
     * 返回列表里第一个重复的值；没有重复为 null。
     *
     * @param list<string> $values
     */
    public static function firstDuplicate(array $values): ?string
    {
        $seen = [];
        foreach ($values as $value) {
            if (isset($seen[$value])) {
                return $value;
            }
            $seen[$value] = true;
        }

        return null;
    }
}
