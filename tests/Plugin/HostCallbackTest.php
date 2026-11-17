<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Internal\Deadlines;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\Runtime;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;

/**
 * 回调宿主（invocation.md §5）：测试扮演宿主并提供假的 Host 服务，核对回调端点的选择、本地核对的顺序（端点、权限、
 * 上下文的租户）、回调的元数据、gRPC 状态与宿主写来的 Error 的读法，以及 Host.Publish 的整批结果。
 */
final class HostCallbackTest extends TestCase
{
    private const string MANIFEST = <<<'JSON'
        {
          "id": "xihan.tests.relay",
          "version": "1.0.0",
          "primitives": "1",
          "stability": "experimental",
          "kind": "plugin",
          "isolation": "out-of-process",
          "contributes": {
            "operations": ["relay.run"]
          },
          "permissions": ["capability:kernel.pipeline", "capability:messaging.queue"],
          "entry": "relay"
        }
        JSON;

    private const string TRACE = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    /** @var \Closure(Context, Host): mixed 这次 relay.run 要做的回调 */
    private \Closure $scenario;

    public function testCallbacksCarryTheContextAndAreCheckedLocally(): void
    {
        $host = new FakeHost();
        // 宿主按偏好给出多项：命名管道 PHP 连不了，取下一项回环 TCP
        $harness = $this->started('npipe \\\\.\\pipe\\xihan-host-test;' . $host->endpoint());
        try {
            $this->scenario = static function (Context $context, Host $client): mixed {
                $own = $client->invoke($context, 'orders.get', '{"id":1}');
                $other = $client->invoke($context->withBaggage('xihan.tenant', 'intruder'), 'orders.get');
                // 信封的 xihantenant 不在本地核对，交给宿主逐项判定
                $published = $client->publish($context, [
                    new Envelope('orders.changed', 'e1', 'urn:test', 'x'),
                    new Envelope('orders.changed', 'e2', 'urn:test', 'x', extensions: ['xihantenant' => 'intruder']),
                ]);
                $batch = $client->publish($context->withBaggage('xihan.tenant', 'intruder'), [
                    new Envelope('orders.changed', 'e3', 'urn:test', 'x'),
                    new Envelope('orders.changed', 'e4', 'urn:test', 'x'),
                ]);

                return [
                    'own' => $own->payload,
                    'other' => self::describe($other->outcome->error()),
                    'published' => array_map(static fn (Result $result): bool => $result->isOk(), $published),
                    'batch' => array_map(static fn (Result $result): string => self::describe($result->error()), $batch),
                    'endpoint' => (string) $client->endpoint(),
                ];
            };
            $metadata = (new V1\InvocationMetadata())
                ->setTraceParent(self::TRACE)
                ->setTraceState('vendor=opaque')
                ->setDeadline('2099-01-01T00:00:00.1234567+00:00')
                ->setBaggage(['xihan.tenant' => 't1', 'xihan.correlation' => 'c1', 'xihan.unregistered' => 'x']);
            $result = self::payload($harness->invoke('relay.run', '{}', metadata: $metadata));
            self::assertSame('{"from":"host"}', $result['own']);
            self::assertSame('PLUGINS_DENIED_002 plugins.callback.tenant_denied {"id":"xihan.tests.relay","tenant":"intruder"}', $result['other']);
            self::assertSame([true, true], $result['published']);
            $denied = 'PLUGINS_DENIED_002 plugins.callback.tenant_denied {"id":"xihan.tests.relay","tenant":"intruder"}';
            self::assertSame([$denied, $denied], $result['batch']);
            self::assertSame((string) $host->endpoint(), $result['endpoint']);

            // 只有自己租户的两次回调发到了宿主，带恰好一个会话令牌与 grpc-timeout
            self::assertSame(['Invoke', 'Publish'], array_column($host->received, 'method'));
            foreach ($host->received as $received) {
                self::assertSame([Harness::SESSION], $received['session']);
                self::assertCount(1, $received['timeout']);
                $sent = $received['metadata'];
                self::assertInstanceOf(V1\InvocationMetadata::class, $sent);
                // 当前 Span 的 traceparent（SDK 不另开 Span，就是调用带来的那一个）；trace_state 不进上下文，写空
                self::assertSame(self::TRACE, $sent->getTraceParent());
                self::assertSame('', $sent->getTraceState());
                // 截止时间按 UTC、七位小数、+00:00 写出，比微秒更细的部分已截断
                self::assertSame('2099-01-01T00:00:00.1234560+00:00', $sent->getDeadline());
                self::assertSame(['xihan.tenant' => 't1', 'xihan.correlation' => 'c1'], iterator_to_array($sent->getBaggage()));
            }
            $publish = $host->received[1]['request'];
            self::assertInstanceOf(V1\PublishRequest::class, $publish);
            self::assertCount(2, $publish->getEnvelopes());
        } finally {
            $harness->close();
            $host->close();
        }
    }

    public function testHostFailuresAndErrorsAreMapped(): void
    {
        $host = new FakeHost(
            static function (V1\InvokeRequest $request, ServerCall $call): void {
                $status = match ($request->getOperation()) {
                    'orders.unauthenticated' => throw new GrpcException(GrpcStatus::UNAUTHENTICATED, 'no'),
                    'orders.unknown' => throw new GrpcException(GrpcStatus::UNIMPLEMENTED, 'missing'),
                    'orders.bad' => throw new GrpcException(GrpcStatus::INVALID_ARGUMENT, 'bad frame'),
                    'orders.broken' => throw new GrpcException(GrpcStatus::DATA_LOSS, 'lost'),
                    'orders.code' => (new V1\Status())->setError((new V1\Error())->setCode('not a code')->setText('x')),
                    'orders.noerror' => new V1\Status(),
                    'orders.keyed' => (new V1\Status())->setError((new V1\Error())
                        ->setCode('ORDERS_NOTFOUND_001')
                        ->setMessageKey('orders.order.missing')
                        ->setText('rendered by the host')
                        ->setDetails('{"id":"o1"}')
                        ->setInner((new V1\Error())->setCode('ORDERS_VALIDATION_001')->setMessageKey('not a key!')->setDetails('[1]'))),
                    default => (new V1\Status())->setError((new V1\Error())->setCode('ORDERS_CONFLICT_001')->setDetails('{"a":1,"a":2}')),
                };
                $call->send((new V1\InvokeResponse())->setStatus($status)->serializeToString());
            },
            static function (V1\PublishRequest $request, ServerCall $call): void {
                // 结果数与信封数不同
                $call->send((new V1\PublishResponse())->setResults([(new V1\Status())->setOk(true)])->serializeToString());
            },
        );
        $harness = $this->started((string) $host->endpoint());
        try {
            $this->scenario = static function (Context $context, Host $client): mixed {
                $results = [];
                foreach (['unauthenticated', 'unknown', 'bad', 'broken', 'code', 'noerror', 'keyed', 'literal'] as $name) {
                    $results[$name] = self::describe($client->invoke($context, 'orders.' . $name)->outcome->error());
                }
                $keyed = $client->invoke($context, 'orders.keyed')->outcome->error();
                $results['inner'] = self::describe($keyed?->inner);
                $results['keyedText'] = $keyed?->text;
                $results['publish'] = array_map(
                    static fn (Result $result): string => self::describe($result->error()),
                    $client->publish($context, [new Envelope('a.b', '1', 'urn:x', 't'), new Envelope('a.b', '2', 'urn:x', 't')]),
                );

                return $results;
            };
            $result = self::payload($harness->invoke('relay.run'));
            $id = '"id":"xihan.tests.relay"';
            self::assertSame('PLUGINS_DENIED_002 plugins.callback.session_invalid null', $result['unauthenticated']);
            self::assertSame('KERNEL_NOTFOUND_001 kernel.operation.unknown {"id":"orders.unknown"}', $result['unknown']);
            self::assertSame('KERNEL_VALIDATION_001 kernel.operation.invalid_payload {"id":"orders.bad","reason":"bad frame"}', $result['bad']);
            self::assertSame('PLUGINS_UNEXPECTED_001 plugins.process.failed {' . $id . ',"phase":"orders.broken","reason":"DataLoss: lost"}', $result['broken']);
            // 宿主写来的错误码不合文法：整个错误换掉
            self::assertSame('PLUGINS_UNEXPECTED_001 plugins.process.failed {' . $id . ',"phase":"invoke","reason":"invalid error code \'not a code\'"}', $result['code']);
            self::assertSame('PLUGINS_UNEXPECTED_001 plugins.process.failed {' . $id . ',"phase":"orders.noerror","reason":"the host reported a failure without an error"}', $result['noerror']);
            // 键合文法的按键处理、不取 text；不合文法的成为自由文本错误，text 为空时取码；不是对象的 details 当作没有
            self::assertSame('ORDERS_NOTFOUND_001 orders.order.missing {"id":"o1"}', $result['keyed']);
            self::assertNull($result['keyedText']);
            self::assertSame('ORDERS_VALIDATION_001 literal:ORDERS_VALIDATION_001 null', $result['inner']);
            // 通不过共同 JSON 读取检查（重复成员）的 details 当作没有
            self::assertSame('ORDERS_CONFLICT_001 literal:ORDERS_CONFLICT_001 null', $result['literal']);
            $mismatch = 'PLUGINS_UNEXPECTED_001 plugins.process.failed {' . $id . ',"phase":"publish","reason":"the host returned 1 results for 2 envelopes"}';
            self::assertSame([$mismatch, $mismatch], $result['publish']);
        } finally {
            $harness->close();
            $host->close();
        }
    }

    public function testCallbacksWithoutAUsableEndpointOrPermission(): void
    {
        $missing = [
            'missing' => null,
            'invalid' => 'tcp 10.0.0.1:50123',
            'unsupported' => 'npipe \\\\.\\pipe\\xihan-host-test',
        ];
        $this->scenario = static function (Context $context, Host $client): mixed {
            return [
                'endpoint' => $client->endpoint(),
                'invoke' => self::describe($client->invoke($context, 'orders.get')->outcome->error()),
                'publish' => array_map(
                    static fn (Result $result): string => self::describe($result->error()),
                    $client->publish($context, [new Envelope('a.b', '1', 'urn:x', 't')]),
                ),
            ];
        };
        $unavailable = 'PLUGINS_UNAVAILABLE_001 plugins.host.unavailable {"id":"xihan.tests.relay","reason":"no supported callback endpoint"}';
        foreach ($missing as $name => $value) {
            $harness = $this->started($value);
            try {
                // 照常服务，启动时记一条 warning，回调一律 plugins.host.unavailable、不发请求
                self::assertContains('No supported callback endpoint; callbacks to the host will fail.', $harness->messages('warning'), $name);
                self::assertSame(['endpoint' => null, 'invoke' => $unavailable, 'publish' => [$unavailable]], self::payload($harness->invoke('relay.run')), $name);
            } finally {
                $harness->close();
            }
        }

        // 连不上：Unavailable 的说明原样写进 reason
        $harness = $this->started('tcp 127.0.0.1:1');
        try {
            $result = self::payload($harness->invoke('relay.run'));
            self::assertIsString($result['invoke']);
            self::assertStringStartsWith('PLUGINS_UNAVAILABLE_001 plugins.host.unavailable {"id":"xihan.tests.relay","reason":"Unavailable: ', $result['invoke']);
        } finally {
            $harness->close();
        }

        // Configure 没有授予的权限在本地拒绝：Invoke 要 capability:kernel.pipeline，Publish 要 capability:messaging.queue
        $host = new FakeHost();
        $harness = $this->started((string) $host->endpoint(), []);
        try {
            $result = self::payload($harness->invoke('relay.run'));
            self::assertSame('PLUGINS_DENIED_002 plugins.callback.denied {"id":"xihan.tests.relay","permission":"capability:kernel.pipeline"}', $result['invoke']);
            self::assertSame(['PLUGINS_DENIED_002 plugins.callback.denied {"id":"xihan.tests.relay","permission":"capability:messaging.queue"}'], $result['publish']);
            self::assertSame([], $host->received);
        } finally {
            $harness->close();
            $host->close();
        }
    }

    /**
     * 起一个配置并启动好的插件；$hostAddress 是 XIHAN_PLUGIN_HOST_ADDRESS（null 即不设）。
     *
     * @param list<string> $granted Configure 授予的权限
     */
    private function started(
        ?string $hostAddress,
        array $granted = [Host::PERMISSION_INVOKE, Host::PERMISSION_PUBLISH],
    ): Harness {
        $registry = new Registry();
        $registry->add(new Contract('relay.run', Stability::Experimental), function (Context $context): Result {
            $client = Host::of($context) ?? self::fail('The runtime puts the host client into every context.');

            return Result::ok(($this->scenario)($context, $client));
        });
        $harness = new Harness();
        $harness->serve(new Options(
            manifest: self::MANIFEST,
            registry: $registry,
            network: PHP_OS_FAMILY === 'Windows' ? Network::Tcp : null,
            stdin: $harness->stdin,
            stdout: $harness->stdout,
            stderr: $harness->stderr,
            environment: Harness::environment([Runtime::ENV_HOST_ADDRESS => $hostAddress]),
        ));
        $harness->handshake();
        self::assertTrue($harness->configure('{}', $granted)->getStatus()?->getOk());
        self::assertTrue($harness->start()->getStatus()?->getOk());

        return $harness;
    }

    /**
     * 取 relay.run 成功时的响应对象。
     *
     * @return array<string, mixed>
     */
    private static function payload(V1\InvokeResponse $response): array
    {
        self::assertTrue($response->getStatus()?->getOk(), (string) $response->getStatus()?->getError()?->getCode());
        $payload = json_decode($response->getPayload(), true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * 把错误写成「码 键 details」：自由文本错误的键写成 `literal:<文案>`，没有细节写 null。
     */
    private static function describe(?Error $error): string
    {
        if ($error === null) {
            return 'ok';
        }
        $details = $error->details === [] ? 'null' : (string) json_encode($error->details, JSON_UNESCAPED_SLASHES);

        return $error->code . ' ' . ($error->messageKey ?? 'literal:' . $error->text) . ' ' . $details;
    }
}
