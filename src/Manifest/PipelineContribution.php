<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;

/**
 * 贡献的一个中间件（RFC-0002 `contributes.pipeline`，排序规则见 RFC-0004）。
 */
final readonly class PipelineContribution implements \JsonSerializable
{
    /** @var list<string> */
    public array $before;

    /** @var list<string> */
    public array $after;

    /**
     * 创建贡献。
     *
     * @param string $middleware 中间件名
     * @param PipelineStage $stage 所在阶段
     * @param list<string> $before 要排在它们之前的中间件名
     * @param list<string> $after 要排在它们之后的中间件名
     *
     * @throws InvalidArgumentException 中间件名为空，或约束不是字符串列表（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $middleware,
        public PipelineStage $stage,
        array $before = [],
        array $after = [],
    ) {
        if ($middleware === '') {
            throw new InvalidArgumentException('A pipeline contribution needs a middleware name.');
        }
        $this->before = Checks::strings($before, 'before');
        $this->after = Checks::strings($after, 'after');
    }

    /**
     * 返回线上编码；没有约束时省略 before / after。
     *
     * @return array{middleware: string, stage: string, before?: list<string>, after?: list<string>}
     */
    public function jsonSerialize(): array
    {
        $wire = ['middleware' => $this->middleware, 'stage' => $this->stage->value];
        if ($this->before !== []) {
            $wire['before'] = $this->before;
        }
        if ($this->after !== []) {
            $wire['after'] = $this->after;
        }

        return $wire;
    }
}
