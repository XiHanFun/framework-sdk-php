<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\Socket\ServerSocket;

use function Amp\async;
use function Amp\Future\await;

/**
 * gRPC 服务端：接受连接，按路径把调用分派给登记的方法。
 *
 * 每个调用先经守卫（插件协议的会话令牌，handshake.md §4），守卫拒绝的调用不进方法；未登记的路径以 UNIMPLEMENTED 结束。
 * 方法抛出的 GrpcException 写成对应的状态，其余异常交给 $onError 并以 INTERNAL 结束，细节不写给对端。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class GrpcServer
{
    /** @var \SplObjectStorage<Http2Connection, null> */
    private readonly \SplObjectStorage $connections;

    private bool $stopping = false;

    /**
     * @param array<string, \Closure(ServerCall): void> $methods 路径 → 方法
     * @param (\Closure(ServerCall): ?GrpcException)|null $guard 守卫：返回异常即以它的状态拒绝
     * @param \Closure(\Throwable, string): void $onError 方法抛出意外的异常时调用（异常、路径）
     */
    public function __construct(
        private readonly ServerSocket $socket,
        private readonly array $methods,
        private readonly ?\Closure $guard,
        private readonly \Closure $onError,
    ) {
        $this->connections = new \SplObjectStorage();
    }

    /**
     * 在后台开始接受连接。
     */
    public function start(): void
    {
        async(function (): void {
            while (!$this->stopping && ($client = $this->socket->accept()) !== null) {
                $connection = new Http2Connection($client, true, $this->dispatch(...));
                $this->connections->offsetSet($connection, null);
                async(function () use ($connection): void {
                    try {
                        $connection->serve();
                    } finally {
                        $this->connections->offsetUnset($connection);
                    }
                })->ignore();
            }
        })->ignore();
    }

    /**
     * 返回在途的调用数。
     */
    public function activeCalls(): int
    {
        $count = 0;
        foreach ($this->connections as $connection) {
            $count += $connection->streamCount();
        }

        return $count;
    }

    /**
     * 优雅停止：不再接受连接与新调用，等在途的调用结束（至多 $grace 秒）后断开全部连接。
     */
    public function shutdown(float $grace): void
    {
        $this->stopping = true;
        $this->socket->close();
        $futures = [];
        foreach ($this->connections as $connection) {
            $futures[] = async(static fn () => $connection->shutdown($grace));
        }
        await($futures);
    }

    /**
     * 处理一个新流：核对是 gRPC 请求、过守卫、分派给方法，保证调用以一个状态结束。
     */
    private function dispatch(Http2Connection $connection, Http2Stream $stream): void
    {
        $call = new ServerCall($connection, $stream);
        if ($call->method() !== 'POST') {
            $call->reject(405);

            return;
        }
        if (!str_starts_with($stream->header('content-type') ?? '', 'application/grpc')) {
            $call->reject(415);

            return;
        }
        try {
            $refusal = $this->guard === null ? null : ($this->guard)($call);
            if ($refusal !== null) {
                $call->finish($refusal->status, $refusal->detail);

                return;
            }
            $method = $this->methods[$call->path()] ?? null;
            if ($method === null) {
                $call->finish(GrpcStatus::UNIMPLEMENTED, sprintf("The method '%s' is not implemented.", $call->path()));

                return;
            }
            $method($call);
            $call->finish();
        } catch (GrpcException $exception) {
            $call->finish($exception->status, $exception->detail);
        } catch (\Throwable $exception) {
            ($this->onError)($exception, $call->path());
            $call->finish(GrpcStatus::INTERNAL, 'The plugin failed to handle the call.');
        }
    }
}
