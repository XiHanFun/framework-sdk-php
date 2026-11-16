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
     * 判断值是否 JSON 可表示：null、布尔、整数、有限浮点数、UTF-8 字符串、由它们组成的数组或 stdClass，或 JsonSerializable。
     */
    public static function isJson(mixed $value): bool
    {
        return self::isJsonAt($value, 0);
    }

    /**
     * 把值写成面向人的文本（RFC-0010「模板」的值的文本形式）。
     *
     * 字符串原样，整数十进制，布尔写 true / false，null 写空串，浮点见 Numbers::plain；
     * 规范不作规定的其余值：枚举取值或名字，Stringable 取文本，数组与 JsonSerializable 按 JSON，其他写类型名。
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
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return Numbers::plain($value);
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
        if (is_array($value) || $value instanceof \JsonSerializable || $value instanceof \stdClass) {
            try {
                return json_encode($value, self::JSON_FLAGS);
            } catch (\JsonException) {
                // 写成文本只为日志与描述，编码不了时退到类型名，让调用方仍能看到是什么值
                return get_debug_type($value);
            }
        }

        return get_debug_type($value);
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
        $leftUnits = self::utf16Units($left);
        $rightUnits = self::utf16Units($right);
        if ($leftUnits === null || $rightUnits === null) {
            return strcmp($left, $right) <=> 0;
        }
        $length = min(count($leftUnits), count($rightUnits));
        for ($index = 0; $index < $length; $index++) {
            if ($leftUnits[$index] !== $rightUnits[$index]) {
                return $leftUnits[$index] <=> $rightUnits[$index];
            }
        }

        return count($leftUnits) <=> count($rightUnits);
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
        if ($value === null || is_bool($value) || is_int($value) || $value instanceof \JsonSerializable) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
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
