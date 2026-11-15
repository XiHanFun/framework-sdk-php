<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

/**
 * 测试用的响应：公开属性即 JSON 字段。
 */
final readonly class OrderCreated
{
    public function __construct(public string $id, public int $quantity)
    {
    }
}
