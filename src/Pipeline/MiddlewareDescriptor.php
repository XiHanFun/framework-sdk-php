<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

use XiHan\Framework\Primitives\PipelineStage;

/**
 * 一个已登记中间件的描述（RFC-0004 MiddlewareDescriptor）。
 */
final readonly class MiddlewareDescriptor
{
    /**
     * @param PipelineStage $stage 所在阶段
     * @param string $middleware 中间件名
     * @param string|null $instance 按名登记的实例名；默认实例为 null
     * @param string $owner 归属：清单 ID 或 app
     * @param Placement $placement 阶段内位置
     */
    public function __construct(
        public PipelineStage $stage,
        public string $middleware,
        public ?string $instance,
        public string $owner,
        public Placement $placement,
    ) {
    }

    /**
     * 返回打印名：中间件名，命名实例为 `中间件名[实例名]`。
     */
    public function display(): string
    {
        return $this->instance === null ? $this->middleware : $this->middleware . '[' . $this->instance . ']';
    }
}
