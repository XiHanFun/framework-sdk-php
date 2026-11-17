<?php

declare(strict_types=1);

namespace XiHan\Framework\Examples\Echo;

use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Primitives\RawJson;

/**
 * echoes.relay 的请求：经宿主回调调用的操作与它的请求。
 */
final readonly class RelayEcho
{
    public function __construct(public string $operation, public ?RawJson $payload)
    {
    }

    /**
     * 请求的形状：operation 是必填的字符串，payload 是任意 JSON（原文原样转发）。
     */
    public static function shape(): Shape
    {
        return Shape::object(['operation' => Shape::string()->required(), 'payload' => Shape::any()]);
    }

    /**
     * 用核对过的值创建请求。
     */
    public static function create(mixed $value): self
    {
        /** @var array{operation: string|null, payload?: RawJson|null} $value */
        return new self($value['operation'] ?? '', $value['payload'] ?? null);
    }
}
