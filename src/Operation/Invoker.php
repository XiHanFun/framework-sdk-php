<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\CancellationReason;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Pipeline\Placement;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 让操作经过完整管道执行的调用器（RFC-0011 IOperationInvoker）。
 *
 * 调用器带三个内置步骤，位置与 .NET 的内置中间件相同（RFC-0011 规则 10，ADR-0236）：都按归属 xihan.kernel.core 放在
 * 所在阶段的 Beginning，与同位置的其他中间件按 RFC-0004 的规则排序（归属的序数序、同归属按登记顺序）。
 *
 * - Observe 阶段的 ObserveMiddleware：把排在它之后的中间件与处理函数抛出的异常翻译为 KERNEL_UNEXPECTED_001（规则 5）；
 *   排在它之前的（Ingress 阶段，或 Observe 阶段 Beginning 上归属排在 xihan.kernel.core 之前的，如 app）抛出的异常原样
 *   交给调用方。取消异常（amphp 的 Amp\CancelledException）不在这里翻译，交给截止时间步骤归因。
 * - Resilience 阶段的 DeadlineMiddleware：进入时截止时间已到即以 KERNEL_TIMEOUT_001 结束，后面的不执行；不核对取消。
 *   途中的取消按 RFC-0003 第 1 条归因：下游抛出取消异常、或把取消写成 KERNEL_CANCELLED_001 的结果时，截止时间已到即
 *   KERNEL_TIMEOUT_001 + kernel.invocation.deadline_exceeded，否则调用方已取消即 KERNEL_CANCELLED_001；两个都没有触发的
 *   取消异常原样抛给调用方。取消只经令牌通知，调用器不强行中止中间件或处理函数，令牌触发后照常返回的结果就是调用的结果。
 * - Validate 阶段的 ValidationMiddleware：原始调用在这里把载荷解码成请求，失败即以 KERNEL_VALIDATION_001 结束；
 *   类型化调用跳过。
 *
 * 管道的终点在 handle 阶段之后分派当前操作，响应对象写进对象袋（Current::responseObject()）；XGI 入口在管道结束之后才把它
 * 编码成载荷，中间件看到的是编码之前的结果（规则 10「编码」）。描述、请求与响应在进入管道前写进上下文的对象袋，调用返回时
 * 还原成进入前的值：同一个上下文上的连续或嵌套调用互不干扰。
 */
final class Invoker
{
    /** 内置步骤的归属：内核清单 ID。 */
    public const string KERNEL_OWNER = 'xihan.kernel.core';

    /** Observe 阶段的异常翻译步骤的中间件名。 */
    public const string OBSERVE_MIDDLEWARE = 'ObserveMiddleware';

    /** Resilience 阶段的截止时间步骤的中间件名。 */
    public const string DEADLINE_MIDDLEWARE = 'DeadlineMiddleware';

    /** Validate 阶段的解码步骤的中间件名。 */
    public const string VALIDATION_MIDDLEWARE = 'ValidationMiddleware';

    /** 按取消归因的异常：amphp 的取消异常（不依赖 amphp，类不存在时不匹配） */
    private const string CANCELLED_EXCEPTION = 'Amp\CancelledException';

    /** @var \Closure(Context): Result<Unit> */
    private readonly \Closure $step;

    /**
     * 用登记表与管道构建调用器；传入的构建器不变，内置步骤登记在它的副本上。
     *
     * @throws ConfigurationException 管道的排序或约束不合法（KERNEL_PIPELINE_00x），或已登记了与内置步骤同名的中间件
     */
    public function __construct(private readonly Registry $registry, ?PipelineBuilder $builder = null)
    {
        $pipeline = $builder === null ? new PipelineBuilder() : clone $builder;
        $pipeline
            ->useAt(
                new ClosureMiddleware(self::OBSERVE_MIDDLEWARE, self::observe(...)),
                PipelineStage::Observe,
                Placement::Beginning,
                self::KERNEL_OWNER,
            )
            ->useAt(
                new ClosureMiddleware(self::DEADLINE_MIDDLEWARE, self::deadline(...)),
                PipelineStage::Resilience,
                Placement::Beginning,
                self::KERNEL_OWNER,
            )
            ->useAt(
                new ClosureMiddleware(self::VALIDATION_MIDDLEWARE, self::validate(...)),
                PipelineStage::Validate,
                Placement::Beginning,
                self::KERNEL_OWNER,
            );
        $this->step = $pipeline->build(self::dispatch(...));
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
     *
     * 截止时间、解码与异常按内置步骤处理（见类说明）。管道以成功结束后编码响应对象，写不出时（浮点是 NaN 或无穷、不支持的值）
     * 返回 KERNEL_UNEXPECTED_001 + kernel.invocation.unhandled_exception、没有载荷；没有响应对象时用中间件交出的载荷
     * （幂等回放），两样都没有时返回 KERNEL_UNEXPECTED_001 + kernel.operation.no_response（规则 9）。
     *
     * @throws \Throwable 排在 Observe 内置步骤之前的中间件抛出的异常原样抛出
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
            Current::REQUEST_OBJECT_KEY => null,
            Current::RESPONSE_OBJECT_KEY => null,
            Current::RESPONSE_KEY => null,
            Current::TERMINAL_KEY => null,
        ], function () use ($context, $request, $descriptor): Response {
            $outcome = ($this->step)($context);
            if ($outcome->isFailed()) {
                return new Response($outcome, null);
            }
            // 管道结束之后才编码：中间件看到的是编码之前的响应对象
            if ($context->items()->has(Current::RESPONSE_OBJECT_KEY)) {
                $encoded = $descriptor->codec->encode($request->id, Current::responseObject($context));
                $error = $encoded->error();

                return $error === null
                    ? new Response($outcome, $encoded->valueOrThrow())
                    : new Response(Result::fail($error), null);
            }
            $payload = Current::responsePayload($context);

            return $payload === null
                ? new Response(Result::fail(OperationErrors::noResponse($request->id)), null)
                : new Response($outcome, $payload);
        });
    }

    /**
     * 类型化入口：不经 JSON，直接以请求对象经完整管道执行；Validate 阶段的解码步骤跳过。
     *
     * 进入管道前依次核对：未登记返回 KERNEL_NOTFOUND_001；请求不是登记的请求类型、或期望的响应类型与登记不符返回
     * KERNEL_VALIDATION_002。截止时间、没有产出响应与抛出的异常同 invoke()。
     *
     * @param string $responseType 期望的响应类型名，与登记的 Codec 一致（Codec::json() 为 mixed）
     *
     * @return Result<mixed>
     *
     * @throws \Throwable 排在 Observe 内置步骤之前的中间件抛出的异常原样抛出
     */
    public function invokeTyped(Context $context, string $id, mixed $request, string $responseType): Result
    {
        $descriptor = $this->registry->find($id);
        if ($descriptor === null) {
            return Result::fail(OperationErrors::notFound($id));
        }
        $codec = $descriptor->codec;
        if (!$codec->acceptsRequest($request) || $codec->responseType !== $responseType) {
            return Result::fail(OperationErrors::typeMismatch($id));
        }
        $result = null;
        $terminal = static function (Context $context) use ($descriptor, $request, &$result): Result {
            $result = $descriptor->handle($context, $request);

            return $result->toUnit();
        };
        $outcome = self::scoped($context, [
            Current::DESCRIPTOR_KEY => $descriptor,
            Current::REQUEST_KEY => new Request($id),
            Current::REQUEST_OBJECT_KEY => $request,
            Current::RESPONSE_OBJECT_KEY => null,
            Current::RESPONSE_KEY => null,
            Current::TERMINAL_KEY => $terminal,
        ], fn (): Result => ($this->step)($context));
        $error = $outcome->error();
        if ($error !== null) {
            return Result::fail($error);
        }

        // 管道成功结束却没有走到 handle（中间件以成功短路）时没有响应对象（规则 9）
        return $result instanceof Result ? $result : Result::fail(OperationErrors::noResponse($id));
    }

    /**
     * Observe 阶段的内置步骤：把下游抛出的异常翻译为 KERNEL_UNEXPECTED_001（规则 5），不传给调用方。
     *
     * 要记日志的应用把自己的中间件放在它之后（Observe 阶段的 End 或更晚的阶段），异常先经过它再到这里。
     *
     * @param \Closure(Context): Result<Unit> $next
     *
     * @return Result<Unit>
     */
    private static function observe(Context $context, \Closure $next): Result
    {
        try {
            return $next($context);
        } catch (\Throwable $exception) {
            // 取消不在这里翻译：由截止时间步骤区分超时与调用方取消（RFC-0003 第 1 条）
            if (self::isCancellation($exception)) {
                throw $exception;
            }

            return Result::fail(KernelErrors::unhandledException($exception));
        }
    }

    /**
     * Resilience 阶段的内置步骤：进入时截止时间已到即结束，后面的中间件与处理函数不执行（规则 10）。
     *
     * @param \Closure(Context): Result<Unit> $next
     *
     * @return Result<Unit>
     */
    private static function deadline(Context $context, \Closure $next): Result
    {
        if ($context->isExpired($context->clock()->now())) {
            return Result::fail(KernelErrors::deadlineBeforeStart());
        }
        try {
            $outcome = $next($context);
        } catch (\Throwable $exception) {
            if (!self::isCancellation($exception)) {
                throw $exception;
            }
            // 截止时间已到即超时，调用方同时取消也一样；只有调用方取消才是取消；两个都没有触发的取消不归调用器
            if (self::deadlineReached($context)) {
                return Result::fail(CancellationReason::DeadlineExceeded->toError());
            }
            if ($context->cancellation()->isRequested()) {
                return Result::fail(CancellationReason::Cancelled->toError());
            }

            throw $exception;
        }
        // 下游把取消写成了结果：截止时间已到就按超时报告，与抛出的路径同一规则（RFC-0004「行为变化」）
        if ($outcome->error()?->code === KernelErrors::CANCELLED && self::deadlineReached($context)) {
            return Result::fail(CancellationReason::DeadlineExceeded->toError());
        }

        return $outcome;
    }

    /**
     * 判断异常是不是取消：amphp 的 Amp\CancelledException（含子类）。
     */
    private static function isCancellation(\Throwable $exception): bool
    {
        $class = self::CANCELLED_EXCEPTION;

        return $exception instanceof $class;
    }

    /**
     * 判断截止时间是否已到：按上下文的时钟，或令牌已按截止时间触发。
     */
    private static function deadlineReached(Context $context): bool
    {
        return $context->isExpired($context->clock()->now())
            || $context->cancellation()->reason() === CancellationReason::DeadlineExceeded;
    }

    /**
     * Validate 阶段的内置步骤：原始调用把载荷解码成请求写进对象袋，失败即结束；类型化调用跳过（规则 2、10）。
     *
     * @param \Closure(Context): Result<Unit> $next
     *
     * @return Result<Unit>
     */
    private static function validate(Context $context, \Closure $next): Result
    {
        $descriptor = Current::descriptor($context);
        $request = Current::request($context);
        if ($descriptor === null || $request === null || $context->items()->has(Current::TERMINAL_KEY)) {
            return $next($context);
        }
        $decoded = $descriptor->codec->decode($descriptor->contract->id, $request->payload);
        $error = $decoded->error();
        if ($error !== null) {
            return Result::fail($error);
        }
        $context->items()->set(Current::REQUEST_OBJECT_KEY, $decoded->valueOrThrow());

        return $next($context);
    }

    /**
     * 管道的终点：类型化调用执行它带来的终点，否则以解码后的请求执行当前操作并把响应对象写进对象袋，编码留到管道结束之后。
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
        $request = Current::request($context);
        if ($descriptor === null || $request === null) {
            return Result::fail(OperationErrors::notFound($request->id ?? ''));
        }
        // 解码步骤已把请求写进对象袋；它不在时（对象袋被下游改动）按载荷再解码一次
        if (!$context->items()->has(Current::REQUEST_OBJECT_KEY)) {
            $decoded = $descriptor->codec->decode($descriptor->contract->id, $request->payload);
            $error = $decoded->error();
            if ($error !== null) {
                return Result::fail($error);
            }
            $context->items()->set(Current::REQUEST_OBJECT_KEY, $decoded->valueOrThrow());
        }
        $result = $descriptor->handle($context, Current::requestObject($context));
        $error = $result->error();
        if ($error !== null) {
            return Result::fail($error);
        }
        $context->items()->set(Current::RESPONSE_OBJECT_KEY, $result->valueOrThrow());

        return Result::success();
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
}
