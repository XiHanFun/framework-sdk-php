<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\Shape;

/**
 * payload-reasons 向量的 order 请求：{ lines?: [ { quantity?: 32 位整数 } ] }。
 */
final readonly class Order
{
    /**
     * @param list<int> $quantities 每一行的数量；没有 quantity 的行按 0
     */
    public function __construct(public array $quantities)
    {
    }

    /**
     * 请求的形状。
     */
    public static function shape(): Shape
    {
        return Shape::object([
            'lines' => Shape::listOf(Shape::object(['quantity' => Shape::int32()])),
        ]);
    }

    /**
     * 用核对过的值创建请求。
     */
    public static function create(mixed $value): self
    {
        /** @var array{lines?: list<array{quantity?: int}>|null} $value */
        $quantities = [];
        foreach ($value['lines'] ?? [] as $line) {
            $quantities[] = $line['quantity'] ?? 0;
        }

        return new self($quantities);
    }
}
