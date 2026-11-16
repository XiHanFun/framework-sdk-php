<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\Shape;

/**
 * payload-reasons 向量的 counts 请求：成员值都是 32 位整数的对象（整数字典）。
 */
final readonly class Counts
{
    /**
     * @param array<array-key, int> $values
     */
    public function __construct(public array $values)
    {
    }

    /**
     * 请求的形状。
     */
    public static function shape(): Shape
    {
        return Shape::mapOf(Shape::int32());
    }

    /**
     * 用核对过的值创建请求。
     */
    public static function create(mixed $value): self
    {
        /** @var array<array-key, int> $value */
        return new self($value);
    }
}
