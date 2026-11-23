<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 按值模型读出的 JSON 对象（RFC-0010「模板」的值模型，ADR-0236）：成员按文本顺序，成员名原样。
 *
 * json_decode 的两种结果都不够：stdClass 放不下以 U+0000 开头的成员名，关联数组分不清 `{}` 与 `[]`（`{"0":1}` 也会变成列表）。
 * SDK 读线上编码（Result::fromJson、Error::fromJson）与无类型的载荷（Codec::json()）时，JSON 对象是这个类型，数组是 PHP 的
 * 列表，数字是 int（64 位范围内的整数写法）或 float（其余，上溢为无穷）。
 *
 * 不可变：用 `$object['name']` 或 get() 取成员，with() 得到改了一个成员的新对象。PHP 的数组会把纯数字的键转成整数，
 * 所以 toArray() 的键可能是整数；遍历与 names() 给出的成员名都是字符串。
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final readonly class JsonObject implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param array<array-key, mixed> $members 成员名 → 值，按文本顺序
     */
    private function __construct(private array $members)
    {
    }

    /**
     * 用成员表创建对象；键是成员名，顺序就是成员的顺序。
     *
     * @param array<array-key, mixed> $members
     */
    public static function of(array $members = []): self
    {
        return new self($members);
    }

    /**
     * 判断有没有这个成员（值为 null 也算有）。
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->members);
    }

    /**
     * 取成员的值；没有这个成员时为 null。
     */
    public function get(string $name): mixed
    {
        return $this->members[$name] ?? null;
    }

    /**
     * 返回改了（或加上）一个成员的新对象；已有的成员留在原位置，新成员排在最后。
     */
    public function with(string $name, mixed $value): self
    {
        $members = $this->members;
        $members[$name] = $value;

        return new self($members);
    }

    /**
     * 返回成员名，按顺序。
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->members));
    }

    /**
     * 返回成员表（浅拷贝）；纯数字的成员名在 PHP 的数组里是整数键。
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->members;
    }

    /**
     * 返回成员数。
     */
    #[\Override]
    public function count(): int
    {
        return count($this->members);
    }

    /**
     * 按顺序遍历成员，键是字符串的成员名。
     *
     * @return \Generator<string, mixed>
     */
    #[\Override]
    public function getIterator(): \Generator
    {
        foreach ($this->members as $name => $value) {
            yield (string) $name => $value;
        }
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->members);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->members[(string) $offset] ?? null;
    }

    /**
     * 不可变：用 with()。
     *
     * @throws \LogicException
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('A JSON object is immutable; use with() to get a changed copy.');
    }

    /**
     * 不可变。
     *
     * @throws \LogicException
     */
    #[\Override]
    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('A JSON object is immutable.');
    }

    /**
     * 自己用 json_encode 写出时的形态：stdClass（空对象写 `{}`，纯数字的成员名照样是成员）；成员名以 U+0000 开头时 stdClass
     * 放不下，退成关联数组。SDK 的写出（toJson、响应编码）不经过这里，按成员原样写出。
     *
     * @return \stdClass|array<array-key, mixed>
     */
    #[\Override]
    public function jsonSerialize(): \stdClass|array
    {
        foreach (array_keys($this->members) as $name) {
            if (str_starts_with((string) $name, "\0")) {
                return $this->members;
            }
        }

        return (object) $this->members;
    }
}
