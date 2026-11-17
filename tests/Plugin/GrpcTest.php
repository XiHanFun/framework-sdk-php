<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcClient;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcException;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcServer;
use XiHan\Framework\Plugin\Internal\Grpc\GrpcStatus;
use XiHan\Framework\Plugin\Internal\Grpc\Http2Connection;
use XiHan\Framework\Plugin\Internal\Grpc\ServerCall;
use XiHan\Framework\Plugin\Internal\Listener;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * gRPC 帧层：明文 HTTP/2 上的一元、服务端流与双向流调用，状态与尾部，流量控制、截止时间与取消。
 */
final class GrpcTest extends TestCase
{
    private ?Listener $listener = null;

    private ?GrpcServer $server = null;

    private ?GrpcClient $client = null;

    /** @var list<string> 服务端记下的事件 */
    private array $events = [];

    protected function setUp(): void
    {
        $this->listener = Listener::open(null, null);
        $this->server = new GrpcServer($this->listener->socket, [
            '/test.Echo/Unary' => static function (ServerCall $call): void {
                $call->send(strrev((string) $call->receive()));
            },
            '/test.Echo/Fail' => static function (ServerCall $call): void {
                $call->receive();

                throw new GrpcException(GrpcStatus::NOT_FOUND, 'nope ✓ 100%');
            },
            '/test.Echo/Server' => static function (ServerCall $call): void {
                $count = (int) $call->receive();
                for ($index = 0; $index < $count; $index++) {
                    $call->send('m' . $index);
                }
            },
            '/test.Echo/Bidi' => static function (ServerCall $call): void {
                while (($message = $call->receive()) !== null) {
                    $call->send('echo:' . $message);
                }
            },
            '/test.Echo/Slow' => function (ServerCall $call): void {
                $call->receive();
                try {
                    delay(5, cancellation: $call->cancellation());
                } catch (CancelledException) {
                    $this->events[] = 'cancelled';

                    throw new GrpcException(GrpcStatus::CANCELLED, 'gone');
                }
            },
            '/test.Echo/Crash' => static function (ServerCall $call): void {
                throw new \LogicException('secret detail');
            },
        ], static function (ServerCall $call): ?GrpcException {
            return $call->metadata('x-token') === ['secret']
                ? null
                : new GrpcException(GrpcStatus::UNAUTHENTICATED, 'no token');
        }, function (\Throwable $exception, string $path): void {
            $this->events[] = $path . ': ' . $exception->getMessage();
        });
        $this->server->start();
        $this->client = $this->client(['x-token' => 'secret']);
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->server?->shutdown(1.0);
        $this->listener?->close();
    }

    public function testUnaryCallsRoundTrip(): void
    {
        self::assertSame('olleh', $this->client()->unary('/test.Echo/Unary', 'hello'));
        // 空消息也是一条消息
        self::assertSame('', $this->client()->unary('/test.Echo/Unary', ''));
    }

    public function testStatusesArriveInTrailers(): void
    {
        $failure = self::failure(fn () => $this->client()->unary('/test.Echo/Fail', 'x'));
        self::assertSame(GrpcStatus::NOT_FOUND, $failure->status);
        self::assertSame('nope ✓ 100%', $failure->detail);

        self::assertSame(GrpcStatus::UNIMPLEMENTED, self::failure(fn () => $this->client()->unary('/test.Echo/Missing', 'x'))->status);
        // 意外的异常只交给 onError，对端只看到 INTERNAL，不带异常的消息
        $crash = self::failure(fn () => $this->client()->unary('/test.Echo/Crash', 'x'));
        self::assertSame(GrpcStatus::INTERNAL, $crash->status);
        self::assertStringNotContainsString('secret', $crash->detail);
        self::assertSame(['/test.Echo/Crash: secret detail'], $this->events);
    }

    public function testTheGuardRejectsBeforeTheMethodRuns(): void
    {
        $anonymous = $this->client([]);
        try {
            self::assertSame(GrpcStatus::UNAUTHENTICATED, self::failure(fn () => $anonymous->unary('/test.Echo/Crash', 'x'))->status);
            self::assertSame([], $this->events);
        } finally {
            $anonymous->close();
        }
    }

    public function testLargeMessagesFlowThroughTheWindows(): void
    {
        $big = str_repeat('abcdefgh', 300_000);
        self::assertSame(strrev($big), $this->client()->unary('/test.Echo/Unary', $big));
        $tooBig = str_repeat('x', Http2Connection::MAX_MESSAGE + 1);
        self::assertSame(GrpcStatus::RESOURCE_EXHAUSTED, self::failure(fn () => $this->client()->unary('/test.Echo/Unary', $tooBig))->status);
    }

    public function testServerAndBidirectionalStreams(): void
    {
        $call = $this->client()->call('/test.Echo/Server');
        $call->send('3', true);
        $received = [];
        while (($message = $call->receive()) !== null) {
            $received[] = $message;
        }
        self::assertSame(['m0', 'm1', 'm2'], $received);

        // 双向流：每发一条就收到一条，不必等对方结束发送
        $bidi = $this->client()->call('/test.Echo/Bidi');
        foreach (['a', 'b', 'c'] as $message) {
            $bidi->send($message);
            self::assertSame('echo:' . $message, $bidi->receive());
        }
        $bidi->closeSend();
        self::assertNull($bidi->receive());
    }

    public function testConcurrentCallsShareOneConnection(): void
    {
        $futures = [];
        for ($index = 0; $index < 20; $index++) {
            $futures[] = async(fn () => $this->client()->unary('/test.Echo/Unary', 'n' . $index));
        }
        $results = await($futures);
        self::assertSame('01n', $results[10]);
        self::assertCount(20, $results);
    }

    public function testDeadlinesAndCancellationResetTheStream(): void
    {
        $started = microtime(true);
        $timeout = self::failure(fn () => $this->client()->unary('/test.Echo/Slow', 'x', [], 0.2));
        self::assertSame(GrpcStatus::DEADLINE_EXCEEDED, $timeout->status);
        self::assertLessThan(2.0, microtime(true) - $started);

        $cancellation = new DeferredCancellation();
        $call = async(fn () => $this->client()->unary('/test.Echo/Slow', 'x', [], null, $cancellation->getCancellation()));
        delay(0.1);
        $cancellation->cancel();
        self::assertSame(GrpcStatus::CANCELLED, self::failure(static fn () => $call->await())->status);
        delay(0.1);
        // 客户端重置流后服务端的调用随之取消
        self::assertSame(['cancelled', 'cancelled'], $this->events);
    }

    public function testUnreachableEndpointsAreUnavailable(): void
    {
        $directory = sys_get_temp_dir() . '/xihan-missing-' . bin2hex(random_bytes(4));
        $client = new GrpcClient(
            PHP_OS_FAMILY === 'Windows' ? 'tcp://127.0.0.1:1' : 'unix://' . $directory . '/none.sock',
            'localhost',
            [],
            'test',
        );
        self::assertSame(GrpcStatus::UNAVAILABLE, self::failure(fn () => $client->unary('/test.Echo/Unary', 'x'))->status);
    }

    public function testMessagesArePercentEncoded(): void
    {
        self::assertSame('a%25b%0A%E2%9C%93', GrpcStatus::encodeMessage("a%b\n✓"));
        self::assertSame("a%b\n✓", GrpcStatus::decodeMessage('a%25b%0A%E2%9C%93'));
        self::assertSame(1.5, GrpcStatus::parseTimeout('1500m'));
        self::assertNull(GrpcStatus::parseTimeout('123456789S'));
        // 取放得进 8 位数字的最细单位
        self::assertSame('1500000u', GrpcStatus::formatTimeout(1.5));
        self::assertSame('100000m', GrpcStatus::formatTimeout(100.0));
        self::assertSame('1n', GrpcStatus::formatTimeout(1e-9));
    }

    /**
     * 返回带给定元数据的客户端；不给时为共用的、带令牌的客户端。
     *
     * @param array<string, string>|null $metadata
     */
    private function client(?array $metadata = null): GrpcClient
    {
        if ($metadata === null && $this->client !== null) {
            return $this->client;
        }

        return new GrpcClient(
            (string) $this->listener?->endpoint->socketUri(),
            'localhost',
            $metadata ?? [],
            'xihan-test',
        );
    }

    /**
     * 执行并返回它抛出的 GrpcException。
     */
    private static function failure(\Closure $call): GrpcException
    {
        try {
            $call();
        } catch (GrpcException $exception) {
            return $exception;
        }
        self::fail('The call must fail with a gRPC status.');
    }
}
