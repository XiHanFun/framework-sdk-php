<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\JsonPath;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Primitives\Result;

/**
 * payload-reasons 向量的 order 请求：{ lines: [ { quantity: 整数 } ] }。
 */
final readonly class Order
{
    /**
     * @param list<int> $quantities 每一行的数量
     */
    public function __construct(public array $quantities)
    {
    }

    /**
     * 解码；沿着结构记下路径，第一个类型不符的值以它的路径报载荷不合法。
     *
     * @param array<string, mixed> $json
     *
     * @return Result<self>
     */
    public static function decode(array $json): Result
    {
        $linesPath = JsonPath::member(JsonPath::ROOT, 'lines');
        $lines = $json['lines'] ?? [];
        if (!is_array($lines) || !array_is_list($lines)) {
            return self::invalid($linesPath);
        }
        $quantities = [];
        foreach ($lines as $index => $line) {
            $linePath = JsonPath::index($linesPath, $index);
            if (!is_array($line) || ($line !== [] && array_is_list($line))) {
                return self::invalid($linePath);
            }
            $quantity = $line['quantity'] ?? 0;
            if (!is_int($quantity)) {
                return self::invalid(JsonPath::member($linePath, 'quantity'));
            }
            $quantities[] = $quantity;
        }

        return Result::ok(new self($quantities));
    }

    /**
     * @return Result<never>
     */
    private static function invalid(string $path): Result
    {
        return Result::fail(OperationErrors::invalidPayload('orders.check', $path));
    }
}
