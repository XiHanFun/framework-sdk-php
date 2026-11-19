<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * 规范所说的空白（RFC-0001「空白字符」，ADR-0245）：U+0009–U+000D、U+0020、U+0085、U+00A0、U+1680、U+2000–U+200A、
 * U+2028、U+2029、U+202F、U+205F、U+3000，共 25 个码位，等于 Unicode 的 White_Space 属性。
 *
 * Error 的文案、插件握手行与回调端点（plugin/handshake.md §2）、读入插件错误的 text（plugin/invocation.md §3）都按它判断。
 * ctype_space、trim 与不带 u 修饰符的 \s 只认 ASCII，带 u 修饰符的 \s 也与这个集合不完全相同，所以逐个写出，按 UTF-8 码位匹配。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Whitespace
{
    /** 25 个空白码位的字符类 */
    private const string SET = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}'
        . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    /** 匹配一个空白码位；不是合法 UTF-8 的文本 preg_match 返回 false */
    public const string PATTERN = '/' . self::SET . '/u';

    /** 整串都是空白（含空串） */
    private const string BLANK = '/\A' . self::SET . '*\z/u';

    private function __construct()
    {
    }

    /**
     * 判断文本是不是空串或只有空白。
     *
     * 不是合法 UTF-8 的文本不算：它的字节不是任何一个空白码位。
     */
    public static function isBlank(string $text): bool
    {
        return preg_match(self::BLANK, $text) === 1;
    }
}
