<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 管道中间件（RFC-0004 IPipelineMiddleware）。
 *
 * 可以在进入下游前后做事，也可以不调用 `$next` 而短路；可恢复失败返回失败的 Result，不抛异常。
 * 签名是同步的，可以运行在 Fiber 里。
 */
interface Middleware
{
    /**
     * 返回中间件名：排序约束与打印用，同一类型的中间件同名（通常是类的全名）。
     */
    public function name(): string;

    /**
     * 处理一次调用。
     *
     * @param \Closure(Context): Result<Unit> $next 下游
     *
     * @return Result<Unit>
     */
    public function invoke(Context $context, \Closure $next): Result;
}
