<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Operation\Contract;
use XiHan\Framework\Operation\Schema;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 插件服务的一个流式操作：握手时声明为 `streaming: true`，宿主以 InvokeStream 调用（invocation.md §1）。
 *
 * RFC-0011 还没有流式操作的契约，这里只按协议的帧格式交给处理器：处理器读调用方的 chunk、写自己的 chunk，返回的结果
 * 作为 `end` 帧。流式操作不经插件内的管道（管道的终点只分派一元操作），ID 同样要出现在清单 contributes.operations 里。
 */
final readonly class StreamingOperation
{
    /** @var \Closure(Context, OperationStream): Result<Unit> */
    public \Closure $handler;

    /**
     * @param Contract $contract 契约
     * @param \Closure(Context, OperationStream): Result<Unit> $handler 处理器；返回失败即以带错误的 `end` 结束
     * @param Schema|null $schema 起始请求与每个响应帧的 JSON Schema
     */
    public function __construct(public Contract $contract, \Closure $handler, public ?Schema $schema = null)
    {
        $this->handler = $handler;
    }
}
