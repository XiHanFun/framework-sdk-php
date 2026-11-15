<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Primitives\Result;

/**
 * 测试用的请求。
 */
final readonly class CreateOrder
{
    public function __construct(public int $quantity)
    {
    }

    /**
     * 从请求 JSON 对象解码；没有 quantity 按 0（与 Go 的零值一致），不是整数即载荷不合法。
     *
     * @param array<string, mixed> $json
     *
     * @return Result<self>
     */
    public static function decode(array $json): Result
    {
        $quantity = $json['quantity'] ?? 0;
        if (!is_int($quantity)) {
            return Result::fail(OperationErrors::invalidPayload('orders.create', '$.quantity'));
        }

        return Result::ok(new self($quantity));
    }
}
