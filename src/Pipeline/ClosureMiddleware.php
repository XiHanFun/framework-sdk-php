<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 用名字与闭包组成的中间件：不必为每个中间件写一个类。
 */
final readonly class ClosureMiddleware implements Middleware
{
    /**
     * 创建中间件。
     *
     * @param string $name 中间件名
     * @param \Closure(Context, \Closure(Context): Result<Unit>): Result<Unit> $callback 处理函数
     *
     * @throws InvalidArgumentException 名字为空（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(private string $name, private \Closure $callback)
    {
        if ($name === '') {
            throw new InvalidArgumentException('A middleware needs a name.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function invoke(Context $context, \Closure $next): Result
    {
        return ($this->callback)($context, $next);
    }
}
