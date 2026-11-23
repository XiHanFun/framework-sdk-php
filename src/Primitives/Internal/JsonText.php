<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\JsonPath;

/**
 * XiHan 读取 JSON 文本的共同检查（RFC-0001「JSON 文本」，ADR-0236）。
 *
 * Result 与 Error 的线上编码、操作载荷与清单都先过这一遍：语法按 RFC 8259（不允许注释、尾逗号、BOM 与多个根值），
 * 嵌套不超过 64 层，文本是合法的 UTF-8，字符串没有孤立的代理项转义，任何一层都不能有重复的成员名。
 * 语法错误不论出现在哪里都优先于重复成员，位置只写 `$`；重复成员写第一处重复（第二次出现）的路径。
 *
 * 语法交给 json_decode（它按 RFC 8259 解析，并拒绝原始控制字符、非法转义与孤立的代理项），非法 UTF-8 由 PCRE 的
 * UTF-8 校验拒绝（含过长编码与编码成 UTF-8 的代理项）；json_decode 遇到重复成员悄悄取最后一个，重复只能在原文上找。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class JsonText
{
    /** 最大嵌套层数，最外层的对象或数组是第 1 层。 */
    public const int MAX_DEPTH = 64;

    /** UTF-8 的 BOM：check() 把它当作语法错误，读文件与清单原文的入口先用 stripBom() 去掉。 */
    public const string UTF8_BOM = "\xEF\xBB\xBF";

    private function __construct()
    {
    }

    /**
     * 去掉开头的一个 UTF-8 BOM，没有时原样返回：清单文件（RFC-0002 解析规则 2）与握手发出的清单原文
     * （handshake.md §3 第 3 条）先去掉它再检查，文本里别处的 BOM 照常是语法错误。
     */
    public static function stripBom(string $text): string
    {
        return str_starts_with($text, self::UTF8_BOM) ? substr($text, strlen(self::UTF8_BOM)) : $text;
    }

    /**
     * 检查 JSON 文本；通过时为 null，否则是第一个问题。
     */
    public static function check(string $text): ?JsonTextProblem
    {
        if (preg_match('//u', $text) !== 1) {
            return new JsonTextProblem(true, JsonPath::ROOT);
        }
        // json_decode 的深度比层数多算一层：64 层的数组要给 65
        json_decode($text, true, self::MAX_DEPTH + 1);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonTextProblem(true, JsonPath::ROOT);
        }
        $duplicate = self::firstDuplicate($text);

        return $duplicate === null ? null : new JsonTextProblem(false, $duplicate);
    }

    /**
     * 返回第一处重复成员的路径；没有重复时为 null。只对语法已核对过的文本调用。
     */
    private static function firstDuplicate(string $json): ?string
    {
        // 每层一个帧：对象记下已见的成员名、刚读到的成员名与下一个字符串是不是成员名，数组记下当前项的下标
        /** @var list<array{object: bool, path: string, names: array<array-key, true>, expectName: bool, name: string, index: int}> $frames */
        $frames = [];
        $length = strlen($json);
        $offset = 0;
        while ($offset < $length) {
            $offset += strcspn($json, '"{}[],', $offset);
            if ($offset >= $length) {
                break;
            }
            $char = $json[$offset];
            $top = count($frames) - 1;
            if ($char === '"') {
                $end = self::stringEnd($json, $offset);
                if ($top >= 0 && $frames[$top]['object'] && $frames[$top]['expectName']) {
                    $raw = substr($json, $offset + 1, $end - $offset - 1);
                    // 名字按转义还原后比较："a" 与 "a" 是同一个名字
                    $name = str_contains($raw, '\\') ? self::unescape($raw) : $raw;
                    if (isset($frames[$top]['names'][$name])) {
                        return JsonPath::member($frames[$top]['path'], $name);
                    }
                    $frames[$top]['names'][$name] = true;
                    $frames[$top]['name'] = $name;
                    $frames[$top]['expectName'] = false;
                }
                $offset = $end + 1;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $path = match (true) {
                    $top < 0 => JsonPath::ROOT,
                    $frames[$top]['object'] => JsonPath::member($frames[$top]['path'], $frames[$top]['name']),
                    default => JsonPath::index($frames[$top]['path'], $frames[$top]['index']),
                };
                $frames[] = [
                    'object' => $char === '{',
                    'path' => $path,
                    'names' => [],
                    'expectName' => $char === '{',
                    'name' => '',
                    'index' => 0,
                ];
            } elseif ($char === '}' || $char === ']') {
                array_pop($frames);
            } elseif ($top >= 0) {
                // 逗号：对象里之后是下一个成员名，数组里之后是下一项
                if ($frames[$top]['object']) {
                    $frames[$top]['expectName'] = true;
                } else {
                    $frames[$top]['index']++;
                }
            }
            $offset++;
        }

        return null;
    }

    /**
     * 还原带转义的成员名；文本已核对过，解码不会失败。
     */
    private static function unescape(string $raw): string
    {
        $name = json_decode('"' . $raw . '"');

        return is_string($name) ? $name : $raw;
    }

    /**
     * 返回从 `$start` 处的引号开始的字符串的闭合引号位置。
     */
    private static function stringEnd(string $json, int $start): int
    {
        $offset = $start + 1;
        $length = strlen($json);
        while ($offset < $length) {
            $offset += strcspn($json, '"\\', $offset);
            if ($offset >= $length || $json[$offset] === '"') {
                return $offset;
            }
            // 反斜杠转义：跳过它和后一个字符
            $offset += 2;
        }

        return $length;
    }
}
