<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * 原语层内部用的取值工具：判断 JSON 可表示性、把值写成文本。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Values
{
    /** 线上编码用的 json_encode 选项：斜杠与非 ASCII 字符原样输出 */
    public const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** 判断可表示性时的最大嵌套深度，与 json_encode 的缺省深度一致，避免无界递归 */
    private const int MAX_DEPTH = 512;

    private function __construct()
    {
    }

    /**
     * 判断值是否 JSON 可表示：null、布尔、整数、有限浮点数、UTF-8 字符串、由它们组成的数组，或 JsonSerializable。
     */
    public static function isJson(mixed $value): bool
    {
        return self::isJsonAt($value, 0);
    }

    /**
     * 把值写成面向人的文本：字符串原样，标量与 null 按 JSON 字面量，枚举取值或名字，其余按 JSON 或类型名。
     */
    public static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_float($value) || is_array($value) || $value instanceof \JsonSerializable) {
            try {
                return json_encode($value, self::JSON_FLAGS);
            } catch (\JsonException) {
                // 写成文本只为日志与描述，编码不了时退到类型名，让调用方仍能看到是什么值
                return get_debug_type($value);
            }
        }

        return get_debug_type($value);
    }

    private static function isJsonAt(mixed $value, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        if ($value === null || is_bool($value) || is_int($value) || $value instanceof \JsonSerializable) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_string($value)) {
            return preg_match('//u', $value) === 1;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                return false;
            }
            if (!self::isJsonAt($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
