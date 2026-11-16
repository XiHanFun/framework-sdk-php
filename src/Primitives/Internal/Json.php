<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

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
     * 把值写成紧凑的 JSON 文本。
     *
     * @throws \JsonException 值写不成 JSON（非法 UTF-8、资源、嵌套过深）
     */
    public static function encode(mixed $value): string
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode(self::writable($value, 0), self::FLAGS);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    /**
     * 把值里的非有限浮点换成字符串，JsonSerializable 先展开，其余原样；数组与 stdClass 保持原来的形态。
     */
    public static function writable(mixed $value, int $depth = 0): mixed
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : Numbers::plain($value);
        }
        if ($depth >= self::MAX_DEPTH) {
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
