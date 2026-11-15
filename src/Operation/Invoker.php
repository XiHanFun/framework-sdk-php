<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 让操作经过完整管道执行的调用器（RFC-0011 IOperationInvoker）。
 *
 * 管道的终点在 handle 阶段分派当前操作。描述、请求与响应载荷在进入管道前写进上下文的对象袋，
 * 调用返回时还原成进入前的值：同一个上下文上的连续或嵌套调用互不干扰。
 */
final class Invoker
{
    /** @var \Closure(Context): Result<Unit> */
    private readonly \Closure $step;

    /**
     * 用登记表与管道构建调用器。
     *
     * @throws ConfigurationException 管道的排序或约束不合法（KERNEL_PIPELINE_00x）
     */
    public function __construct(private readonly Registry $registry, ?PipelineBuilder $builder = null)
    {
        $this->step = ($builder ?? new PipelineBuilder())->build(self::dispatch(...));
    }

    /**
     * 返回登记表。
     */
    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * 执行一个 XGI 请求：未登记返回 KERNEL_NOTFOUND_001（不进管道）；否则经完整管道执行。
     */
    public function invoke(Context $context, Request $request): Response
    {
        $descriptor = $this->registry->find($request->id);
        if ($descriptor === null) {
            return new Response(Result::fail(OperationErrors::notFound($request->id)), null);
        }

        return self::scoped($context, [
            Current::DESCRIPTOR_KEY => $descriptor,
            Current::REQUEST_KEY => $request,
            Current::RESPONSE_KEY => null,
            Current::TERMINAL_KEY => null,
        ], function () use ($context): Response {
            $outcome = ($this->step)($context);

            return new Response($outcome, $outcome->isOk() ? Current::responsePayload($context) : null);
        });
    }

    /**
     * 类型化入口：不经 JSON，直接以请求对象经完整管道执行。
     *
     * 请求不是登记的请求类型、或期望的响应类型与登记不符时返回 KERNEL_VALIDATION_002；未登记返回 KERNEL_NOTFOUND_001。
     *
     * @param string $responseType 期望的响应类型名，与登记的 Codec 一致（Codec::json() 为 mixed）
     *
     * @return Result<mixed>
     */
    public function invokeTyped(Context $context, string $id, mixed $request, string $responseType): Result
    {
        $descriptor = $this->registry->find($id);
        if ($descriptor === null) {
            return Result::fail(OperationErrors::notFound($id));
        }
        $codec = $descriptor->codec;
        if (!$codec->acceptsRequest($request) || $codec->responseType !== $responseType) {
            return Result::fail(OperationErrors::typeMismatch(
                $id,
                $codec->requestType . ' -> ' . $codec->responseType,
                get_debug_type($request) . ' -> ' . $responseType,
            ));
        }
        $result = null;
        $terminal = static function (Context $context) use ($descriptor, $request, &$result): Result {
            $result = $descriptor->handle($context, $request);

            return $result->toUnit();
        };
        $outcome = self::scoped($context, [
            Current::DESCRIPTOR_KEY => $descriptor,
            Current::REQUEST_KEY => new Request($id),
            Current::RESPONSE_KEY => null,
            Current::TERMINAL_KEY => $terminal,
        ], fn (): Result => ($this->step)($context));
        if ($outcome->isFailed()) {
            return Result::fail($outcome->error() ?? self::notExecuted($id));
        }

        // 管道成功结束却没有走到 handle（中间件短路成功）时没有响应对象
        return $result instanceof Result ? $result : Result::fail(self::notExecuted($id));
    }

    /**
     * 管道的终点：类型化调用执行它带来的终点，否则执行当前操作并把响应载荷写回上下文。
     *
     * @return Result<Unit>
     */
    private static function dispatch(Context $context): Result
    {
        $terminal = $context->items()->get(Current::TERMINAL_KEY);
        if ($terminal instanceof \Closure) {
            /** @var \Closure(Context): Result<Unit> $terminal */
            return $terminal($context);
        }
        $descriptor = Current::descriptor($context);
        if ($descriptor === null) {
            return Result::fail(OperationErrors::notFound(Current::request($context)->id ?? ''));
        }
        $response = $descriptor->execute($context, Current::request($context)->payload ?? '');
        if ($response->outcome->isOk() && $response->payload !== null) {
            Current::setResponsePayload($context, $response->payload);
        }

        return $response->outcome;
    }

    /**
     * 在对象袋里放好这次调用的值再执行，返回前还原成进入前的值。
     *
     * @template T
     *
     * @param array<string, mixed> $values 值为 null 表示这次调用里没有它
     * @param \Closure(): T $run
     *
     * @return T
     */
    private static function scoped(Context $context, array $values, \Closure $run): mixed
    {
        $items = $context->items();
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = $items->has($key) ? [$items->get($key)] : null;
            if ($value === null) {
                $items->remove($key);
            } else {
                $items->set($key, $value);
            }
        }
        try {
            return $run();
        } finally {
            // 嵌套调用各自还原，外层调用回到自己写进去的值；进入前没有的键移除
            foreach ($previous as $key => $saved) {
                if ($saved === null) {
                    $items->remove($key);
                } else {
                    $items->set($key, $saved[0]);
                }
            }
        }
    }

    private static function notExecuted(string $id): Error
    {
        return Error::literal(
            ErrorCode::UNINITIALIZED_RESULT,
            sprintf("The operation '%s' ended successfully without running its handler.", $id),
        );
    }
}
