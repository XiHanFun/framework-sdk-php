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
    private function __construct()
    {
    }

    /**
     * 解析接口版本文本：正整数，`$allowCaret` 时可带前缀 `^`（`^N` 与 N 等价）；不合法或超出整数范围为 null。
     */
    public static function interfaceVersion(string $text, bool $allowCaret): ?int
    {
        $pattern = $allowCaret ? '/^\^?([1-9][0-9]*)$/D' : '/^([1-9][0-9]*)$/D';
        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }
        $version = filter_var($match[1], FILTER_VALIDATE_INT);

        return is_int($version) ? $version : null;
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
