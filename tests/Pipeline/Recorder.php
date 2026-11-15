<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Pipeline;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\ClosureMiddleware;
use XiHan\Framework\Pipeline\Middleware;
use XiHan\Framework\Primitives\Result;

/**
 * 测试用：记下中间件进入与离开的次序。
 */
final class Recorder
{
    /** @var list<string> */
    public array $entries = [];

    /**
     * 返回一个在下游前后各记一笔的中间件。
     */
    public function middleware(string $name): Middleware
    {
        return new ClosureMiddleware($name, function (Context $context, \Closure $next) use ($name): Result {
            $this->entries[] = $name . ':before';
            $outcome = $next($context);
            $this->entries[] = $name . ':after';

            return $outcome;
        });
    }

    /**
     * 返回一个直接放行的中间件，只用于排序。
     */
    public static function passThrough(string $name): Middleware
    {
        return new ClosureMiddleware($name, static fn (Context $context, \Closure $next): Result => $next($context));
    }
}
