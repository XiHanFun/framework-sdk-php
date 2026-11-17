<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal\Grpc;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Http\HPack;
use Amp\Http\Http2\Http2ConnectionException;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Http2\Http2Processor;
use Amp\Http\Http2\Http2StreamException;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;

use function Amp\async;

/**
 * 一条明文 HTTP/2 连接（RFC 9113，按先验知识、不经升级），只承载 gRPC：服务端与客户端两种角色共用。
 *
 * 帧的解析与 HPACK 用 amphp/http 的 Http2Parser，连接与流的状态、流量控制与帧的写出在这里。插件协议的连接都在本机、
 * 长期保持（WatchHealth 一直开着），所以不设空闲超时与 PING 次数这类面向公网的限制；内存由流的接收窗口、每条消息的
 * 上限与并发流数限定。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Http2Connection implements Http2Processor
{
    /** 一条消息的最大字节数：与 gRPC 各实现的缺省接收上限相同 */
    public const int MAX_MESSAGE = 4 * 1024 * 1024;

    /** HTTP/2 的初始窗口 */
    private const int DEFAULT_WINDOW = 65535;

    /** 本端通告的流接收窗口：对端在本端取走消息之前最多再发这么多 */
    private const int STREAM_WINDOW = 256 * 1024;

    /** 本端的连接接收窗口：收到就还，只防止对端无限制地发 */
    private const int CONNECTION_WINDOW = 4 * 1024 * 1024;

    /** 帧的最大载荷：本端不通告更大的值 */
    private const int MAX_FRAME_SIZE = 16384;

    /** 头部列表的最大字节数 */
    private const int MAX_HEADER_LIST = 64 * 1024;

    /** 服务端允许的并发流数 */
    private const int MAX_CONCURRENT_STREAMS = 100;

    /** 攒够这么多字节再发 WINDOW_UPDATE，少发小帧 */
    private const int WINDOW_THRESHOLD = 32 * 1024;

    /** 窗口的上限 2^31 - 1 */
    private const int MAX_WINDOW = 2147483647;

    private readonly HPack $hpack;

    private readonly Http2Parser $parser;

    /** @var array<int, Http2Stream> */
    private array $streams = [];

    /** 服务端：对端开过的最大流 ID */
    private int $lastRemoteStream = 0;

    /** 客户端：下一个流 ID */
    private int $nextLocalStream = 1;

    /** 连接级的发送窗口 */
    private int $sendWindow = self::DEFAULT_WINDOW;

    /** 对端通告的流初始窗口 */
    private int $peerInitialWindow = self::DEFAULT_WINDOW;

    /** 对端允许的帧载荷上限 */
    private int $peerMaxFrame = self::MAX_FRAME_SIZE;

    /** 对端允许本端开的并发流数 */
    private int $peerMaxStreams = PHP_INT_MAX;

    /** 已收到、还没有还给对端的连接级字节数 */
    private int $connectionUnacknowledged = 0;

    /** @var list<DeferredFuture<null>> 等发送窗口的写入方 */
    private array $windowWaiters = [];

    /** 已发出或收到 GOAWAY：不再开新流 */
    private bool $goingAway = false;

    private bool $closed = false;

    private ?GrpcException $closeReason = null;

    /** @var DeferredFuture<null>|null 优雅关闭时等全部流结束 */
    private ?DeferredFuture $drained = null;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $done;

    /**
     * @param bool $server 是否是服务端：服务端接收对端开的流，客户端自己开流
     * @param (\Closure(self, Http2Stream): void)|null $onStream 服务端：每个新流在自己的 Fiber 里交给它
     */
    public function __construct(
        private readonly Socket $socket,
        private readonly bool $server,
        private readonly ?\Closure $onStream = null,
    ) {
        $this->hpack = new HPack();
        $this->parser = new Http2Parser($this, $this->hpack, null, self::MAX_HEADER_LIST, self::MAX_FRAME_SIZE);
        $this->done = new DeferredFuture();
    }

    /**
     * 服务端：发出设置、核对客户端的连接序言，然后一直读到连接结束（在每条连接自己的 Fiber 里运行）。
     */
    public function serve(): void
    {
        $preface = Http2Parser::PREFACE;
        try {
            // 服务端的连接序言是 SETTINGS 帧，不必等客户端的序言再发
            $this->writeRaw($this->settingsFrames());
            $pending = '';
            while (strlen($pending) < strlen($preface)) {
                $chunk = $this->socket->read();
                if ($chunk === null) {
                    return;
                }
                $pending .= $chunk;
            }
            if (!str_starts_with($pending, $preface)) {
                // 不是 HTTP/2 的先验知识连接（例如 HTTP/1.1）：不回应，直接断开
                return;
            }
            $this->readLoop(substr($pending, strlen($preface)));
        } catch (GrpcException) {
            // 写出失败：连接已断开
        } finally {
            $this->close(new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection was closed.'));
        }
    }

    /**
     * 客户端：同步写出连接序言与设置，再在后台读；返回后即可开流。
     *
     * @throws GrpcException 写不出
     */
    public function start(): void
    {
        $this->writeRaw(Http2Parser::PREFACE . $this->settingsFrames());
        async(function (): void {
            try {
                $this->readLoop('');
            } finally {
                $this->close(new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection to the server was closed.'));
            }
        })->ignore();
    }

    /**
     * 判断连接能否开新流。
     */
    public function isUsable(): bool
    {
        return !$this->closed && !$this->goingAway;
    }

    /**
     * 返回还开着的流数。
     */
    public function streamCount(): int
    {
        return count($this->streams);
    }

    /**
     * 客户端：开一个新流。
     *
     * @throws GrpcException 连接已关闭或正在关闭、并发流数已满
     */
    public function openStream(): Http2Stream
    {
        if (!$this->isUsable()) {
            throw new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection is closing.');
        }
        if (count($this->streams) >= $this->peerMaxStreams) {
            throw new GrpcException(GrpcStatus::UNAVAILABLE, 'The server does not accept more concurrent streams.');
        }
        $id = $this->nextLocalStream;
        if ($id > self::MAX_WINDOW) {
            // 流 ID 用完：这条连接不再开流，下一次调用会建新连接
            $this->goingAway = true;

            throw new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection has run out of stream IDs.');
        }
        $this->nextLocalStream += 2;

        return $this->streams[$id] = new Http2Stream($id, $this->peerInitialWindow, self::MAX_MESSAGE);
    }

    /**
     * 写出头部块（超过帧上限时拆成 HEADERS 与 CONTINUATION，一次写出、中间不夹别的帧）。
     *
     * @param list<array{string, string}> $fields 头部，伪头部在前
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    public function sendHeaders(Http2Stream $stream, array $fields, bool $endStream): void
    {
        $this->checkWritable($stream);
        $block = $this->hpack->encode($fields);
        $parts = str_split($block, max(1, $this->peerMaxFrame));
        $frames = '';
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $flags = $index === $last ? Http2Parser::END_HEADERS : Http2Parser::NO_FLAG;
            if ($index === 0 && $endStream) {
                $flags |= Http2Parser::END_STREAM;
            }
            $type = $index === 0 ? Http2Parser::HEADERS : Http2Parser::CONTINUATION;
            $frames .= Http2Parser::compileFrame($part, $type, $flags, $stream->id);
        }
        $stream->headersSent = true;
        if ($endStream) {
            $stream->localClosed = true;
        }
        $this->writeRaw($frames);
        if ($endStream) {
            $this->release($stream);
        }
    }

    /**
     * 写出一条 gRPC 消息（5 字节前缀 + 内容）；发送窗口不够时等对端的 WINDOW_UPDATE。
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    public function sendMessage(Http2Stream $stream, string $message, bool $endStream): void
    {
        $this->sendData($stream, pack('CN', 0, strlen($message)) . $message, $endStream);
    }

    /**
     * 写出 DATA；$data 为空且 $endStream 时只发结束标志。
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    public function sendData(Http2Stream $stream, string $data, bool $endStream): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length || ($endStream && !$stream->localClosed)) {
            $this->checkWritable($stream);
            $chunk = min($length - $offset, $this->sendWindow, $stream->sendWindow, $this->peerMaxFrame);
            if ($chunk <= 0 && $offset < $length) {
                $this->awaitWindow($stream);

                continue;
            }
            $chunk = max(0, $chunk);
            $last = $offset + $chunk >= $length;
            $flags = $last && $endStream ? Http2Parser::END_STREAM : Http2Parser::NO_FLAG;
            // 先扣窗口再写：写出可能挂起，期间别的 Fiber 看到的是扣过的窗口
            $this->sendWindow -= $chunk;
            $stream->sendWindow -= $chunk;
            if ($flags === Http2Parser::END_STREAM) {
                $stream->localClosed = true;
            }
            $this->writeFrame(substr($data, $offset, $chunk), Http2Parser::DATA, $flags, $stream->id);
            $offset += $chunk;
            if ($last && !$endStream) {
                break;
            }
        }
        if ($endStream) {
            $this->release($stream);
        }
    }

    /**
     * 取下一条消息；取走后流里没有积压时把收到的字节还给对端。
     *
     * @throws GrpcException 流已失败
     * @throws CancelledException 等待被取消
     */
    public function receive(Http2Stream $stream, ?Cancellation $cancellation = null): ?string
    {
        $message = $stream->nextMessage($cancellation);
        if ($message !== null) {
            $this->creditStream($stream);
        }

        return $message;
    }

    /**
     * 重置流（RST_STREAM）；流已结束时什么都不做。
     */
    public function reset(Http2Stream $stream, int $code): void
    {
        if (!isset($this->streams[$stream->id])) {
            return;
        }
        unset($this->streams[$stream->id]);
        $stream->fail(new GrpcException(GrpcStatus::CANCELLED, 'The stream was reset.'), true);
        $this->wakeWriters();
        $this->checkDrained();
        if (!$this->closed) {
            try {
                $this->writeFrame(pack('N', $code), Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $stream->id);
            } catch (GrpcException) {
                // 连接已断开：对端不会再发这个流的帧
            }
        }
    }

    /**
     * 优雅关闭：发出 GOAWAY，不再接收新流，等在途的流结束（至多 $grace 秒）再断开。
     */
    public function shutdown(float $grace): void
    {
        if ($this->closed) {
            return;
        }
        $this->goingAway = true;
        try {
            $this->writeRaw(Http2Parser::compileFrame(
                pack('NN', $this->lastRemoteStream, Http2Parser::GRACEFUL_SHUTDOWN),
                Http2Parser::GOAWAY,
                Http2Parser::NO_FLAG,
            ));
        } catch (GrpcException) {
            return;
        }
        if ($this->streams !== []) {
            $this->drained ??= new DeferredFuture();
            try {
                $this->drained->getFuture()->await(new TimeoutCancellation(max(0.0, $grace)));
            } catch (CancelledException) {
                // 宽限到了：在途的流随连接一起结束
            }
        }
        $this->close(new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection was shut down.'));
    }

    /**
     * 断开连接：全部流以 $reason 失败，等待中的读写被唤醒。
     */
    public function close(GrpcException $reason): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeReason = $reason;
        $streams = $this->streams;
        $this->streams = [];
        foreach ($streams as $stream) {
            $stream->fail($reason, true);
        }
        $this->wakeWriters();
        $this->checkDrained();
        $this->socket->close();
        $this->done->complete();
    }

    /**
     * 等连接断开。
     */
    public function awaitClosed(): void
    {
        $this->done->getFuture()->await();
    }

    /**
     * {@inheritDoc}
     */
    public function handlePong(string $data): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handlePing(string $data): void
    {
        $this->writeFrame($data, Http2Parser::PING, Http2Parser::ACK);
    }

    /**
     * {@inheritDoc}
     */
    public function handleShutdown(int $lastId, int $error, string $message): void
    {
        $this->goingAway = true;
        if ($this->server) {
            return;
        }
        // 客户端：编号大于 lastId 的流服务端没有处理，可以在新连接上重试
        foreach ($this->streams as $id => $stream) {
            if ($id > $lastId) {
                unset($this->streams[$id]);
                $stream->fail(new GrpcException(GrpcStatus::UNAVAILABLE, 'The server is going away.'), true);
            }
        }
        $this->wakeWriters();
        if ($this->streams === []) {
            $this->close(new GrpcException(GrpcStatus::UNAVAILABLE, 'The server closed the connection.'));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function handleStreamWindowIncrement(int $streamId, int $windowSize): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }
        if ($stream->sendWindow + $windowSize > self::MAX_WINDOW) {
            throw new Http2StreamException('Flow control window overflow', $streamId, Http2Parser::FLOW_CONTROL_ERROR);
        }
        $stream->sendWindow += $windowSize;
        $this->wakeWriters();
    }

    /**
     * {@inheritDoc}
     */
    public function handleConnectionWindowIncrement(int $windowSize): void
    {
        if ($this->sendWindow + $windowSize > self::MAX_WINDOW) {
            throw new Http2ConnectionException('Flow control window overflow', Http2Parser::FLOW_CONTROL_ERROR);
        }
        $this->sendWindow += $windowSize;
        $this->wakeWriters();
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, string> $pseudo
     * @param array<string, list<string>> $headers
     */
    public function handleHeaders(int $streamId, array $pseudo, array $headers, bool $streamEnded): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($this->server && $stream === null) {
            $this->acceptStream($streamId, $pseudo, $headers);

            return;
        }
        if ($stream === null) {
            // 客户端：本端已重置或已结束的流，迟到的帧丢弃
            return;
        }
        if ($this->server || $stream->headersReceived) {
            // 第二个头部块是尾部，必须结束流
            if (!$streamEnded) {
                throw new Http2StreamException('Trailers must end the stream', $streamId, Http2Parser::PROTOCOL_ERROR);
            }
            $stream->trailers = $headers;

            return;
        }
        $stream->pseudo = $pseudo;
        $stream->headers = $headers;
        $stream->headersReceived = true;
        if ($streamEnded) {
            // 只有尾部的应答：状态在这个头部块里
            $stream->trailers = $headers;
        }
        $stream->wakeHeaders();
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, string> $pseudo
     * @param array<string, list<string>> $headers
     */
    public function handlePushPromise(int $streamId, int $pushId, array $pseudo, array $headers): void
    {
        throw new Http2ConnectionException('Server push is not enabled', Http2Parser::PROTOCOL_ERROR);
    }

    /**
     * {@inheritDoc}
     */
    public function handlePriority(int $streamId, int $parentId, int $weight): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function handleStreamReset(int $streamId, int $errorCode): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }
        unset($this->streams[$streamId]);
        if ($errorCode === Http2Parser::GRACEFUL_SHUTDOWN && $stream->remoteClosed) {
            // 对端发完（尾部已到）之后以 NO_ERROR 重置只是让本端停止发送，调用的结果以收到的尾部为准（RFC 9113 §8.1）
            $stream->reset = true;
            $this->wakeWriters();
            $this->checkDrained();

            return;
        }
        $stream->fail(new GrpcException(
            $errorCode === Http2Parser::REFUSED_STREAM ? GrpcStatus::UNAVAILABLE : GrpcStatus::CANCELLED,
            sprintf('The stream was reset by the peer (HTTP/2 error code %d).', $errorCode),
        ), true);
        $this->wakeWriters();
        $this->checkDrained();
    }

    /**
     * {@inheritDoc}
     */
    public function handleStreamException(Http2StreamException $exception): void
    {
        $streamId = $exception->getStreamId();
        $stream = $this->streams[$streamId] ?? null;
        if ($stream !== null) {
            $stream->fail(new GrpcException(GrpcStatus::INTERNAL, $exception->getMessage()), true);
            $this->reset($stream, $exception->getCode());

            return;
        }
        $this->writeFrame(pack('N', $exception->getCode()), Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $streamId);
    }

    /**
     * {@inheritDoc}
     */
    public function handleConnectionException(Http2ConnectionException $exception): void
    {
        try {
            $this->writeRaw(Http2Parser::compileFrame(
                pack('NN', $this->lastRemoteStream, $exception->getCode()) . $exception->getMessage(),
                Http2Parser::GOAWAY,
                Http2Parser::NO_FLAG,
            ));
        } catch (GrpcException) {
            // 连接已断开
        }
    }

    /**
     * {@inheritDoc}
     */
    public function handleData(int $streamId, string $data): void
    {
        $length = strlen($data);
        // 连接级窗口收到就还：背压只做在流上，一个不读的流不会卡住整条连接
        $this->connectionUnacknowledged += $length;
        if ($this->connectionUnacknowledged >= self::WINDOW_THRESHOLD) {
            $increment = $this->connectionUnacknowledged;
            $this->connectionUnacknowledged = 0;
            $this->writeFrame(pack('N', $increment), Http2Parser::WINDOW_UPDATE);
        }
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            // 本端已重置或已结束的流：在途的帧丢弃
            return;
        }
        if ($stream->remoteClosed) {
            throw new Http2StreamException('Stream closed', $streamId, Http2Parser::STREAM_CLOSED);
        }
        $stream->unacknowledged += $length;
        $stream->append($data);
        $this->creditStream($stream);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, int> $settings
     */
    public function handleSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            switch ($key) {
                case Http2Parser::INITIAL_WINDOW_SIZE:
                    if ($value > self::MAX_WINDOW) {
                        throw new Http2ConnectionException(
                            'Invalid initial window size',
                            Http2Parser::FLOW_CONTROL_ERROR,
                        );
                    }
                    // 初始窗口的变化按差值作用到已开的流上（RFC 9113 §6.9.2）
                    $delta = $value - $this->peerInitialWindow;
                    $this->peerInitialWindow = $value;
                    foreach ($this->streams as $stream) {
                        $stream->sendWindow += $delta;
                    }
                    break;
                case Http2Parser::MAX_FRAME_SIZE:
                    if ($value < self::MAX_FRAME_SIZE || $value > 16777215) {
                        throw new Http2ConnectionException('Invalid max frame size', Http2Parser::PROTOCOL_ERROR);
                    }
                    $this->peerMaxFrame = $value;
                    break;
                case Http2Parser::MAX_CONCURRENT_STREAMS:
                    $this->peerMaxStreams = $value;
                    break;
            }
        }
        $this->writeFrame('', Http2Parser::SETTINGS, Http2Parser::ACK);
        $this->wakeWriters();
    }

    /**
     * {@inheritDoc}
     */
    public function handleStreamEnd(int $streamId): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }
        $stream->end();
        $this->release($stream);
    }

    /**
     * 服务端：接收对端开的新流，交给处理函数；正在关闭或并发流数已满时以 REFUSED_STREAM 拒绝。
     *
     * @param array<string, string> $pseudo
     * @param array<string, list<string>> $headers
     */
    private function acceptStream(int $streamId, array $pseudo, array $headers): void
    {
        if ($streamId % 2 === 0 || $streamId <= $this->lastRemoteStream) {
            throw new Http2ConnectionException('Invalid stream ID', Http2Parser::PROTOCOL_ERROR);
        }
        $this->lastRemoteStream = $streamId;
        if ($this->goingAway || count($this->streams) >= self::MAX_CONCURRENT_STREAMS || $this->onStream === null) {
            $refused = pack('N', Http2Parser::REFUSED_STREAM);
            $this->writeFrame($refused, Http2Parser::RST_STREAM, Http2Parser::NO_FLAG, $streamId);

            return;
        }
        $stream = new Http2Stream($streamId, $this->peerInitialWindow, self::MAX_MESSAGE);
        $stream->pseudo = $pseudo;
        $stream->headers = $headers;
        $stream->headersReceived = true;
        $this->streams[$streamId] = $stream;
        $onStream = $this->onStream;
        // 每个流一个 Fiber：处理函数挂起时不挡住连接的读取与别的流
        async(fn () => $onStream($this, $stream))->ignore();
    }

    /**
     * 读循环：把收到的字节交给解析器，直到对端断开或连接出错。
     */
    private function readLoop(string $pending): void
    {
        try {
            if ($pending !== '') {
                $this->parser->push($pending);
            }
            while (($chunk = $this->socket->read()) !== null) {
                $this->parser->push($chunk);
            }
        } catch (Http2ConnectionException) {
            // 已发出 GOAWAY（handleConnectionException）
        } catch (\Throwable) {
            // 读或写失败，或处理帧时出错：断开这条连接；异常不能逃出读连接的 Fiber，否则会中止整个事件循环
        } finally {
            $this->parser->cancel();
        }
    }

    /**
     * 本端的设置与把连接接收窗口调大的 WINDOW_UPDATE。
     */
    private function settingsFrames(): string
    {
        $settings = $this->server
            ? pack(
                'nNnNnN',
                Http2Parser::MAX_CONCURRENT_STREAMS,
                self::MAX_CONCURRENT_STREAMS,
                Http2Parser::INITIAL_WINDOW_SIZE,
                self::STREAM_WINDOW,
                Http2Parser::MAX_HEADER_LIST_SIZE,
                self::MAX_HEADER_LIST,
            )
            : pack(
                'nNnNnN',
                Http2Parser::ENABLE_PUSH,
                0,
                Http2Parser::INITIAL_WINDOW_SIZE,
                self::STREAM_WINDOW,
                Http2Parser::MAX_HEADER_LIST_SIZE,
                self::MAX_HEADER_LIST,
            );

        return Http2Parser::compileFrame($settings, Http2Parser::SETTINGS, Http2Parser::NO_FLAG)
            . Http2Parser::compileFrame(
                pack('N', self::CONNECTION_WINDOW - self::DEFAULT_WINDOW),
                Http2Parser::WINDOW_UPDATE,
                Http2Parser::NO_FLAG,
            );
    }

    /**
     * 把流上收到的字节还给对端：流里有没被取走的消息时先不还，对端最多再发一个窗口，读得慢的流因此被限速。
     */
    private function creditStream(Http2Stream $stream): void
    {
        $done = $stream->remoteClosed || $stream->reset;
        if ($done || $stream->unacknowledged === 0 || $stream->pendingMessages() > 0) {
            return;
        }
        // 半条消息在等后续字节时立即还，否则攒够阈值再还
        if ($stream->unacknowledged < self::WINDOW_THRESHOLD && !$stream->hasPartialMessage()) {
            return;
        }
        $increment = $stream->unacknowledged;
        $stream->unacknowledged = 0;
        $this->writeFrame(pack('N', $increment), Http2Parser::WINDOW_UPDATE, Http2Parser::NO_FLAG, $stream->id);
    }

    /**
     * 两端都结束发送的流从连接上移除。
     */
    private function release(Http2Stream $stream): void
    {
        if ($stream->localClosed && $stream->remoteClosed && isset($this->streams[$stream->id])) {
            unset($this->streams[$stream->id]);
            $this->checkDrained();
        }
    }

    /**
     * 优雅关闭在等时，最后一个流结束即唤醒它。
     */
    private function checkDrained(): void
    {
        if ($this->streams === [] && $this->drained !== null && !$this->drained->isComplete()) {
            $this->drained->complete();
        }
    }

    /**
     * @throws GrpcException 流已重置或连接已断开
     */
    private function checkWritable(Http2Stream $stream): void
    {
        if ($this->closed) {
            throw $this->closeReason ?? new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection was closed.');
        }
        if ($stream->reset) {
            throw $stream->failure() ?? new GrpcException(GrpcStatus::CANCELLED, 'The stream was reset.');
        }
        if ($stream->localClosed) {
            throw new GrpcException(GrpcStatus::INTERNAL, 'The stream has already been half-closed.');
        }
    }

    /**
     * 等发送窗口变大、流被重置或连接断开。
     *
     * @throws GrpcException 流已重置或连接已断开
     */
    private function awaitWindow(Http2Stream $stream): void
    {
        /** @var DeferredFuture<null> $waiter */
        $waiter = new DeferredFuture();
        $this->windowWaiters[] = $waiter;
        try {
            $waiter->getFuture()->await($stream->cancellation());
        } catch (CancelledException) {
            throw $stream->failure() ?? new GrpcException(GrpcStatus::CANCELLED, 'The stream was reset.');
        }
    }

    /**
     * 唤醒全部等发送窗口的写入方，它们各自重新核对窗口。
     */
    private function wakeWriters(): void
    {
        $waiters = $this->windowWaiters;
        $this->windowWaiters = [];
        foreach ($waiters as $waiter) {
            $waiter->complete();
        }
    }

    /**
     * 写一个帧。
     *
     * @throws GrpcException 连接已断开
     */
    private function writeFrame(string $payload, int $type, int $flags = Http2Parser::NO_FLAG, int $stream = 0): void
    {
        $this->writeRaw(Http2Parser::compileFrame($payload, $type, $flags, $stream));
    }

    /**
     * 把帧写到套接字；写不出时断开连接。
     *
     * @throws GrpcException 连接已断开
     */
    private function writeRaw(string $bytes): void
    {
        if ($this->closed) {
            throw $this->closeReason ?? new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection was closed.');
        }
        try {
            $this->socket->write($bytes);
        } catch (StreamException $exception) {
            $reason = new GrpcException(GrpcStatus::UNAVAILABLE, 'The connection was closed.', [], $exception);
            $this->close($reason);

            throw $reason;
        }
    }
}
