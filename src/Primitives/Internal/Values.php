<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\JsonObject;

/**
 * 原语层内部用的取值工具：判断 JSON 可表示性、值模型与值的文本形式（RFC-0010「模板」）。
 *
 * 值模型（ADR-0236）只有空、布尔、字符串、64 位有符号整数、双精度浮点、数组与对象七种，PHP 的值按下面归入：
 *
 * - null、bool、string、int（PHP 的 int 就是 64 位）、float 原样。
 * - 数组：列表（array_is_list，含空数组）是数组，其余是对象，键写成字符串；JsonObject 与 stdClass 是对象。
 * - 模型之外的值写成 PHP 的文本：BackedEnum 取值的文本，UnitEnum 取名字，Stringable 取 `(string)`，
 *   DateTimeInterface 取 ATOM 格式（`2026-11-16T10:00:00+08:00`），JsonSerializable 取 jsonSerialize() 再归入，
 *   其余对象写类型名。它们不跨语言，插件要跨语言格式化时先转成字符串。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Values
{
    /** 判断可表示性时的最大嵌套深度，与 json_encode 的缺省深度一致，避免无界递归 */
    private const int MAX_DEPTH = 512;

    /** 写成文本的数组与对象的最大层数，更深的容器写成 null */
    private const int MAX_TEXT_DEPTH = 64;

    private function __construct()
    {
    }

    /**
     * 判断值是否 JSON 可表示：null、布尔、整数、浮点数（非有限的写成字符串）、UTF-8 字符串、由它们组成的数组或 stdClass，
     * 或 JsonSerializable。
     */
    public static function isJson(mixed $value): bool
    {
        return self::isJsonAt($value, 0);
    }

    /**
     * 把值归到值模型：结果是 null、bool、string、int、float、数组、JsonObject 或 stdClass。
     */
    public static function model(mixed $value, int $depth = 0): mixed
    {
        if (self::isContainer($value) || $value === null || is_scalar($value)) {
            return $value;
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
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \JsonSerializable && $depth < self::MAX_TEXT_DEPTH) {
            return self::model($value->jsonSerialize(), $depth + 1);
        }

        return get_debug_type($value);
    }

    /**
     * 把值写成无格式的文本（RFC-0010「模板」的值的文本形式，`Describe` 同样适用）。
     *
     * 字符串原样，整数十进制，布尔写 true / false，null 写空串，浮点见 Numbers::plain，数组与对象写成紧凑的 JSON
     * （对象成员按名字的 UTF-16 码元序）。`$decimal` 与 `$group` 是区域的小数点与分组符，只用在顶层的浮点上。
     */
    public static function text(mixed $value, string $decimal = '.', string $group = ','): string
    {
        $value = self::model($value);
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::localize(Numbers::plain($value), $decimal, $group);
        }

        return self::json($value, 1);
    }

    /**
     * 把不变文化的数字文本换成区域的小数点与分组符。
     */
    public static function localize(string $text, string $decimal, string $group): string
    {
        return $decimal === '.' && $group === ',' ? $text : strtr($text, ['.' => $decimal, ',' => $group]);
    }

    /**
     * 按 UTF-16 码元的序数比较两个字符串（.NET 的 StringComparer.Ordinal）。
     *
     * 基本多文种平面内 UTF-8 的字节序与 UTF-16 的码元序相同；平面以外的字符在 UTF-16 里是代理对（0xD800–0xDFFF），
     * 排在 U+E000–U+FFFF 之前，字节序会排在它们之后，所以含非 ASCII 时逐码元比较。不是合法 UTF-8 时按字节比较。
     */
    public static function compareOrdinal(string $left, string $right): int
    {
        if (preg_match('/[\x80-\xFF]/', $left . $right) !== 1) {
            return strcmp($left, $right) <=> 0;
        }

        return strcmp(self::ordinalKey($left), self::ordinalKey($right)) <=> 0;
    }

    /**
     * 返回按字节比较即是 UTF-16 码元序的键：码元按大端写成两个字节；不是合法 UTF-8 时每个字节当作一个码元。
     */
    private static function ordinalKey(string $text): string
    {
        $units = self::utf16Units($text) ?? array_map(ord(...), str_split($text));

        return $units === [] ? '' : pack('n*', ...$units);
    }

    /**
     * 写成紧凑的 JSON：没有空白，对象成员按名字的序数顺序（同名保持原顺序），数字的写法同顶层但不随区域，
     * 超过 64 层的容器写成 null。
     */
    private static function json(mixed $value, int $depth): string
    {
        $value = self::model($value);
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return Json::string($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return Numbers::plain($value);
        }
        if ($depth > self::MAX_TEXT_DEPTH || !self::isContainer($value)) {
            return 'null';
        }
        if (is_array($value) && array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = self::json($item, $depth + 1);
            }

            return '[' . implode(',', $items) . ']';
        }
        $members = [];
        $entries = match (true) {
            $value instanceof JsonObject => $value->toArray(),
            $value instanceof \stdClass => get_object_vars($value),
            default => $value,
        };
        foreach ($entries as $name => $item) {
            $name = (string) $name;
            $members[] = [self::ordinalKey($name), $name, $item];
        }
        // usort 是稳定排序：同名成员（只可能来自不同的整数与字符串键）保持原顺序
        usort($members, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));
        $parts = [];
        foreach ($members as [, $name, $item]) {
            $parts[] = Json::string($name) . ':' . self::json($item, $depth + 1);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * 判断值是不是值模型里的数组或对象：PHP 数组、JsonObject 或 stdClass。
     *
     * @phpstan-assert-if-true array<array-key, mixed>|JsonObject|\stdClass $value
     */
    private static function isContainer(mixed $value): bool
    {
        return is_array($value) || $value instanceof JsonObject || $value instanceof \stdClass;
    }

    /**
     * @return list<int>|null 不是合法 UTF-8 时为 null
     */
    private static function utf16Units(string $text): ?array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return null;
        }
        $units = [];
        foreach ($characters as $character) {
            // 合法的 UTF-8 字符是 1–4 个字节，首字节的高位决定长度
            $length = strlen($character);
            $point = ord($character[0]) & [1 => 0x7F, 2 => 0x1F, 3 => 0x0F, 4 => 0x07][$length];
            for ($index = 1; $index < $length; $index++) {
                $point = ($point << 6) | (ord($character[$index]) & 0x3F);
            }
            if ($point < 0x10000) {
                $units[] = $point;
                continue;
            }
            $point -= 0x10000;
            $units[] = 0xD800 + ($point >> 10);
            $units[] = 0xDC00 + ($point & 0x3FF);
        }

        return $units;
    }

    private static function isJsonAt(mixed $value, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        if ($value === null || is_scalar($value) && !is_string($value) || $value instanceof \JsonSerializable) {
            return true;
        }
        if (is_string($value)) {
            return preg_match('//u', $value) === 1;
        }
        // 线上还原的嵌套对象保持 stdClass，空对象再写出时仍是 {}
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
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
