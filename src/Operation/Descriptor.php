<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\Result;

/**
 * 登记表里的一个操作（RFC-0011 OperationDescriptor）：契约、编解码、Schema、归属与执行入口。
 *
 * 由 Registry::add() 创建。执行入口不经管道：管道由 Invoker 负责。
 */
final readonly class Descriptor
{
    /** @var \Closure(Context, mixed): Result<mixed> */
    private \Closure $handler;

    /**
     * @param Contract $contract 契约
     * @param \Closure(Context, mixed): Result<mixed> $handler 处理函数；可恢复失败返回失败的 Result，不抛异常
     * @param Codec<mixed, mixed> $codec 编解码
     * @param Schema|null $schema 请求与响应的 JSON Schema
     * @param string $owner 归属：清单 ID 或 app
     */
    public function __construct(
        public Contract $contract,
        \Closure $handler,
        public Codec $codec,
        public ?Schema $schema,
        public string $owner,
    ) {
        // 包一层带返回类型的闭包：处理函数返回的不是 Result 时在边界上以 TypeError 失败
        $this->handler = static fn (Context $context, mixed $request): Result => $handler($context, $request);
    }

    /**
     * 解码载荷、执行、编码响应；解码失败返回 KERNEL_VALIDATION_001，不执行处理函数。
     */
    public function execute(Context $context, string $payload): Response
    {
        $request = $this->codec->decode($this->contract->id, $payload);
        if ($request->isFailed()) {
            return new Response($request->toUnit(), null);
        }
        $result = $this->handle($context, $request->valueOrThrow());
        if ($result->isFailed()) {
            return new Response($result->toUnit(), null);
        }
        $encoded = $this->codec->encode($this->contract->id, $result->valueOrThrow());
        if ($encoded->isFailed()) {
            return new Response($encoded->toUnit(), null);
        }

        return new Response(Result::success(), $encoded->valueOrThrow());
    }

    /**
     * 以请求对象直接执行处理函数（类型化入口，不经 JSON、不经管道）。
     *
     * @return Result<mixed>
     */
    public function handle(Context $context, mixed $request): Result
    {
        return ($this->handler)($context, $request);
    }
}
