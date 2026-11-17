<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcServer;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Internal\Listener;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Plugin\Protocol\V1;

/**
 * 假的宿主回调服务（Host.Invoke、Host.Publish）：监听回环 TCP，记下收到的回调与它们的元数据，按测试给的函数应答。
 */
final class FakeHost
{
    /** @var list<array{method: string, metadata: V1\InvocationMetadata|null, session: list<string>, timeout: list<string>, request: V1\InvokeRequest|V1\PublishRequest}> */
    public array $received = [];

    private readonly Listener $listener;

    private readonly GrpcServer $server;

    /**
     * @param \Closure(V1\InvokeRequest, ServerCall): void|null $invoke 应答 Host.Invoke；null 即成功并回 `{"from":"host"}`
     * @param \Closure(V1\PublishRequest, ServerCall): void|null $publish 应答 Host.Publish；null 即每项都成功
     */
    public function __construct(?\Closure $invoke = null, ?\Closure $publish = null)
    {
        $this->listener = Listener::open(Network::Tcp, null);
        $this->server = new GrpcServer($this->listener->socket, [
            '/xihan.plugin.v1.Host/Invoke' => function (ServerCall $call) use ($invoke): void {
                $request = new V1\InvokeRequest();
                $request->mergeFromString((string) $call->receive());
                $this->record('Invoke', $request, $request->getMetadata(), $call);
                if ($invoke !== null) {
                    $invoke($request, $call);

                    return;
                }
                $call->send((new V1\InvokeResponse())
                    ->setStatus((new V1\Status())->setOk(true))
                    ->setPayload('{"from":"host"}')
                    ->serializeToString());
            },
            '/xihan.plugin.v1.Host/Publish' => function (ServerCall $call) use ($publish): void {
                $request = new V1\PublishRequest();
                $request->mergeFromString((string) $call->receive());
                $this->record('Publish', $request, $request->getMetadata(), $call);
                if ($publish !== null) {
                    $publish($request, $call);

                    return;
                }
                $results = array_map(
                    static fn (): V1\Status => (new V1\Status())->setOk(true),
                    iterator_to_array($request->getEnvelopes(), false),
                );
                $call->send((new V1\PublishResponse())->setResults($results)->serializeToString());
            },
        ], null, static function (\Throwable $exception): void {
            throw $exception;
        });
        $this->server->start();
    }

    /**
     * 返回监听的端点。
     */
    public function endpoint(): Endpoint
    {
        return $this->listener->endpoint;
    }

    /**
     * 停止监听。
     */
    public function close(): void
    {
        $this->server->shutdown(0.5);
        $this->listener->close();
    }

    private function record(
        string $method,
        V1\InvokeRequest|V1\PublishRequest $request,
        ?V1\InvocationMetadata $metadata,
        ServerCall $call,
    ): void {
        $this->received[] = [
            'method' => $method,
            'metadata' => $metadata,
            'session' => $call->metadata('xihan-plugin-session'),
            'timeout' => $call->metadata('grpc-timeout'),
            'request' => $request,
        ];
    }
}
