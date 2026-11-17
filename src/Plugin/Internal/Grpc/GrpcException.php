<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

/**
 * 以 gRPC 状态结束的调用：服务端据此写尾部，客户端据此把对方的状态交给调用方。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class GrpcException extends \RuntimeException
{
    /**
     * @param int $status gRPC 状态码（GrpcStatus）
     * @param string $detail 状态说明（grpc-message 的原文）
     * @param array<string, list<string>> $trailers 客户端收到的尾部元数据
     */
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
        public readonly array $trailers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(GrpcStatus::name($status) . ': ' . $detail, 0, $previous);
    }
}
