<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 列表的运行期核对：公开类型的构造函数收到的数组不一定经过静态检查，清单、操作契约与插件运行时的选项共用这一处。
 *
 * 列表是键从 0 起连续的数组；不是列表或有一项类型不对即抛出，消息是「`$subject` must be a list of …」。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Lists
{
    private function __construct()
    {
    }

    /**
     * 核对数组是给定类型的列表，原样返回。
     *
     * @template T of object
     *
     * @param class-string<T> $class 每一项的类型
     * @param array<array-key, mixed> $values
     * @param string $subject 异常消息的主语，如 `The field 'provides'`
     *
     * @return list<T>
     *
     * @throws InvalidArgumentException 不是列表或有一项不是 `$class`（PRIMITIVES_VALIDATION_001）
     */
    public static function of(string $class, array $values, string $subject): array
    {
        $checked = [];
        foreach ($values as $index => $value) {
            if ($index !== count($checked) || !$value instanceof $class) {
                throw new InvalidArgumentException(sprintf('%s must be a list of %s.', $subject, $class));
            }
            $checked[] = $value;
        }

        return $checked;
    }

    /**
     * 核对数组是字符串列表，原样返回。
     *
     * @param array<array-key, mixed> $values
     * @param string $subject 异常消息的主语，如 `The field 'permissions'`
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException 不是列表或有一项不是字符串（PRIMITIVES_VALIDATION_001）
     */
    public static function strings(array $values, string $subject): array
    {
        $checked = [];
        foreach ($values as $index => $value) {
            if ($index !== count($checked) || !is_string($value)) {
                throw new InvalidArgumentException(sprintf('%s must be a list of strings.', $subject));
            }
            $checked[] = $value;
        }

        return $checked;
    }
}
