<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * JsonReader 读出的一个 JSON 值：种类、值与它在原文里的位置。
 *
 * 对象是按文本顺序的成员表（成员名原样，含以 U+0000 开头的名字，stdClass 放不下它们），数组是项的列表，
 * 数字按值模型读成 int 或 float，原文的写法留在 `[start, end)` 里，任意 JSON 按它原样保留。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final readonly class JsonNode
{
    public const string OBJECT = 'object';
    public const string ARRAY = 'array';
    public const string STRING = 'string';
    public const string NUMBER = 'number';
    public const string BOOL = 'bool';
    public const string NULL = 'null';

    /**
     * @param string $kind 种类，见本类的常量
     * @param list<array{string, JsonNode}>|list<JsonNode>|string|int|float|bool|null $value 对象是成员表，数组是项
     * @param int $start 原文里的起始字节
     * @param int $end 原文里的结束字节（不含）
     */
    public function __construct(
        public string $kind,
        public array|string|int|float|bool|null $value,
        public int $start,
        public int $end,
    ) {
    }

    /**
     * 返回对象的成员，按文本顺序；不是对象时为空。
     *
     * @return list<array{string, JsonNode}>
     */
    public function members(): array
    {
        /** @var list<array{string, JsonNode}> */
        return $this->kind === self::OBJECT && is_array($this->value) ? $this->value : [];
    }

    /**
     * 返回数组的项；不是数组时为空。
     *
     * @return list<JsonNode>
     */
    public function items(): array
    {
        /** @var list<JsonNode> */
        return $this->kind === self::ARRAY && is_array($this->value) ? $this->value : [];
    }

    /**
     * 返回这个值在原文里的写法。
     */
    public function raw(string $text): string
    {
        return substr($text, $this->start, $this->end - $this->start);
    }
}
