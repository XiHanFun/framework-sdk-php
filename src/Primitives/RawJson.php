<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\JsonText;

/**
 * 原样保留的 JSON 值（RFC-0011 规则 2，ADR-0236）：任意 JSON 类型的成员读到什么就保留什么，写回时 JSON 值相等。
 *
 * json_decode 把 `1e400` 读成无穷、把超出双精度的小数读成近似值，再写出就不是原来的值了，所以载荷里的任意 JSON
 * （Shape::any()）交出这个类型：`json` 是读到的原文，SDK 写响应与线上编码（toJson）时把它原样放回。要读里面的内容时用
 * decode()，它按 json_decode 的对象模式解码，精度与它相同。
 */
final readonly class RawJson implements \JsonSerializable
{
    private function __construct(public string $json)
    {
    }

    /**
     * 包装一段 JSON 文本；先过 JSON 文本的共同检查（RFC-0001「JSON 文本」）。
     *
     * @throws InvalidArgumentException 不是 JSON，或有重复成员（PRIMITIVES_VALIDATION_001）
     */
    public static function of(string $json): self
    {
        $problem = JsonText::check($json);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem->syntax
                ? 'The raw JSON value is not valid JSON.'
                : sprintf("The raw JSON value has a duplicate member at '%s'.", $problem->path));
        }

        return new self($json);
    }

    /**
     * 包装已核对过的 JSON 文本，不再检查。
     *
     * @internal 由 SDK 在读载荷时调用。
     */
    public static function checked(string $json): self
    {
        return new self($json);
    }

    /**
     * 按 json_decode 的对象模式解码：对象是 stdClass，`1e400` 是无穷。
     *
     * @throws \JsonException 成员名以 U+0000 开头，stdClass 放不下
     */
    public function decode(): mixed
    {
        return json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 自己用 json_encode 写出时取解码后的值；SDK 的写出（toJson、响应编码）原样放回 `json`，不经过这里。
     */
    public function jsonSerialize(): mixed
    {
        return $this->decode();
    }
}
