<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 没有值的成功结果的值：`Result<Unit>`。
 *
 * 只有一个实例 `Unit::Value`；线上编码为空对象 `{}`，与 Go SDK 的 `Result[Unit]` 一致。
 */
enum Unit implements \JsonSerializable
{
    case Value;

    /**
     * 编码为空对象。
     */
    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }
}
