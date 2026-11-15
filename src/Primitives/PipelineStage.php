<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 管道的十一个阶段（RFC-0004）。
 *
 * 枚举值是清单里的阶段名，声明顺序就是执行顺序；名称与顺序都是规范性内容，名字区分大小写。
 */
enum PipelineStage: string
{
    case Ingress = 'ingress';
    case Observe = 'observe';
    case Resilience = 'resilience';
    case Authenticate = 'authenticate';
    case Tenant = 'tenant';
    case Authorize = 'authorize';
    case Validate = 'validate';
    case Idempotency = 'idempotency';
    case Transaction = 'transaction';
    case Handle = 'handle';
    case Egress = 'egress';

    /**
     * 返回清单里的阶段名。
     */
    public function manifestName(): string
    {
        return $this->value;
    }

    /**
     * 返回执行顺序：ingress 为 0，egress 为 10。
     */
    public function order(): int
    {
        return match ($this) {
            self::Ingress => 0,
            self::Observe => 1,
            self::Resilience => 2,
            self::Authenticate => 3,
            self::Tenant => 4,
            self::Authorize => 5,
            self::Validate => 6,
            self::Idempotency => 7,
            self::Transaction => 8,
            self::Handle => 9,
            self::Egress => 10,
        };
    }
}
