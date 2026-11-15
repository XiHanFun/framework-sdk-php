<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

/**
 * XGI 请求（RFC-0011「XGI 消息」）：操作 ID 与请求类型的 UTF-8 JSON。
 */
final readonly class Request
{
    /**
     * @param string $id 操作 ID
     * @param string $payload 请求的 UTF-8 JSON；空串等价于 `{}`
     */
    public function __construct(public string $id, public string $payload = '')
    {
    }
}
