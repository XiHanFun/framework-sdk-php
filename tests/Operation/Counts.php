<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\JsonPath;
use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Primitives\Result;

/**
 * payload-reasons 向量的 counts 请求：成员值都是整数的对象（整数字典）。
 */
final readonly class Counts
{
    /**
     * @param array<string, int> $values
     */
    public function __construct(public array $values)
    {
    }

    /**
     * 解码；值不是整数时以它的路径报载荷不合法。
     *
     * @param array<string, mixed> $json
     *
     * @return Result<self>
     */
    public static function decode(array $json): Result
    {
        $values = [];
        foreach ($json as $name => $value) {
            // PHP 把纯数字的成员名转成整数键，路径里按原来的字符串写
            $name = (string) $name;
            if (!is_int($value)) {
                return Result::fail(OperationErrors::invalidPayload('counts.check', JsonPath::member(JsonPath::ROOT, $name)));
            }
            $values[$name] = $value;
        }

        return Result::ok(new self($values));
    }
}
