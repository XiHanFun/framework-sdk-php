<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\RawJson;

/**
 * 写 JSON 文本：线上编码按值模型写数字（RFC-0001「Result」，ADR-0236），字符串按 RFC-0010「模板」的写法转义。
 *
 * 整数值的浮点要让对方读回浮点（`2.0`、`-0.0`、`1.0e+17`），所以 json_encode 带 JSON_PRESERVE_ZERO_FRACTION，并临时把
 * serialize_precision 设成 -1（最短往返）；非有限的浮点在 JSON 里写不出，写成字符串 `NaN`、`Infinity`、`-Infinity`。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Json
{
    /** json_encode 的选项：斜杠与非 ASCII 字符原样，整数值的浮点保留小数点 */
    public const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /** 展开值时的最大嵌套深度，与 json_encode 的缺省深度一致，避免无界递归 */
    private const int MAX_DEPTH = 512;

    /** @var array<string, string>|null 字符串里要转义的字符 */
    private static ?array $escapes = null;

    private function __construct()
    {
    }

    /**
     * 把值写成紧凑的 JSON 文本；RawJson 原样放回读到的原文（RFC-0011 规则 2）。
     *
     * @param bool $nonFiniteAsText 非有限的浮点写成字符串（无类型的值：错误细节、Result 的值）；为假时写不出，抛出
     *     （类型化的响应：NaN 与无穷不写成 null 或字符串，RFC-0011 规则 10「编码」）
     *
     * @throws \JsonException 值写不成 JSON（非法 UTF-8、非有限的浮点、不支持的值、嵌套过深）
     */
    public static function encode(mixed $value, bool $nonFiniteAsText = true): string
    {
        $raw = [];
        $token = bin2hex(random_bytes(8));
        $prepared = self::prepare($value, 0, $nonFiniteAsText, $token, $raw);
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode($prepared, self::FLAGS);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }

        // json_encode 不能写原文：先写成只在这次调用里出现的占位字符串，再换回原文
        return $raw === [] ? $json : strtr($json, $raw);
    }

    /**
     * 把值里的非有限浮点换成字符串，JsonSerializable 先展开（RawJson 留给 Json::encode 或它自己的 jsonSerialize），其余原样；
     * 数组与 stdClass 保持原来的形态。
     */
    public static function writable(mixed $value, int $depth = 0): mixed
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : Numbers::plain($value);
        }
        if ($depth >= self::MAX_DEPTH || $value instanceof RawJson) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return self::writable($value->jsonSerialize(), $depth + 1);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::writable($item, $depth + 1);
            }

            return $value;
        }
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = self::writable($item, $depth + 1);
            }

            return $copy;
        }

        return $value;
    }

    /**
     * 为 json_encode 准备值：RawJson 换成占位字符串并记下原文，JsonSerializable 展开，普通对象取公开属性（与 json_encode
     * 一样），非有限的浮点按 `$nonFiniteAsText` 换成字符串或留给 json_encode 报错。
     *
     * @param array<string, string> $raw 占位字符串（连引号）→ 原文
     */
    private static function prepare(mixed $value, int $depth, bool $nonFiniteAsText, string $token, array &$raw): mixed
    {
        if (is_float($value)) {
            return $nonFiniteAsText && !is_finite($value) ? Numbers::plain($value) : $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return $value;
        }
        if ($value instanceof RawJson) {
            $placeholder = "\u{E000}" . $token . count($raw) . "\u{E000}";
            $raw['"' . $placeholder . '"'] = $value->json;

            return $placeholder;
        }
        if ($value instanceof \JsonSerializable) {
            return self::prepare($value->jsonSerialize(), $depth + 1, $nonFiniteAsText, $token, $raw);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::prepare($item, $depth + 1, $nonFiniteAsText, $token, $raw);
            }

            return $value;
        }
        // 枚举与闭包交给 json_encode：BackedEnum 写值，其余写不出
        if (!is_object($value) || $value instanceof \UnitEnum || $value instanceof \Closure) {
            return $value;
        }
        $copy = new \stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            $copy->{$key} = self::prepare($item, $depth + 1, $nonFiniteAsText, $token, $raw);
        }

        return $copy;
    }

    /**
     * 写 JSON 字符串（RFC-0010「模板」，ADR-0236；清单写出用同一种写法）：`"` 与 `\` 加反斜杠，退格、换页、换行、回车、
     * 制表符写短写，其余 U+0000–U+001F 写小写十六进制的 `\u001f`，别的字符（含 `/`、U+007F–U+009F、U+2028 与非 ASCII）原样。
     *
     * PHP 的字符串是 UTF-8 字节，合法的 UTF-8 里没有孤立的代理项。
     */
    public static function string(string $text): string
    {
        return '"' . strtr($text, self::$escapes ??= self::escapes()) . '"';
    }

    /**
     * @return array<string, string>
     */
    private static function escapes(): array
    {
        $escapes = [];
        for ($code = 0; $code < 0x20; $code++) {
            $escapes[chr($code)] = sprintf('\\u%04x', $code);
        }

        return array_replace($escapes, [
            '"' => '\\"',
            '\\' => '\\\\',
            "\x08" => '\\b',
            "\f" => '\\f',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }
}
