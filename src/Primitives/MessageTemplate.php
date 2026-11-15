<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\Values;

/**
 * 消息键的文法与模板的格式化（RFC-0001、RFC-0010）。
 *
 * 消息键是 `<domain>.<segment>[.<segment>]...`，每段 `[a-z][a-z0-9_]*`；模板里 `{name}` 按名字、`{0}` 按位置取参数，
 * 可带 `:format`（以 `%` 开头时按 sprintf 解释，如 `{count:%03d}`；其他写法按值的文本输出），`{{`、`}}` 转义为花括号。
 */
final class MessageTemplate
{
    /** 消息键文法（2026-10-28 起首段与后段同形，允许下划线）；D 修饰符让 $ 只匹配串尾 */
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D';

    /** 转义的花括号，或 {name} / {0} / {name:format} 占位符 */
    private const string PLACEHOLDER_PATTERN = '/\{\{|\}\}|\{([A-Za-z_][A-Za-z0-9_]*|[0-9]+)(?::([^{}]*))?\}/';

    private function __construct()
    {
    }

    /**
     * 判断消息键是否合法。
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * 校验消息键并原样返回。
     *
     * @throws InvalidArgumentException 消息键不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function validateKey(string $key): string
    {
        if (!self::isValidKey($key)) {
            throw new InvalidArgumentException(
                sprintf("The message key '%s' must match <domain>.<segment>[.<segment>].", $key),
            );
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
     * 列出模板里的占位符名：按出现顺序、去重，转义的花括号不算。
     *
     * @return list<string>
     */
    public static function placeholdersOf(string $template): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $names = [];
        foreach ($matches as $match) {
            if ($match[1] !== null && !in_array($match[1], $names, true)) {
                $names[] = $match[1];
            }
        }

        return $names;
    }

    /**
     * 用参数填充模板；缺参数的占位符与无法解释的 `%` 格式原样保留。
     *
     * @param array<array-key, mixed> $arguments 命名参数，或 positional() 给出的位置参数
     */
    public static function format(string $template, array $arguments): string
    {
        return self::render($template, $arguments, false) ?? $template;
    }

    /**
     * 用参数填充模板；有占位符缺参数时返回 null（消息解析器据此判定解析失败）。
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function tryFormat(string $template, array $arguments): ?string
    {
        return self::render($template, $arguments, true);
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
     * 在没有文案时描述一个消息：`键 (k=v, ...)`，参数按键的字符串序排列；没有参数时只是键。
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function describe(string $key, array $arguments): string
    {
        if ($arguments === []) {
            return $key;
        }
        ksort($arguments, SORT_STRING);
        $parts = [];
        foreach ($arguments as $name => $value) {
            $parts[] = $name . '=' . Values::text($value);
        }

        return $key . ' (' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private static function render(string $template, array $arguments, bool $strict): ?string
    {
        $missing = false;
        $rendered = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $match) use ($arguments, &$missing): string {
                if ($match[0] === '{{') {
                    return '{';
                }
                if ($match[0] === '}}') {
                    return '}';
                }
                $name = (string) $match[1];
                if (!array_key_exists($name, $arguments)) {
                    $missing = true;

                    return $match[0];
                }
                $value = $arguments[$name];
                $format = $match[2] ?? '';
                if (!str_starts_with($format, '%')) {
                    return Values::text($value);
                }
                try {
                    return sprintf($format, is_scalar($value) || $value === null ? $value : Values::text($value));
                } catch (\ValueError | \ArgumentCountError) {
                    // 格式写错是模板作者的问题；保留占位符让它在文案里可见，而不是让取消息的调用失败
                    return $match[0];
                }
            },
            $template,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );

        return $rendered === null || ($strict && $missing) ? null : $rendered;
    }
}
