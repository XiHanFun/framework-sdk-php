<?php

declare(strict_types=1);

namespace XiHan\Framework\Examples\Echo;

use XiHan\Framework\Operation\Shape;

/**
 * echoes.create 的请求：要回声的文本。
 */
final readonly class CreateEcho
{
    public function __construct(public string $text)
    {
    }

    /**
     * 请求的形状：text 是必填的字符串。
     */
    public static function shape(): Shape
    {
        return Shape::object(['text' => Shape::string()->required()]);
    }

    /**
     * 用核对过的值创建请求；text 写成 null 时按空串，由处理函数报业务失败。
     */
    public static function create(mixed $value): self
    {
        /** @var array{text: string|null} $value */
        return new self($value['text'] ?? '');
    }
}
