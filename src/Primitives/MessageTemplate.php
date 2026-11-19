<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\Numbers;
use XiHan\Framework\Primitives\Internal\Values;

/**
 * 消息键的文法与模板的格式化（RFC-0001、RFC-0010「消息键」「模板」，ADR-0233）。
 *
 * 消息键是 `<domain>.<segment>[.<segment>]...`，每段 `[a-z][a-z0-9_]*`，最长 128 个字符。
 *
 * 模板：`{name}` 按名字、`{0}` 按位置取参数（位置参数的名字是 0、1…），名字符合 `^([A-Za-z][A-Za-z0-9_]*|[0-9]+)$`；
 * 第一个 `:` 之后到 `}` 是格式说明符。`{{`、`}}` 是转义的花括号；花括号里不是合法名字的原样输出，
 * 没有闭合的 `{` 及其后的全部文本原样输出，参数值里的花括号不再解释。
 *
 * 值先归到值模型（RFC-0010「模板」，ADR-0236，PHP 的归法见文档页「语言映射」）。格式说明符只认 `^[DdFfNnXx][0-9]{0,2}$`：
 * `D[n]` 整数补零、`X[n]` / `x[n]` 非负整数的十六进制、`F[n]` 定点小数、`N[n]` 带千分位的定点小数（n 缺省 2，
 * 按精确值正好一半取偶）；D、F、N 不分大小写。其余说明符，以及说明符用在不适用的值上（浮点的 D、负数的 X、
 * 字符串、布尔、null、数组与对象），一律按无格式输出，不报错。
 *
 * 区域只改变小数点与分组符，取自规范的固定表（区域名不区分大小写）：de-DE、es-ES、pt-BR 是 `,` 与 `.`，fr-FR 是 `,`
 * 与 U+202F，ru-RU 是 `,` 与 U+00A0，其余区域与不给区域都是 `.` 与 `,`。小数点用在无格式的浮点与 F、N 上，分组符只用在
 * N 上；整数无格式时不分组，D、X 与数组、对象里的数字不受区域影响。
 */
final class MessageTemplate
{
    /** 消息键的最大长度。 */
    public const int MAX_KEY_LENGTH = 128;

    /** 消息键文法；D 修饰符让 $ 只匹配串尾 */
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D';

    /** 占位符名文法：字母开头的名字或纯数字的位置 */
    private const string NAME_PATTERN = '/^([A-Za-z][A-Za-z0-9_]*|[0-9]+)$/D';

    /** 可移植的格式说明符：D、X / x、F、N，可带至多两位精度（可有前导零）；D、F、N 不分大小写 */
    private const string FORMAT_PATTERN = '/^([DdFfNnXx])([0-9]{0,2})$/D';

    /** 内置区域的小数点与分组符，按小写区域名索引；表外的区域按不变文化 */
    private const array SYMBOLS = [
        'de-de' => [',', '.'],
        'es-es' => [',', '.'],
        'pt-br' => [',', '.'],
        'fr-fr' => [',', " "],
        'ru-ru' => [',', " "],
    ];

    private function __construct()
    {
    }

    /**
     * 判断消息键是否合法。
     */
    public static function isValidKey(string $key): bool
    {
        return strlen($key) <= self::MAX_KEY_LENGTH && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * 校验消息键并原样返回。
     *
     * @throws InvalidArgumentException 消息键不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function validateKey(string $key): string
    {
        if (!self::isValidKey($key)) {
            throw new InvalidArgumentException(sprintf(
                "The message key '%s' must match <domain>.<segment>[.<segment>] with at most %d characters.",
                $key,
                self::MAX_KEY_LENGTH,
            ));
        }

        return $key;
    }

    /**
     * 返回消息键的领域段（第一个点之前）；不合法时为 null。
     */
    public static function domainOf(string $key): ?string
    {
        return self::isValidKey($key) ? explode('.', $key, 2)[0] : null;
    }

    /**
     * 列出模板里的占位符名：按出现顺序、去重；转义的花括号与不是合法名字的花括号不算。
     *
     * @return list<string>
     */
    public static function placeholdersOf(string $template): array
    {
        $names = [];
        self::scan($template, static function (string $name) use (&$names): ?string {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }

            return null;
        });

        return $names;
    }

    /**
     * 宽松格式化：缺值的占位符连同格式说明符原样留在文本里，让遗漏可见。
     *
     * @param array<array-key, mixed>|null $arguments 命名参数，或 positional() 给出的位置参数；null 即没有参数
     * @param string|null $locale 区域名，决定小数点与分组符；null 即不变文化
     */
    public static function format(string $template, ?array $arguments, ?string $locale = null): string
    {
        $arguments ??= [];
        $symbols = self::symbols($locale);

        return self::scan(
            $template,
            static fn (string $name, ?string $format): ?string => array_key_exists($name, $arguments)
                ? self::formatValue($arguments[$name], $format, $symbols)
                : null,
        );
    }

    /**
     * 严格格式化：任一占位符缺值即返回 null（消息解析器据此判定解析失败、退回 describe）；值为 null 不算缺值。
     *
     * @param array<array-key, mixed>|null $arguments
     * @param string|null $locale 区域名，决定小数点与分组符；null 即不变文化
     */
    public static function tryFormat(string $template, ?array $arguments, ?string $locale = null): ?string
    {
        $arguments ??= [];
        $symbols = self::symbols($locale);
        $missing = false;
        $text = self::scan(
            $template,
            static function (string $name, ?string $format) use ($arguments, $symbols, &$missing): ?string {
                if (!array_key_exists($name, $arguments)) {
                    $missing = true;

                    return null;
                }

                return self::formatValue($arguments[$name], $format, $symbols);
            },
        );

        return $missing ? null : $text;
    }

    /**
     * 把位置参数转成以 0、1… 为键的参数表，对应模板里的 `{0}`、`{1}`。
     *
     * @return list<mixed>
     */
    public static function positional(mixed ...$values): array
    {
        return array_values($values);
    }

    /**
     * 返回区域的小数点与分组符；表外的区域与 null 按不变文化（`.` 与 `,`）。
     *
     * @return array{string, string}
     */
    public static function numberSymbols(?string $locale): array
    {
        return self::symbols($locale);
    }

    /**
     * 在没有文案时描述一个消息：没有参数时是键本身，否则 `键 (name=value, ...)`，值按文本形式（不变文化）。
     *
     * 参数按名字的序数顺序（逐个 UTF-16 码元比较）排列（RFC-0010，ADR-0235），各语言写出同样的文本。
     *
     * @param array<array-key, mixed>|null $arguments
     */
    public static function describe(string $key, ?array $arguments): string
    {
        if ($arguments === null || $arguments === []) {
            return $key;
        }
        uksort($arguments, static fn (int|string $left, int|string $right): int => Values::compareOrdinal(
            (string) $left,
            (string) $right,
        ));
        $parts = [];
        foreach ($arguments as $name => $value) {
            $parts[] = $name . '=' . Values::text($value);
        }

        return $key . ' (' . implode(', ', $parts) . ')';
    }

    /**
     * 逐字扫描模板：字面量原样输出，每个合法的占位符交给 `$replace`；它返回 null 时占位符原样保留。
     *
     * 用扫描而不是正则：没有闭合的 `{` 要连同其后的全部文本原样输出，花括号里不是名字的要原样输出，
     * 这两条用一个正则表达不清楚。
     *
     * @param \Closure(string, ?string): ?string $replace 参数是占位符名与格式说明符
     */
    private static function scan(string $template, \Closure $replace): string
    {
        $text = '';
        $length = strlen($template);
        $index = 0;
        while ($index < $length) {
            $char = $template[$index];
            if ($char === '}') {
                // 单独的 } 原样输出，}} 是转义
                $text .= '}';
                $index += ($template[$index + 1] ?? '') === '}' ? 2 : 1;
                continue;
            }
            if ($char !== '{') {
                $next = strcspn($template, '{}', $index);
                $text .= substr($template, $index, $next);
                $index += $next;
                continue;
            }
            if (($template[$index + 1] ?? '') === '{') {
                $text .= '{';
                $index += 2;
                continue;
            }
            $close = strpos($template, '}', $index + 1);
            if ($close === false) {
                // 没有闭合的 { 及其后的全部文本原样输出
                $text .= substr($template, $index);
                break;
            }
            $inside = substr($template, $index + 1, $close - $index - 1);
            $colon = strpos($inside, ':');
            $name = $colon === false ? $inside : substr($inside, 0, $colon);
            $format = $colon === false ? null : substr($inside, $colon + 1);
            $replaced = preg_match(self::NAME_PATTERN, $name) === 1 ? $replace($name, $format) : null;
            $text .= $replaced ?? '{' . $inside . '}';
            $index = $close + 1;
        }

        return $text;
    }

    /**
     * @return array{string, string}
     */
    private static function symbols(?string $locale): array
    {
        return $locale === null ? ['.', ','] : self::SYMBOLS[strtolower($locale)] ?? ['.', ','];
    }

    /**
     * 按格式说明符写值；不认的说明符、或说明符用在不适用的值上时按无格式输出。
     *
     * @param array{string, string} $symbols 小数点与分组符
     */
    private static function formatValue(mixed $value, ?string $format, array $symbols): string
    {
        [$decimal, $group] = $symbols;
        $value = Values::model($value);
        $letter = null;
        $precision = null;
        if ($format !== null && preg_match(self::FORMAT_PATTERN, $format, $match) === 1) {
            // D、F、N 不分大小写；X 与 x 定十六进制数字的大小写
            $letter = in_array($match[1], ['X', 'x'], true) ? $match[1] : strtoupper($match[1]);
            $precision = $match[2] === '' ? null : (int) $match[2];
        }
        if (is_int($value)) {
            $text = match (true) {
                $letter === 'D' => Numbers::integer($value, $precision ?? 0),
                ($letter === 'X' || $letter === 'x') && $value >= 0
                    => Numbers::hex($value, $precision ?? 0, $letter === 'X'),
                $letter === 'F' || $letter === 'N' => Numbers::fixed($value, $precision ?? 2, $letter === 'N'),
                default => (string) $value,
            };

            return Values::localize($text, $decimal, $group);
        }
        if (is_float($value)) {
            // 非数与无穷写 F、N 时与无格式相同
            $text = ($letter === 'F' || $letter === 'N') && is_finite($value)
                ? Numbers::fixed($value, $precision ?? 2, $letter === 'N')
                : Numbers::plain($value);

            return Values::localize($text, $decimal, $group);
        }

        return Values::text($value);
    }
}
