<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline\Internal;

use XiHan\Framework\Pipeline\Middleware;
use XiHan\Framework\Pipeline\MiddlewareDescriptor;

/**
 * 一次登记：中间件、描述与登记序号。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final readonly class Registration
{
    public function __construct(
        public Middleware $middleware,
        public MiddlewareDescriptor $descriptor,
        public int $order,
    ) {
    }
}
