<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Health;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\HealthStatus;
use XiHan\Framework\Plugin\Hooks;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcClient;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcServer;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Internal\Listener;
use XiHan\Framework\Plugin\OperationStream;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\Runtime;
use XiHan\Framework\Plugin\StreamingOperation;
use XiHan\Framework\Plugin\Subscription;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;

use function Amp\async;
use function Amp\delay;

/**
 * 运行时在进程内对接本 SDK 的 gRPC 客户端与一个假宿主：生命周期、调用、流式、投递、健康推送、回调的权限与租户、停止。
 */
final class RuntimeTest extends TestCase
{
    private const string MANIFEST = <<<'JSON'
        {
          "id": "xihan.tests.items",
          "version": "1.0.0",
          "primitives": "1",
          "stability": "experimental",
          "kind": "plugin",
          "isolation": "out-of-process",
          "contributes": {
            "operations": ["items.get", "items.relay", "items.stream"],
            "health": ["items"]
          },
          "permissions": ["capability:kernel.pipeline"],
          "entry": "items"
        }
        JSON;

    private const string SESSION = 'session-token-for-the-runtime-test-0123456789';

    /** @var list<array{operation: string, tenant: string, session: list<string>}> 假宿主收到的回调 */
    private array $callbacks = [];

    /** 测试插件在 Configure 里读到的问候语 */
    private string $greeting = '';

    public function testTheRuntimeServesTheProtocol(): void
    {
        $host = $this->host();
        [$stdin, $stdinWriter] = self::pipe();
        $stdout = self::memory();
        $stderr = self::memory();
        $serving = async(fn (): int => Runtime::serve($this->options($stdin, $stdout, $stderr, $host[0]->endpoint)));
        try {
            $client = new GrpcClient(
                (string) self::endpoint($stdout)->socketUri(),
                'localhost',
                ['xihan-plugin-session' => self::SESSION],
                'xihan-test',
            );
            $handshake = new V1\HandshakeResponse();
            $handshake->mergeFromString($client->unary(
                '/xihan.plugin.v1.Plugin/Handshake',
                (new V1\HandshakeRequest())->setProtocolVersions([1, 2])->setPrimitives(1)->serializeToString(),
            ));
            self::assertSame(1, $handshake->getProtocolVersion());
            self::assertSame(self::MANIFEST, $handshake->getManifest());
            $ids = array_map(static fn (V1\OperationDeclaration $operation): string => $operation->getId(), iterator_to_array($handshake->getOperations(), false));
            self::assertSame(['items.get', 'items.relay', 'items.stream'], $ids);
            // 握手只做一次
            self::assertSame(GrpcStatus::FAILED_PRECONDITION, self::grpcStatus(fn () => $client->unary('/xihan.plugin.v1.Plugin/Handshake', '')));

            // Start 之前的调用是业务失败 PLUGINS_UNAVAILABLE_001
            self::assertSame('PLUGINS_UNAVAILABLE_001', self::invoke($client, 'items.get', '{}')->getStatus()?->getError()?->getCode());
            $configured = new V1\ConfigureResponse();
            $configured->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/Configure', (new V1\ConfigureRequest())
                ->setConfiguration('{"greeting":"hi"}')
                ->setGrantedPermissions(['capability:kernel.pipeline'])
                ->serializeToString()));
            self::assertTrue($configured->getStatus()?->getOk());
            $started = new V1\StartResponse();
            $started->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/Start', ''));
            self::assertTrue($started->getStatus()?->getOk());

            $health = new V1\HealthResponse();
            $health->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/Health', ''));
            self::assertSame(LifecycleState::Ready->number(), $health->getState());
            // 没有声明的健康项不上报
            self::assertCount(1, $health->getItems());

            $response = self::invoke($client, 'items.get', '{"id":"i1"}', ['xihan.tenant' => 't1']);
            self::assertTrue($response->getStatus()?->getOk());
            self::assertSame('{"id":"i1","tenant":"t1","greeting":"hi"}', $response->getPayload());
            self::assertSame(GrpcStatus::UNIMPLEMENTED, self::grpcStatus(fn () => self::invoke($client, 'items.missing', '{}')));

            // 回调：本次调用的租户放行，别的租户与没有声明的权限在本端拒绝，不发给宿主
            $relayed = self::invoke($client, 'items.relay', '{}', ['xihan.tenant' => 't1']);
            self::assertSame(
                '{"own":"{\"from\":\"host\"}","other":"plugins.callback.tenant_denied","published":"plugins.callback.denied"}',
                $relayed->getPayload(),
            );
            self::assertSame([['operation' => 'orders.get', 'tenant' => 't1', 'session' => [self::SESSION]]], $this->callbacks);

            $events = new V1\HandleEventResponse();
            $events->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/HandleEvent', (new V1\HandleEventRequest())
                ->setEnvelope((new V1\Envelope())->setTopic('items.changed')->setId('e1')->setType('x'))
                ->setMode('broadcast')
                ->setAttempt(1)
                ->serializeToString()));
            // 只以竞争方式订阅：广播的投递找不到处理器
            self::assertSame('KERNEL_NOTFOUND_001', $events->getStatus()?->getError()?->getCode());

            $stream = $client->call('/xihan.plugin.v1.Plugin/InvokeStream');
            $stream->send((new V1\StreamFrame())->setStart((new V1\InvokeRequest())->setOperation('items.stream'))->serializeToString());
            $stream->send((new V1\StreamFrame())->setChunk('1')->serializeToString());
            $stream->send((new V1\StreamFrame())->setEnd((new V1\Status())->setOk(true))->serializeToString(), true);
            $frames = [];
            while (($frame = $stream->receive()) !== null) {
                $decoded = new V1\StreamFrame();
                $decoded->mergeFromString($frame);
                $frames[] = $decoded->getFrame() === 'chunk' ? $decoded->getChunk() : ($decoded->getEnd()?->getOk() ? 'end:ok' : 'end:error');
            }
            self::assertSame(['[1]', 'end:ok'], $frames);

            $watch = $client->call('/xihan.plugin.v1.Plugin/WatchHealth');
            $watch->send('', true);
            $first = new V1\HealthResponse();
            $first->mergeFromString((string) $watch->receive());
            self::assertSame(LifecycleState::Ready->number(), $first->getState());

            $stopped = new V1\StopResponse();
            $stopped->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/Stop', (new V1\StopRequest())->setGraceSeconds(2)->setReason('unload')->serializeToString()));
            self::assertTrue($stopped->getStatus()?->getOk());
            // 停止时健康推送发出 Draining 后结束
            $last = new V1\HealthResponse();
            $last->mergeFromString((string) $watch->receive());
            self::assertSame(LifecycleState::Draining->number(), $last->getState());
            self::assertNull($watch->receive());
            self::assertSame(0, $serving->await());
            $client->close();
            rewind($stderr);
            $log = (string) stream_get_contents($stderr);
            self::assertStringContainsString('"message":"Stopped."', $log);
            self::assertStringContainsString('"plugin":"xihan.tests.items"', $log);
        } finally {
            fclose($stdinWriter);
            $host[1]->shutdown(1.0);
            $host[0]->close();
        }
    }

    public function testCallsWithoutTheSessionTokenAreRejected(): void
    {
        [$stdin, $stdinWriter] = self::pipe();
        $stdout = self::memory();
        $serving = async(fn (): int => Runtime::serve($this->options($stdin, $stdout, self::memory(), null)));
        $endpoint = self::endpoint($stdout);
        foreach ([[], ['xihan-plugin-session' => 'wrong']] as $metadata) {
            $client = new GrpcClient((string) $endpoint->socketUri(), 'localhost', $metadata, 'xihan-test');
            self::assertSame(GrpcStatus::UNAUTHENTICATED, self::grpcStatus(fn () => $client->unary('/xihan.plugin.v1.Plugin/Handshake', '')));
            $client->close();
        }
        // stdin 关闭即停止
        fclose($stdinWriter);
        self::assertSame(0, $serving->await());
    }

    public function testConfigurationErrorsStopBeforeListening(): void
    {
        $plugin = str_replace('"isolation": "out-of-process",', '"isolation": "wasm",', self::MANIFEST);
        $middleware = '"health": ["items"], "pipeline": [{"middleware": "M", "stage": "observe"}]';
        $environment = ['XIHAN_PLUGIN_PROTOCOL_VERSIONS' => '1', 'XIHAN_PLUGIN_SESSION' => 's'];
        $cases = [
            'not out of process' => [$plugin, null, 'isolation out-of-process'],
            'middleware' => [str_replace('"health": ["items"]', $middleware, self::MANIFEST), null, 'contributes.pipeline'],
            'undeclared operation' => [str_replace('"items.get", ', '', self::MANIFEST), null, "'items.get'"],
            'no session' => [self::MANIFEST, ['XIHAN_PLUGIN_PROTOCOL_VERSIONS' => '1'], 'XIHAN_PLUGIN_SESSION'],
            'no versions' => [self::MANIFEST, ['XIHAN_PLUGIN_SESSION' => 's'], 'XIHAN_PLUGIN_PROTOCOL_VERSIONS'],
            'bad host address' => [
                self::MANIFEST,
                [...$environment, 'XIHAN_PLUGIN_HOST_ADDRESS' => 'tcp 10.0.0.1:1'],
                'XIHAN_PLUGIN_HOST_ADDRESS',
            ],
        ];
        foreach ($cases as $name => [$manifest, $variables, $expected]) {
            $options = $this->options(self::memory(), self::memory(), self::memory(), null, $manifest, $variables);
            try {
                Runtime::serve($options);
                self::fail($name . ' must be rejected');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString($expected, $exception->getMessage(), $name);
            }
        }
        $duplicate = fn (): Subscription => new Subscription('items.changed', static fn (): Result => Result::success());
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('more than once');
        Runtime::serve(new Options(self::MANIFEST, subscriptions: [$duplicate(), $duplicate()], environment: $environment));
    }

    /**
     * 组装测试插件：items.get 回显、items.relay 回调宿主、items.stream 流式；订阅 items.changed（竞争）。
     *
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     * @param array<string, string>|null $environment
     */
    private function options(mixed $stdin, mixed $stdout, mixed $stderr, ?Endpoint $host, string $manifest = self::MANIFEST, ?array $environment = null): Options
    {
        $registry = new Registry();
        $registry->add(new Contract('items.get', Stability::Experimental), function (Context $context, JsonObject $request): Result {
            return Result::ok(['id' => $request['id'], 'tenant' => $context->tenant(), 'greeting' => $this->greeting]);
        });
        $registry->add(new Contract('items.relay', Stability::Experimental), static function (Context $context): Result {
            $host = Host::of($context) ?? throw new \LogicException('The host client must be in the context.');
            $own = $host->invoke($context, 'orders.get', '{"id":1}');
            $other = $host->invoke($context->withBaggage('xihan.tenant', 'intruder'), 'orders.get');
            [$published] = $host->publish($context, [new Envelope('items.changed', 'e1', 'urn:test', 'x')]);

            return Result::ok([
                'own' => $own->payload,
                'other' => $other->outcome->error()?->messageKey,
                'published' => $published->error()?->messageKey,
            ]);
        });
        $environment ??= [
            'XIHAN_PLUGIN_PROTOCOL_VERSIONS' => '1',
            'XIHAN_PLUGIN_PRIMITIVES' => '1',
            'XIHAN_PLUGIN_SESSION' => self::SESSION,
            'XIHAN_PLUGIN_INSTANCE' => 'test',
            'XIHAN_PLUGIN_LOCALE' => 'en-US',
        ];
        if ($host !== null) {
            $environment['XIHAN_PLUGIN_HOST_ADDRESS'] = (string) $host;
        }

        return new Options(
            manifest: $manifest,
            registry: $registry,
            subscriptions: [new Subscription('items.changed', static fn (): Result => Result::success())],
            hooks: new Hooks(
                configure: function (Configuration $configuration): Result {
                    $greeting = $configuration->section['greeting'];
                    $this->greeting = is_string($greeting) ? $greeting : '';

                    return Result::success();
                },
                health: static fn (): Health => new Health(LifecycleState::Ready, [
                    new HealthItem('items'),
                    new HealthItem('undeclared', HealthStatus::Unhealthy),
                ]),
            ),
            streams: [new StreamingOperation(
                new Contract('items.stream', Stability::Experimental),
                static function (Context $context, OperationStream $stream): Result {
                    while (($chunk = $stream->receive()) !== null) {
                        $stream->send('[' . $chunk . ']');
                    }

                    return Result::success();
                },
            )],
            stopGrace: 2.0,
            stdin: $stdin,
            stdout: $stdout,
            stderr: $stderr,
            environment: $environment,
        );
    }

    /**
     * 起一个假宿主：Invoke 记下操作、租户与会话令牌并应答，Publish 一律成功。
     *
     * @return array{Listener, GrpcServer}
     */
    private function host(): array
    {
        $listener = Listener::open(null, null);
        $server = new GrpcServer($listener->socket, [
            '/xihan.plugin.v1.Host/Invoke' => function (ServerCall $call): void {
                $request = new V1\InvokeRequest();
                $request->mergeFromString((string) $call->receive());
                $baggage = iterator_to_array($request->getMetadata()?->getBaggage() ?? []);
                $this->callbacks[] = [
                    'operation' => $request->getOperation(),
                    'tenant' => is_string($baggage['xihan.tenant'] ?? null) ? $baggage['xihan.tenant'] : '',
                    'session' => $call->metadata('xihan-plugin-session'),
                ];
                $call->send((new V1\InvokeResponse())
                    ->setStatus((new V1\Status())->setOk(true))
                    ->setPayload('{"from":"host"}')
                    ->serializeToString());
            },
        ], null, static function (\Throwable $exception): void {
            throw $exception;
        });
        $server->start();

        return [$listener, $server];
    }

    /**
     * 等运行时写出握手行，返回其中的端点。
     *
     * @param resource $stdout
     */
    private static function endpoint(mixed $stdout): Endpoint
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            rewind($stdout);
            $line = (string) stream_get_contents($stdout);
            if (str_ends_with($line, "\n")) {
                self::assertSame(1, substr_count($line, "\n"), 'stdout carries only the handshake line');

                return Endpoint::tryParseHandshakeLine(rtrim($line, "\n")) ?? self::fail('invalid handshake line ' . $line);
            }
            delay(0.01);
        }
        self::fail('no handshake line');
    }

    /**
     * 发一次 Invoke。
     *
     * @param array<string, string> $baggage
     */
    private static function invoke(GrpcClient $client, string $operation, string $payload, array $baggage = []): V1\InvokeResponse
    {
        $response = new V1\InvokeResponse();
        $response->mergeFromString($client->unary('/xihan.plugin.v1.Plugin/Invoke', (new V1\InvokeRequest())
            ->setOperation($operation)
            ->setPayload($payload)
            ->setMetadata((new V1\InvocationMetadata())->setBaggage($baggage))
            ->serializeToString()));

        return $response;
    }

    /**
     * 执行并返回它以之失败的 gRPC 状态。
     */
    private static function grpcStatus(\Closure $call): int
    {
        try {
            $call();
        } catch (GrpcException $exception) {
            return $exception->status;
        }
        self::fail('The call must fail with a gRPC status.');
    }

    /**
     * 一对相连的流：运行时读前者，测试关闭后者模拟宿主关闭 stdin。
     *
     * @return array{resource, resource}
     */
    private static function pipe(): array
    {
        $pair = stream_socket_pair(
            PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            self::fail('cannot create a stream pair');
        }

        return [$pair[0], $pair[1]];
    }

    /**
     * @return resource
     */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            self::fail('cannot open a memory stream');
        }

        return $stream;
    }
}
