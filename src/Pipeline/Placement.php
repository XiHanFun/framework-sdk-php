<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

/**
 * 中间件在阶段内的位置（RFC-0004 排序规则 2）：Beginning 在前，End 在后。
 */
enum Placement
{
    case Beginning;
    case End;

    /**
     * 返回排序用的序号：Beginning 为 0，End 为 1。
     */
    public function order(): int
    {
        return $this === self::Beginning ? 0 : 1;
    }
}
