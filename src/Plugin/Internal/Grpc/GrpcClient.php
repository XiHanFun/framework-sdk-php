<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Socket\ConnectException;
use Amp\Socket\DnsSocketConnector;
use Amp\TimeoutCancellation;

/**
 * gRPC 客户端：对一个端点保持一条 HTTP/2 连接，断开或对方 GOAWAY 后在下一次调用时重连。
 *
 * 每次调用带构造时给的元数据（插件回调宿主时是会话令牌，handshake.md §4）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class GrpcClient
{
    /** 建立连接的超时（秒） */
    private const float CONNECT_TIMEOUT = 10.0;

    private ?Http2Connection $connection = null;

    /** @var DeferredFuture<Http2Connection>|null 正在建立的连接：并发的调用等同一次连接 */
    private ?DeferredFuture $connecting = null;

    /**
     * @param string $uri 端点：`unix:///path` 或 `tcp://127.0.0.1:port`
     * @param string $authority 请求的 `:authority`
     * @param array<string, string|list<string>> $metadata 每次调用都带的元数据；列表即同名的多个值
     * @param string $userAgent 请求的 user-agent
     */
    public function __construct(
        private readonly string $uri,
        private readonly string $authority,
        private readonly array $metadata,
        private readonly string $userAgent,
    ) {
    }

    /**
     * 发起一次调用：写出请求头部，返回调用；消息由调用方写读。
     *
     * @param string $path 方法路径，如 `/xihan.plugin.v1.Host/Invoke`
     * @param array<string, string|list<string>> $metadata 这次调用另带的元数据
     * @param float|null $timeout 截止时间（秒）；写进 grpc-timeout，到了即以 DEADLINE_EXCEEDED 结束
     *
     * @throws GrpcException 连不上（UNAVAILABLE）或截止时间已到
     */
    public function call(
        string $path,
        array $metadata = [],
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): ClientCall {
        $deadline = $timeout === null ? null : new TimeoutCancellation(max(0.0, $timeout));
        if ($timeout !== null && $timeout <= 0.0) {
            throw new GrpcException(GrpcStatus::DEADLINE_EXCEEDED, 'The deadline passed before the call started.');
        }
        try {
            $connection = $this->connection($cancellation);
        } catch (CancelledException $exception) {
            // 等别的调用正在建立的连接时调用方取消了：与调用中途取消一样以 CANCELLED 结束，不把 amphp 的异常交给调用方
            throw new GrpcException(GrpcStatus::CANCELLED, 'The call was canceled.', [], $exception);
        }
        $stream = $connection->openStream();
        $headers = [
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', $this->authority],
            ['content-type', 'application/grpc'],
            ['te', 'trailers'],
            ['user-agent', $this->userAgent],
        ];
        if ($timeout !== null) {
            $headers[] = ['grpc-timeout', GrpcStatus::formatTimeout($timeout)];
        }
        foreach ([...$this->metadata, ...$metadata] as $name => $values) {
            // 同名的多个值各写一个头部字段（gRPC 的元数据可以有多个值）
            foreach ((array) $values as $value) {
                $headers[] = [strtolower($name), $value];
            }
        }
        $connection->sendHeaders($stream, $headers, false);

        return new ClientCall($connection, $stream, $deadline, $cancellation);
    }

    /**
     * 一元调用：写一条请求、读一条响应。
     *
     * @param array<string, string|list<string>> $metadata
     *
     * @throws GrpcException 对方以非 OK 的状态结束、连不上、截止时间已到或调用方已取消
     */
    public function unary(
        string $path,
        string $request,
        array $metadata = [],
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): string {
        $call = $this->call($path, $metadata, $timeout, $cancellation);
        $call->send($request, true);
        $response = $call->receive();
        // 读到结尾才有尾部里的状态；一元调用只能有一条响应
        if ($response === null) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The server sent no response message.');
        }
        if ($call->receive() !== null) {
            $call->cancel();

            throw new GrpcException(GrpcStatus::INTERNAL, 'The server sent more than one response message.');
        }

        return $response;
    }

    /**
     * 断开连接。
     */
    public function close(): void
    {
        $this->connection?->close(new GrpcException(GrpcStatus::CANCELLED, 'The client was closed.'));
        $this->connection = null;
    }

    /**
     * 返回可用的连接：没有或已不可用时建立新的。
     *
     * @throws GrpcException 连不上
     * @throws CancelledException 等别的调用正在建立的连接时调用方取消了
     */
    private function connection(?Cancellation $cancellation): Http2Connection
    {
        if ($this->connection !== null && $this->connection->isUsable()) {
            return $this->connection;
        }
        if ($this->connecting !== null) {
            return $this->connecting->getFuture()->await($cancellation);
        }
        /** @var DeferredFuture<Http2Connection> $connecting */
        $connecting = new DeferredFuture();
        $this->connecting = $connecting;
        // 别的 Fiber 也在等这次连接：失败时它们同样收到异常，Future 不会因没人取值而报未处理的错误
        $connecting->getFuture()->ignore();
        try {
            // 只连一次：本机端点连不上即对方没有在监听，amphp 缺省的退避重试只会让回调多等几秒才失败
            $timeout = new TimeoutCancellation(self::CONNECT_TIMEOUT);
            $socket = (new DnsSocketConnector())->connect($this->uri, null, $timeout);
            $connection = new Http2Connection($socket, false);
            $connection->start();
            $this->connection = $connection;
            $connecting->complete($connection);

            return $connection;
        } catch (ConnectException | CancelledException $exception) {
            $failure = new GrpcException(
                GrpcStatus::UNAVAILABLE,
                sprintf("Cannot connect to '%s': %s", $this->uri, $exception->getMessage()),
                [],
                $exception,
            );
            $connecting->error($failure);

            throw $failure;
        } catch (GrpcException $exception) {
            $connecting->error($exception);

            throw $exception;
        } finally {
            $this->connecting = null;
        }
    }
}
