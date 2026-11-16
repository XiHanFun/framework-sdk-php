<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;

/**
 * 读取线上编码的工具。
 *
 * JSON 文本由 JsonReader 读、归到值模型（RFC-0001「Result」，ADR-0236）：对象是 JsonObject（成员名原样，以 U+0000 开头的也行，
 * `{}` 与 `[]` 不混淆），数组是列表，64 位范围内的整数写法是 int，其余数字是 float（`1e400` 是无穷）。fromWire 一类的入口
 * 另外接受调用方自己用 json_decode 解码的结果：stdClass，或不是非空列表的关联数组。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Wire
{
    private function __construct()
    {
    }

    /**
     * 先过 JSON 文本的共同检查（RFC-0001「JSON 文本」），再按值模型读出；不是 JSON，或任何一层有重复的成员名时抛出。
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $json, string $what): mixed
    {
        $problem = JsonText::check($json);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem->syntax
                ? sprintf('The %s on the wire is not valid JSON.', $what)
                : sprintf("The %s on the wire has a duplicate member at '%s'.", $what, $problem->path));
        }

        return JsonReader::decode($json);
    }

    /**
     * 判断值是不是 JSON 对象：JsonObject、stdClass，或不是非空列表的数组。
     *
     * @phpstan-assert-if-true JsonObject|\stdClass|array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof JsonObject
            || $value instanceof \stdClass
            || (is_array($value) && ($value === [] || !array_is_list($value)));
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

        return match (true) {
            $value instanceof JsonObject => $value->toArray(),
            $value instanceof \stdClass => get_object_vars($value),
            default => $value,
        };
    }
}
