<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * 找出 JSON 文本里同一层重复的成员名（RFC-0001「Result」、RFC-0002 解析规则 2，ADR-0235）。
 *
 * json_decode 遇到重复的成员名悄悄取最后一个，解码出的值已经看不出来，只能在原文上核对。只对已能解码的 JSON 调用。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Duplicates
{
    private function __construct()
    {
    }

    /**
     * 返回第一个重复的成员名；没有重复时为 null。
     */
    public static function find(string $json): ?string
    {
        // 每层一个帧：对象记下已见的成员名与「下一个字符串是不是成员名」，数组不记
        /** @var list<array{object: bool, keys: array<array-key, true>, expectKey: bool}> $stack */
        $stack = [];
        $length = strlen($json);
        $index = 0;
        while ($index < $length) {
            $index += strcspn($json, '"{}[],', $index);
            if ($index >= $length) {
                break;
            }
            $char = $json[$index];
            $top = count($stack) - 1;
            if ($char === '"') {
                $end = self::stringEnd($json, $index);
                if ($top >= 0 && $stack[$top]['object'] && $stack[$top]['expectKey']) {
                    $name = json_decode(substr($json, $index, $end - $index + 1));
                    // 成员名在解码后比较："a" 与 "a" 是同一个名字
                    $key = is_string($name) ? $name : '';
                    if (isset($stack[$top]['keys'][$key])) {
                        return $key;
                    }
                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expectKey'] = false;
                }
                $index = $end + 1;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'keys' => [], 'expectKey' => $char === '{'];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($top >= 0 && $stack[$top]['object']) {
                // 对象里的逗号之后是下一个成员名
                $stack[$top]['expectKey'] = true;
            }
            $index++;
        }

        return null;
    }

    /**
     * 返回从 `$start` 处的引号开始的字符串的闭合引号位置。
     */
    private static function stringEnd(string $json, int $start): int
    {
        $index = $start + 1;
        $length = strlen($json);
        while ($index < $length) {
            $index += strcspn($json, '"\\', $index);
            if ($index >= $length || $json[$index] === '"') {
                return $index;
            }
            // 反斜杠转义：跳过它和后一个字符
            $index += 2;
        }

        return $length;
    }
}
