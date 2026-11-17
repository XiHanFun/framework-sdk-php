<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;

/**
 * HTTP/2 上的一个 gRPC 流：收到的头部与尾部、拆好的消息、两端的关闭与流量窗口。
 *
 * 状态只由所属连接在事件循环里改写；读消息的一方在没有消息时挂起，连接收到数据、结束或重置时唤醒它。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Http2Stream
{
    /** gRPC 消息的前缀长度：1 字节压缩标志 + 4 字节长度 */
    private const int PREFIX = 5;

    /** @var array<string, string> 伪头部（服务端是请求的，客户端是响应的） */
    public array $pseudo = [];

    /** @var array<string, list<string>> 头部（服务端是请求的，客户端是响应的） */
    public array $headers = [];

    /** @var array<string, list<string>>|null 尾部；客户端收到只有尾部的应答时与头部相同 */
    public ?array $trailers = null;

    /** 客户端：是否已收到响应头部 */
    public bool $headersReceived = false;

    /** 是否已发出头部 */
    public bool $headersSent = false;

    /** 对端是否已结束发送（END_STREAM） */
    public bool $remoteClosed = false;

    /** 流是否已被重置或随连接断开：之后两个方向都不能再发 */
    public bool $reset = false;

    /** 本端是否已结束发送 */
    public bool $localClosed = false;

    /** 本端还能发送的字节数（对端给的流窗口） */
    public int $sendWindow;

    /** 已收到、还没有还给对端的字节数 */
    public int $unacknowledged = 0;

    /** 拆消息时剩下的不完整的字节 */
    private string $buffer = '';

    /** @var \SplQueue<string> 拆好、还没有被取走的消息 */
    private \SplQueue $messages;

    /** 流的失败（被重置、连接断开、消息不合法）；有了就不再接收 */
    private ?GrpcException $failure = null;

    /** @var DeferredFuture<null>|null 等消息的读取方 */
    private ?DeferredFuture $readable = null;

    /** @var DeferredFuture<null>|null 等响应头部的客户端 */
    private ?DeferredFuture $headersArrived = null;

    private readonly DeferredCancellation $cancellation;

    /**
     * @param int $id 流 ID
     * @param int $sendWindow 对端的初始流窗口
     * @param int $maxMessage 一条消息的最大字节数
     */
    public function __construct(public readonly int $id, int $sendWindow, private readonly int $maxMessage)
    {
        $this->sendWindow = $sendWindow;
        $this->messages = new \SplQueue();
        $this->cancellation = new DeferredCancellation();
    }

    /**
     * 返回流的取消：被对端重置或连接断开时触发。
     */
    public function cancellation(): Cancellation
    {
        return $this->cancellation->getCancellation();
    }

    /**
     * 返回第一个值；没有时为 null。
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name][0] ?? null;
    }

    /**
     * 返回拆好、还没有被取走的消息数。
     */
    public function pendingMessages(): int
    {
        return $this->messages->count();
    }

    /**
     * 判断是否还有不完整的消息在等后续字节。
     */
    public function hasPartialMessage(): bool
    {
        return $this->buffer !== '';
    }

    /**
     * 收下一段 DATA 的内容，拆出完整的消息；消息超过上限或带压缩标志时让流失败。
     */
    public function append(string $data): void
    {
        if ($this->failure !== null) {
            return;
        }
        $this->buffer .= $data;
        while (strlen($this->buffer) >= self::PREFIX) {
            $flag = ord($this->buffer[0]);
            /** @var array{1: int} $length */
            $length = unpack('N', substr($this->buffer, 1, 4));
            if ($flag !== 0) {
                // 本运行时不声明 grpc-accept-encoding，对端不该压缩；压缩过的消息解不开
                $this->fail(new GrpcException(GrpcStatus::UNIMPLEMENTED, 'Compressed messages are not supported.'));

                return;
            }
            if ($length[1] > $this->maxMessage) {
                $this->fail(new GrpcException(GrpcStatus::RESOURCE_EXHAUSTED, sprintf(
                    'The message of %d bytes exceeds the limit of %d bytes.',
                    $length[1],
                    $this->maxMessage,
                )));

                return;
            }
            if (strlen($this->buffer) < self::PREFIX + $length[1]) {
                break;
            }
            $this->messages->enqueue(substr($this->buffer, self::PREFIX, $length[1]));
            $this->buffer = substr($this->buffer, self::PREFIX + $length[1]);
        }
        $this->wakeReader();
    }

    /**
     * 对端结束发送；结尾还有不完整的消息时让流失败。
     */
    public function end(): void
    {
        $this->remoteClosed = true;
        if ($this->buffer !== '' && $this->failure === null) {
            $this->fail(new GrpcException(GrpcStatus::INTERNAL, 'The stream ended in the middle of a message.'));
        }
        $this->wakeReader();
        $this->wakeHeaders();
    }

    /**
     * 让流失败：之后的读取抛出这个异常，等待中的读取被唤醒。
     *
     * 收到的消息不合法时流仍可以发出状态；被重置或连接断开（$reset）时两个方向都停止，流的取消随之触发。
     */
    public function fail(GrpcException $failure, bool $reset = false): void
    {
        $this->failure ??= $failure;
        $this->buffer = '';
        if ($reset) {
            $this->reset = true;
            if (!$this->cancellation->isCancelled()) {
                $this->cancellation->cancel($failure);
            }
        }
        $this->wakeReader();
        $this->wakeHeaders();
    }

    /**
     * 返回流的失败；没有时为 null。
     */
    public function failure(): ?GrpcException
    {
        return $this->failure;
    }

    /**
     * 取下一条消息：没有就等；对端已结束且没有剩下的消息时为 null。
     *
     * @throws GrpcException 流已失败
     * @throws CancelledException 等待被取消
     */
    public function nextMessage(?Cancellation $cancellation = null): ?string
    {
        while (true) {
            if (!$this->messages->isEmpty()) {
                return $this->messages->dequeue();
            }
            if ($this->failure !== null) {
                throw $this->failure;
            }
            if ($this->remoteClosed) {
                return null;
            }
            $this->readable = new DeferredFuture();
            $this->readable->getFuture()->await($cancellation);
        }
    }

    /**
     * 客户端：等响应头部（或只有尾部的应答）到达。
     *
     * @throws GrpcException 流已失败
     * @throws CancelledException 等待被取消
     */
    public function awaitHeaders(?Cancellation $cancellation = null): void
    {
        while (!$this->headersReceived) {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            if ($this->remoteClosed) {
                return;
            }
            $this->headersArrived = new DeferredFuture();
            $this->headersArrived->getFuture()->await($cancellation);
        }
    }

    /**
     * 收到头部后唤醒等待者。
     */
    public function wakeHeaders(): void
    {
        $waiting = $this->headersArrived;
        $this->headersArrived = null;
        $waiting?->complete();
    }

    private function wakeReader(): void
    {
        $waiting = $this->readable;
        $this->readable = null;
        $waiting?->complete();
    }
}
