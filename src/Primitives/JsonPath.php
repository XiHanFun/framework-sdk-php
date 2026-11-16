<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 出错位置的 JSON 路径（RFC-0011 规则 2，ADR-0235，照 System.Text.Json 的写法）。
 *
 * 载荷不合法的 `reason`、JSON 文本里第一处重复的成员（RFC-0001「JSON 文本」）与清单不合 Schema 的位置
 * （RFC-0002 解析规则 2）都用这种写法。
 *
 * 从 `$` 起，成员写 `.名字`，数组下标写 `[i]`，如 `$.lines[1].quantity`。成员名含 `.`、空格、`'`、`/`、`"`、`[`、`]`、
 * `(`、`)`、`\`、制表符、换行、回车、换页、退格、U+0085、U+2028、U+2029 之一时写 `['名字']`，名字原样、不转义；
 * 其余字符照写，空的成员名写成 `$.`。
 */
final class JsonPath
{
    /** 根。 */
    public const string ROOT = '$';

    /** 需要写成 ['名字'] 的字符 */
    private const string BRACKETED = "/[. '\\/\"\\[\\]()\\\\\\t\\n\\r\\f\\x08]|\\x{85}|\\x{2028}|\\x{2029}/u";

    private function __construct()
    {
    }

    /**
     * 返回成员的路径。
     */
    public static function member(string $path, string $name): string
    {
        return preg_match(self::BRACKETED, $name) === 1 ? $path . "['" . $name . "']" : $path . '.' . $name;
    }

    /**
     * 返回数组下标的路径。
     */
    public static function index(string $path, int $index): string
    {
        return $path . '[' . $index . ']';
    }
}
