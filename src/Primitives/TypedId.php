<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 类型化标识（RFC-0001 TypedId）的基类。
 *
 * 子类本身就是标签：只区分类型、不承载数据，不同子类的同值标识互不相等。线上编码为底层值的编码，标签不上线。
 *
 * 用法：声明 `final readonly class OrderId extends TypedId {}` 并以 `@extends TypedId<string>` 标注底层值类型，
 * `json_encode(new OrderId('o1'))` 得到 `"o1"`。
 *
 * @template-covariant TValue of int|string
 */
abstract readonly class TypedId implements \JsonSerializable, \Stringable
{
    /**
     * @param TValue $value 底层值（字符串、UUID 文本或整数）
     */
    final public function __construct(public int|string $value)
    {
    }

    /**
     * 判断是否同一标识：同一子类且底层值严格相等。
     *
     * @param TypedId<int|string> $other
     */
    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    /**
     * 编码为底层值。
     *
     * @return TValue
     */
    #[\Override]
    public function jsonSerialize(): int|string
    {
        return $this->value;
    }

    /**
     * 返回底层值的文本。
     */
    #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
