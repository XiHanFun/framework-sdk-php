<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 没有值的成功结果的值：`Result<Unit>`。
 *
 * 只有一个实例 `Unit::Value`。单独写出时（如作为操作的响应）是空对象 `{}`；`Result<Unit>` 的线上编码只写
 * `{"ok":true}`，不带 value（RFC-0001「Result」）。
 */
enum Unit implements \JsonSerializable
{
    case Value;

    /**
     * 编码为空对象。
     */
    #[\Override]
    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }
}
