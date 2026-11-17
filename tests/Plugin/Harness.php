<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use Amp\Future;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\Assert;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcClient;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\Runtime;

use function Amp\async;
use function Amp\delay;

/**
 * 测试扮演宿主：在进程内运行 Runtime::serve()，给它 stdin、stdout、stderr 与环境变量，读握手行、连上端点、发调用。
 */
final class Harness
{
    /** 会话令牌。 */
    public const string SESSION = 'session-token-for-the-runtime-test-0123456789';

    /** @var resource 运行时读的 stdin */
    public readonly mixed $stdin;

    /** @var resource 测试关闭它模拟宿主关闭 stdin */
    private mixed $stdinWriter;

    /** @var resource */
    public readonly mixed $stdout;

    /** @var resource */
    public readonly mixed $stderr;

    /** @var Future<int>|null */
    private ?Future $serving = null;

    private ?GrpcClient $client = null;

    private ?Endpoint $endpoint = null;

    public function __construct()
    {
        $pair = stream_socket_pair(
            PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            Assert::fail('cannot create a stream pair');
        }
        [$this->stdin, $this->stdinWriter] = $pair;
        $this->stdout = self::memory();
        $this->stderr = self::memory();
    }

    /**
     * 宿主下发的环境变量，$extra 覆盖或补充（值为 null 即去掉这个变量）。
     *
     * @param array<string, string|null> $extra
     *
     * @return array<string, string>
     */
    public static function environment(array $extra = []): array
    {
        $environment = [
            Runtime::ENV_PROTOCOL_VERSIONS => '1',
            Runtime::ENV_PRIMITIVES => '1',
            Runtime::ENV_SESSION => self::SESSION,
            Runtime::ENV_INSTANCE => 'test',
            Runtime::ENV_LOCALE => 'en-US',
        ];
        foreach ($extra as $name => $value) {
            if ($value === null) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * 在后台运行插件，等它写出握手行并连上它的端点。
     */
    public function serve(Options $options): self
    {
        $this->serving = async(static fn (): int => Runtime::serve($options));
        $this->endpoint = $this->awaitHandshakeLine();
        $this->client = $this->client();

        return $this;
    }

    /**
     * 返回插件写出的握手行里的端点。
     */
    public function endpoint(): Endpoint
    {
        return $this->endpoint ?? Assert::fail('The plugin has not written its handshake line.');
    }

    /**
     * 返回连到插件端点的客户端；$metadata 为 null 时带会话令牌（共用的客户端）。
     *
     * @param array<string, string|list<string>>|null $metadata
     */
    public function client(?array $metadata = null): GrpcClient
    {
        if ($metadata === null && $this->client !== null) {
            return $this->client;
        }

        return new GrpcClient(
            (string) $this->endpoint()->socketUri(),
            'localhost',
            $metadata ?? [Runtime::SESSION_METADATA => self::SESSION],
            'xihan-test',
        );
    }

    /**
     * 发一次一元调用并把应答解码进 $response。
     *
     * @template T of Message
     *
     * @param T $response
     *
     * @return T
     */
    public function unary(string $method, ?Message $request, Message $response, ?GrpcClient $client = null): Message
    {
        $bytes = ($client ?? $this->client())->unary(
            '/xihan.plugin.v1.Plugin/' . $method,
            $request?->serializeToString() ?? '',
        );
        $response->mergeFromString($bytes);

        return $response;
    }

    /**
     * 握手。
     */
    public function handshake(): V1\HandshakeResponse
    {
        return $this->unary(
            'Handshake',
            (new V1\HandshakeRequest())->setProtocolVersions([1])->setPrimitives(1),
            new V1\HandshakeResponse(),
        );
    }

    /**
     * 配置：配置节与授予的权限。
     *
     * @param list<string> $permissions
     */
    public function configure(string $configuration = '{}', array $permissions = []): V1\ConfigureResponse
    {
        return $this->unary(
            'Configure',
            (new V1\ConfigureRequest())->setConfiguration($configuration)->setGrantedPermissions($permissions),
            new V1\ConfigureResponse(),
        );
    }

    /**
     * 启动。
     */
    public function start(): V1\StartResponse
    {
        return $this->unary('Start', null, new V1\StartResponse());
    }

    /**
     * 求一次健康。
     */
    public function health(): V1\HealthResponse
    {
        return $this->unary('Health', null, new V1\HealthResponse());
    }

    /**
     * 停止。
     */
    public function stop(int $grace = 2, string $reason = 'unload'): V1\StopResponse
    {
        return $this->unary(
            'Stop',
            (new V1\StopRequest())->setGraceSeconds($grace)->setReason($reason),
            new V1\StopResponse(),
        );
    }

    /**
     * 调用一个操作。
     *
     * @param array<string, string> $baggage
     */
    public function invoke(
        string $operation,
        string $payload = '{}',
        array $baggage = [],
        ?V1\InvocationMetadata $metadata = null,
    ): V1\InvokeResponse {
        return $this->unary(
            'Invoke',
            (new V1\InvokeRequest())
                ->setOperation($operation)
                ->setPayload($payload)
                ->setMetadata($metadata ?? (new V1\InvocationMetadata())->setBaggage($baggage)),
            new V1\InvokeResponse(),
        );
    }

    /**
     * 投递一条消息；$envelope 为 null 即不带信封。
     *
     * @param array<string, string> $baggage
     */
    public function deliver(?V1\Envelope $envelope, string $mode = 'competing', array $baggage = []): V1\HandleEventResponse
    {
        $request = (new V1\HandleEventRequest())
            ->setMode($mode)
            ->setAttempt(1)
            ->setMetadata((new V1\InvocationMetadata())->setBaggage($baggage));
        if ($envelope !== null) {
            $request->setEnvelope($envelope);
        }

        return $this->unary('HandleEvent', $request, new V1\HandleEventResponse());
    }

    /**
     * 关闭 stdin，模拟宿主退出。
     */
    public function closeStdin(): void
    {
        if (is_resource($this->stdinWriter)) {
            fclose($this->stdinWriter);
        }
    }

    /**
     * 等插件返回，返回退出码。
     */
    public function exitCode(float $timeout = 10.0): int
    {
        $serving = $this->serving ?? Assert::fail('The plugin was not started.');
        $code = $serving->await(new \Amp\TimeoutCancellation($timeout));
        $this->client?->close();

        return $code;
    }

    /**
     * 收尾：关闭 stdin 与客户端，等插件返回。
     */
    public function close(): void
    {
        $this->closeStdin();
        $this->client?->close();
        if ($this->serving !== null && !$this->serving->isComplete()) {
            $this->serving->await(new \Amp\TimeoutCancellation(10.0));
        }
    }

    /**
     * 返回 stdout 的全部内容。
     */
    public function stdoutText(): string
    {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }

    /**
     * 返回 stderr 里的日志，每行解码成数组。
     *
     * @return list<array<string, mixed>>
     */
    public function logs(): array
    {
        rewind($this->stderr);
        $entries = [];
        foreach (explode("\n", (string) stream_get_contents($this->stderr)) as $line) {
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            Assert::assertIsArray($entry, $line);
            /** @var array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * 返回某个级别的日志的 message。
     *
     * @return list<mixed>
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->logs() as $entry) {
            if (($entry['level'] ?? null) === $level) {
                $messages[] = $entry['message'] ?? null;
            }
        }

        return $messages;
    }

    /**
     * 执行并返回它以之失败的 gRPC 状态。
     */
    public static function grpcStatus(\Closure $call): int
    {
        try {
            $call();
        } catch (GrpcException $exception) {
            return $exception->status;
        }
        Assert::fail('The call must fail with a gRPC status.');
    }

    /**
     * 把协议的错误写成便于比较的数组：码、键、文案与 details 的 JSON 值。
     *
     * @return array{code: string, key: string, text: string, details: mixed}
     */
    public static function error(?V1\Error $error): array
    {
        Assert::assertNotNull($error, 'an error is expected');
        $details = $error->getDetails();

        return [
            'code' => $error->getCode(),
            'key' => $error->getMessageKey(),
            'text' => $error->getText(),
            'details' => $details === '' ? null : json_decode($details, true),
        ];
    }

    /**
     * @return resource
     */
    public static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            Assert::fail('cannot open a memory stream');
        }

        return $stream;
    }

    /**
     * 等运行时写出握手行，返回其中的端点。
     */
    private function awaitHandshakeLine(): Endpoint
    {
        for ($attempt = 0; $attempt < 300; $attempt++) {
            $line = $this->stdoutText();
            if (str_ends_with($line, "\n")) {
                Assert::assertSame(1, substr_count($line, "\n"), 'stdout carries only the handshake line');

                return Endpoint::tryParseHandshakeLine(rtrim($line, "\n"))
                    ?? Assert::fail('invalid handshake line ' . $line);
            }
            if ($this->serving?->isComplete() === true) {
                Assert::fail("The plugin returned before its handshake line:\n" . json_encode($this->logs()));
            }
            delay(0.01);
        }
        Assert::fail('no handshake line');
    }
}
