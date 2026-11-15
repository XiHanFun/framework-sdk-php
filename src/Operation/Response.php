<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * XGI 响应（RFC-0011「XGI 消息」）：结果与成功时响应类型的 UTF-8 JSON。
 */
final readonly class Response
{
    /**
     * @param Result<Unit> $outcome 结果
     * @param string|null $payload 成功时响应的 UTF-8 JSON；失败为 null
     */
    public function __construct(public Result $outcome, public ?string $payload)
    {
    }
}
