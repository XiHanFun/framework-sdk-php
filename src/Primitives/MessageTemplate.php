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
 * 没有闭合的 `{` 及其后的全部文本原样输出，参数值里的花括号不再解释。可移植的格式说明符按不变文化解释：
 * `D[n]` 整数补零、`X[n]` / `x[n]` 十六进制、`F[n]` 定点小数、`N[n]` 带千分位的定点小数（n 缺省 2，
 * 按精确值正好一半取偶）；其余说明符按无格式输出，不报错。
 */
final class MessageTemplate
{
    /** 消息键的最大长度。 */
    public const int MAX_KEY_LENGTH = 128;

    /** 消息键文法；D 修饰符让 $ 只匹配串尾 */
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D';

    /** 占位符名文法：字母开头的名字或纯数字的位置 */
    private const string NAME_PATTERN = '/^([A-Za-z][A-Za-z0-9_]*|[0-9]+)$/D';

    /** 可移植的格式说明符：D、X / x、F、N，可带精度；D、F、N 不分大小写，与 .NET 一致 */
    private const string FORMAT_PATTERN = '/^([DdXxFfNn])([0-9]{0,3})$/D';

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
     */
    public static function format(string $template, ?array $arguments): string
    {
        $arguments ??= [];

        return self::scan(
            $template,
            static fn (string $name, ?string $format): ?string => array_key_exists($name, $arguments)
                ? self::formatValue($arguments[$name], $format)
                : null,
        );
    }

    /**
     * 严格格式化：任一占位符缺值即返回 null（消息解析器据此判定解析失败、退回 describe）；值为 null 不算缺值。
     *
     * @param array<array-key, mixed>|null $arguments
     */
    public static function tryFormat(string $template, ?array $arguments): ?string
    {
        $arguments ??= [];
        $missing = false;
        $text = self::scan(
            $template,
            static function (string $name, ?string $format) use ($arguments, &$missing): ?string {
                if (!array_key_exists($name, $arguments)) {
                    $missing = true;

                    return null;
                }

                return self::formatValue($arguments[$name], $format);
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
     * 在没有文案时描述一个消息：没有参数时是键本身，否则 `键 (name=value, ...)`，按参数给出的顺序，值按文本形式。
     *
     * @param array<array-key, mixed>|null $arguments
     */
    public static function describe(string $key, ?array $arguments): string
    {
        if ($arguments === null || $arguments === []) {
            return $key;
        }
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
     * 按格式说明符写值；不认识的说明符、或说明符用在不适用的值上时按无格式输出。
     */
    private static function formatValue(mixed $value, ?string $format): string
    {
        if ($format === null || preg_match(self::FORMAT_PATTERN, $format, $match) !== 1) {
            return Values::text($value);
        }
        $letter = $match[1];
        $kind = strtoupper($letter);
        $precision = $match[2] === '' ? null : (int) $match[2];

        return match (true) {
            $kind === 'D' && is_int($value) => Numbers::integer($value, $precision ?? 0),
            $kind === 'X' && is_int($value) && $value >= 0 => Numbers::hex($value, $precision ?? 0, $letter === 'X'),
            $kind === 'F' && self::isFinite($value) => Numbers::fixed($value, $precision ?? 2, false),
            $kind === 'N' && self::isFinite($value) => Numbers::fixed($value, $precision ?? 2, true),
            default => Values::text($value),
        };
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isFinite(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }
}
