<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Internal\JsonText;
use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 流式操作的处理器看到的帧流（invocation.md §1）：起始请求、调用方的 chunk 与本端要发的 chunk。
 *
 * 每个 chunk 是一个完整的 JSON 值。调用方以 `end` 结束：`ok` 为正常结束，否则是中止，处理器应停止处理。调用方不按顺序
 * 发帧、流被重置或连接断开时 receive() 同样返回 null，运行时随后以对应的 gRPC 状态结束流，处理器的结果不再发出。
 */
final class OperationStream
{
    private ?Error $abort = null;

    private bool $ended = false;

    /**
     * 由运行时创建。
     *
     * @param string $request 起始帧的载荷：请求类型的 JSON，空等价于 `{}`
     * @param \Closure(): (string|Error|null) $receive 读下一帧：chunk、中止原因，或正常结束与流已断开时的 null
     * @param \Closure(string): void $send 写一个 chunk
     *
     * @internal
     */
    public function __construct(
        private readonly string $request,
        private readonly \Closure $receive,
        private readonly \Closure $send,
    ) {
    }

    /**
     * 返回起始请求的 JSON 文本；空载荷写成 `{}`。
     */
    public function request(): string
    {
        return $this->request === '' ? '{}' : $this->request;
    }

    /**
     * 读调用方的下一个 chunk；调用方已结束（或中止、流已断开）时为 null。
     */
    public function receive(): ?string
    {
        if ($this->ended) {
            return null;
        }
        $frame = ($this->receive)();
        if (is_string($frame)) {
            return $frame;
        }
        $this->ended = true;
        $this->abort = $frame;

        return null;
    }

    /**
     * 返回调用方中止的原因；调用方没有中止时为 null。
     */
    public function aborted(): ?Error
    {
        return $this->abort;
    }

    /**
     * 发一个 chunk。
     *
     * @throws InvalidArgumentException chunk 不是一个完整的 JSON 值（PRIMITIVES_VALIDATION_001）
     */
    public function send(string $json): void
    {
        if (JsonText::check($json) !== null) {
            throw new InvalidArgumentException('A stream chunk must be one complete JSON value.');
        }
        ($this->send)($json);
    }
}
