<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 读取线上编码的工具：同时接受 `json_decode` 的两种结果。
 *
 * 对象模式（stdClass）能区分空对象与空数组，按它解码最精确；关联数组模式里 `{}` 与 `[]` 都是空数组，只能按对象处理。
 * 数字按值模型读（RFC-0001「Result」，ADR-0236）：json_decode 把 64 位范围内的整数写法读成 int、其余读成 float，
 * 与模型一致，整数与浮点再写出时仍可区分（见 Json::encode）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Wire
{
    private function __construct()
    {
    }

    /**
     * 先过 JSON 文本的共同检查（RFC-0001「JSON 文本」），再以对象模式解码；不是 JSON，或任何一层有重复的成员名时抛出。
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
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // 检查已通过：只剩 stdClass 表示不了的成员名（以 U+0000 开头）
            throw new InvalidArgumentException(
                sprintf('The %s on the wire has a member name PHP objects cannot hold.', $what),
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
