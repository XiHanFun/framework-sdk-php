<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Http2\Http2Parser;

/**
 * 服务端的一次 gRPC 调用：读请求消息、写响应消息、以状态结束（grpc/doc/PROTOCOL-HTTP2.md）。
 *
 * 响应头部随第一条消息写出；没有写过消息就结束的调用写只有尾部的应答。结束时对端还在发送的，按 RFC 9113 §8.1 以
 * NO_ERROR 重置流，让对端停止发送。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class ServerCall
{
    private bool $finished = false;

    public function __construct(private readonly Http2Connection $connection, private readonly Http2Stream $stream)
    {
    }

    /**
     * 返回请求路径，如 `/xihan.plugin.v1.Plugin/Handshake`。
     */
    public function path(): string
    {
        return $this->stream->pseudo[':path'] ?? '';
    }

    /**
     * 返回请求方法。
     */
    public function method(): string
    {
        return $this->stream->pseudo[':method'] ?? '';
    }

    /**
     * 返回请求元数据的全部值（键是小写）。
     *
     * @return list<string>
     */
    public function metadata(string $name): array
    {
        return $this->stream->headers[$name] ?? [];
    }

    /**
     * 返回 grpc-timeout 给出的剩余秒数；没有或写法不合法时为 null。
     */
    public function timeout(): ?float
    {
        $value = $this->stream->header('grpc-timeout');

        return $value === null ? null : GrpcStatus::parseTimeout($value);
    }

    /**
     * 返回调用的取消：客户端重置流或连接断开时触发。
     */
    public function cancellation(): Cancellation
    {
        return $this->stream->cancellation();
    }

    /**
     * 读下一条请求消息；客户端已结束发送时为 null。
     *
     * @throws GrpcException 流已重置，或消息不合法
     */
    public function receive(?Cancellation $cancellation = null): ?string
    {
        try {
            return $this->connection->receive($this->stream, $cancellation);
        } catch (CancelledException $exception) {
            throw new GrpcException(GrpcStatus::CANCELLED, 'The call was canceled.', [], $exception);
        }
    }

    /**
     * 写一条响应消息；第一条之前先写响应头部。
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    public function send(string $message): void
    {
        if (!$this->stream->headersSent) {
            $this->connection->sendHeaders($this->stream, [
                [':status', '200'],
                ['content-type', 'application/grpc'],
            ], false);
        }
        $this->connection->sendMessage($this->stream, $message, false);
    }

    /**
     * 以 gRPC 状态结束调用；重复调用什么都不做。流已重置或连接已断开时没有人收得到尾部，静默结束。
     */
    public function finish(int $status = GrpcStatus::OK, string $detail = ''): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $trailers = [['grpc-status', (string) $status]];
        if ($detail !== '') {
            $trailers[] = ['grpc-message', GrpcStatus::encodeMessage($detail)];
        }
        try {
            $this->connection->sendHeaders(
                $this->stream,
                $this->stream->headersSent
                    ? $trailers
                    : [[':status', '200'], ['content-type', 'application/grpc'], ...$trailers],
                true,
            );
        } catch (GrpcException) {
            return;
        }
        if (!$this->stream->remoteClosed) {
            $this->connection->reset($this->stream, Http2Parser::GRACEFUL_SHUTDOWN);
        }
    }

    /**
     * 不是 gRPC 请求（方法或内容类型不对）：只回 HTTP 状态。
     */
    public function reject(int $httpStatus): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            $this->connection->sendHeaders($this->stream, [[':status', (string) $httpStatus]], true);
        } catch (GrpcException) {
            return;
        }
        if (!$this->stream->remoteClosed) {
            $this->connection->reset($this->stream, Http2Parser::GRACEFUL_SHUTDOWN);
        }
    }

    /**
     * 判断调用是否已结束。
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }
}
