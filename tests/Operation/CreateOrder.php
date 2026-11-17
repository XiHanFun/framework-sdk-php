<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\Shape;

/**
 * 测试用的请求。
 */
final readonly class CreateOrder
{
    public function __construct(public int $quantity)
    {
    }

    /**
     * 请求的形状：quantity 是可选的 32 位整数。
     */
    public static function shape(): Shape
    {
        return Shape::object(['quantity' => Shape::int32()]);
    }

    /**
     * 用核对过的值创建请求；没有 quantity 按 0，让缺少可选成员的请求走到处理函数的校验。
     */
    public static function create(mixed $value): self
    {
        /** @var array{quantity?: int} $value */
        return new self($value['quantity'] ?? 0);
    }
}
