<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Http\Http2\Http2Parser;
use Amp\NullCancellation;

/**
 * 客户端的一次 gRPC 调用：写请求消息、读响应消息，读到流的结尾时按尾部的状态结束。
 *
 * 截止时间到了或调用方取消时以 CANCEL 重置流，分别以 DEADLINE_EXCEEDED 与 CANCELLED 结束。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class ClientCall
{
    private readonly Cancellation $cancellation;

    private bool $headersChecked = false;

    private bool $ended = false;

    /**
     * @param Cancellation|null $deadline 截止时间的取消
     * @param Cancellation|null $caller 调用方的取消
     */
    public function __construct(
        private readonly Http2Connection $connection,
        private readonly Http2Stream $stream,
        private readonly ?Cancellation $deadline,
        private readonly ?Cancellation $caller,
    ) {
        $parts = array_values(array_filter([$deadline, $caller]));
        $this->cancellation = match (count($parts)) {
            0 => new NullCancellation(),
            1 => $parts[0],
            default => new CompositeCancellation(...$parts),
        };
    }

    /**
     * 写一条请求消息；$last 为真时同时结束发送。服务端已经以状态结束了调用时不再发送，状态由 receive() 给出。
     *
     * @throws GrpcException 流已重置、连接已断开，或截止时间已到、调用方已取消
     */
    public function send(string $message, bool $last = false): void
    {
        $this->sending(fn () => $this->connection->sendMessage($this->stream, $message, $last));
    }

    /**
     * 结束发送。
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    public function closeSend(): void
    {
        if (!$this->stream->localClosed) {
            $this->sending(fn () => $this->connection->sendData($this->stream, '', true));
        }
    }

    /**
     * 读下一条响应消息；流结束时按尾部的状态返回 null（OK）或抛出。
     *
     * @throws GrpcException 对方以非 OK 的状态结束、应答不是 gRPC、截止时间已到或调用方已取消
     */
    public function receive(): ?string
    {
        if ($this->ended) {
            return null;
        }

        return $this->guard(function (): ?string {
            if (!$this->headersChecked) {
                $this->stream->awaitHeaders($this->cancellation);
                $this->checkHeaders();
            }
            $message = $this->connection->receive($this->stream, $this->cancellation);
            if ($message !== null) {
                return $message;
            }
            $this->ended = true;
            $this->checkStatus();

            return null;
        });
    }

    /**
     * 取消调用：以 CANCEL 重置流。
     */
    public function cancel(): void
    {
        $this->connection->reset($this->stream, Http2Parser::CANCEL);
    }

    /**
     * 执行一步发送：服务端已经结束调用（尾部已到）时发送失败不算错，调用的结果以尾部为准。
     *
     * @param \Closure(): void $step
     *
     * @throws GrpcException
     */
    private function sending(\Closure $step): void
    {
        try {
            $this->guard($step);
        } catch (GrpcException $exception) {
            if ($this->stream->trailers === null) {
                throw $exception;
            }
        }
    }

    /**
     * 执行一步；截止时间或调用方的取消改成对应的状态，并重置流。
     *
     * @template T
     *
     * @param \Closure(): T $step
     *
     * @return T
     *
     * @throws GrpcException
     */
    private function guard(\Closure $step): mixed
    {
        try {
            $this->cancellation->throwIfRequested();

            return $step();
        } catch (CancelledException $exception) {
            $this->connection->reset($this->stream, Http2Parser::CANCEL);

            throw $this->deadline?->isRequested() === true
                ? new GrpcException(GrpcStatus::DEADLINE_EXCEEDED, 'The deadline was exceeded.', [], $exception)
                : new GrpcException(GrpcStatus::CANCELLED, 'The call was canceled.', [], $exception);
        }
    }

    /**
     * 核对响应头部：HTTP 状态是 200、内容类型是 gRPC。
     *
     * @throws GrpcException
     */
    private function checkHeaders(): void
    {
        $this->headersChecked = true;
        if (!$this->stream->headersReceived) {
            throw $this->stream->failure()
                ?? new GrpcException(GrpcStatus::INTERNAL, 'The server closed the stream without sending headers.');
        }
        $status = $this->stream->pseudo[':status'] ?? '';
        if ($status !== '200') {
            throw new GrpcException(
                GrpcStatus::fromHttpStatus((int) $status),
                sprintf('The server answered with HTTP status %s.', $status === '' ? 'none' : $status),
            );
        }
        $contentType = $this->stream->header('content-type') ?? '';
        if (!str_starts_with($contentType, 'application/grpc')) {
            throw new GrpcException(
                GrpcStatus::UNKNOWN,
                sprintf("The server answered with the content type '%s'.", $contentType),
            );
        }
    }

    /**
     * 按尾部的 grpc-status 结束：OK 即正常返回，其余抛出。
     *
     * @throws GrpcException
     */
    private function checkStatus(): void
    {
        $trailers = $this->stream->trailers;
        if ($trailers === null) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The server closed the stream without sending trailers.');
        }
        $status = $trailers['grpc-status'][0] ?? null;
        if ($status === null || preg_match('/^[0-9]{1,2}$/D', $status) !== 1) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The trailers carry no valid grpc-status.', $trailers);
        }
        if ((int) $status !== GrpcStatus::OK) {
            throw new GrpcException(
                (int) $status,
                GrpcStatus::decodeMessage($trailers['grpc-message'][0] ?? ''),
                $trailers,
            );
        }
    }
}
