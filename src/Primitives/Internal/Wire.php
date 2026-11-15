<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 读取线上编码的工具：同时接受 `json_decode` 的两种结果。
 *
 * 对象模式（stdClass）能区分空对象与空数组，按它解码最精确；关联数组模式里 `{}` 与 `[]` 都是空数组，只能按对象处理。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Wire
{
    private function __construct()
    {
    }

    /**
     * 以对象模式解码 JSON 文本；不是 JSON 时抛出。
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $json, string $what): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('The %s on the wire is not valid JSON.', $what),
                previous: $exception,
            );
        }
    }

    /**
     * 判断解码出的值是不是 JSON 对象：stdClass，或不是非空列表的数组。
     *
     * @phpstan-assert-if-true \stdClass|array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    /**
     * 取 JSON 对象的成员；不是对象时抛出。
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function members(mixed $value, string $message): array
    {
        if (!self::isObject($value)) {
            throw new InvalidArgumentException($message);
        }

        return $value instanceof \stdClass ? get_object_vars($value) : $value;
    }
}
