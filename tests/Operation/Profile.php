<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Operation;

use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Primitives\RawJson;

/**
 * payload-reasons 向量的 profile 请求：带必填成员与各种取值。
 */
final readonly class Profile
{
    /**
     * @param list<string|null>|null $tags
     * @param list<int>|null $scores
     * @param array{n: int}|null $inner
     */
    public function __construct(
        public ?string $name,
        public ?string $note = null,
        public int $count = 0,
        public float $ratio = 0.0,
        public bool $flag = false,
        public ?array $tags = null,
        public ?array $scores = null,
        public ?array $inner = null,
        public ?RawJson $meta = null,
    ) {
    }

    /**
     * 请求的形状：name 必填（值为 null 也算出现），其余可选；inner 里的 n 必填。
     */
    public static function shape(): Shape
    {
        return Shape::object([
            'name' => Shape::string()->required(),
            'note' => Shape::string(),
            'count' => Shape::int64(),
            'ratio' => Shape::float(),
            'flag' => Shape::bool(),
            'tags' => Shape::listOf(Shape::string()),
            'scores' => Shape::listOf(Shape::int64()),
            'inner' => Shape::object(['n' => Shape::int64()->required()]),
            'meta' => Shape::any(),
        ]);
    }

    /**
     * 用核对过的值创建请求。
     */
    public static function create(mixed $value): self
    {
        /** @var array{name: string|null, note?: string|null, count?: int, ratio?: float, flag?: bool,
         *     tags?: list<string|null>|null, scores?: list<int>|null, inner?: array{n: int}|null, meta?: RawJson|null} $value */
        return new self(
            $value['name'],
            $value['note'] ?? null,
            $value['count'] ?? 0,
            $value['ratio'] ?? 0.0,
            $value['flag'] ?? false,
            $value['tags'] ?? null,
            $value['scores'] ?? null,
            $value['inner'] ?? null,
            $value['meta'] ?? null,
        );
    }
}
