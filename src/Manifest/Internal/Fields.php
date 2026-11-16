<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest\Internal;

/**
 * 按 Schema 严格读取一个 JSON 对象（以 `json_decode(..., false)` 解码的 stdClass）。
 *
 * 字段名区分大小写，类型不对、多出未知字段都抛 UnexpectedValueException，消息带 JSON 路径。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final readonly class Fields
{
    /**
     * @param \stdClass $object 要读取的对象
     * @param string $path 对象的 JSON 路径，如 `$.contributes`
     */
    private function __construct(private \stdClass $object, private string $path)
    {
    }

    /**
     * 包装一个解码出的值；不是 JSON 对象即失败。
     *
     * @throws \UnexpectedValueException
     */
    public static function of(mixed $value, string $path): self
    {
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException(sprintf('%s must be a JSON object', $path));
        }

        return new self($value, $path);
    }

    /**
     * 核对对象只含允许的字段。
     *
     * @param list<string> $allowed
     *
     * @throws \UnexpectedValueException
     */
    public function only(array $allowed): void
    {
        foreach (array_keys(get_object_vars($this->object)) as $name) {
            if (!in_array((string) $name, $allowed, true)) {
                throw new \UnexpectedValueException(sprintf('%s.%s is not a field of the schema', $this->path, $name));
            }
        }
    }

    /**
     * 读取必需的字符串字段。
     *
     * @throws \UnexpectedValueException
     */
    public function string(string $name): string
    {
        $value = $this->optionalString($name);
        if ($value === null) {
            throw new \UnexpectedValueException(sprintf('%s.%s is required', $this->path, $name));
        }

        return $value;
    }

    /**
     * 读取可选的字符串字段；没有写为 null。
     *
     * @throws \UnexpectedValueException
     */
    public function optionalString(string $name): ?string
    {
        $value = $this->value($name);
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s.%s must be a string', $this->path, $name));
        }

        return $value;
    }

    /**
     * 读取可选的布尔字段；没有写为 null。
     *
     * @throws \UnexpectedValueException
     */
    public function optionalBool(string $name): ?bool
    {
        $value = $this->value($name);
        if ($value !== null && !is_bool($value)) {
            throw new \UnexpectedValueException(sprintf('%s.%s must be a boolean', $this->path, $name));
        }

        return $value;
    }

    /**
     * 读取可选的对象字段；没有写为 null。
     *
     * @throws \UnexpectedValueException
     */
    public function optionalObject(string $name): ?self
    {
        $value = $this->value($name);

        return $value === null ? null : self::of($value, $this->path . '.' . $name);
    }

    /**
     * 读取可选的字符串数组字段；没有写为空列表。
     *
     * @return list<string>
     *
     * @throws \UnexpectedValueException
     */
    public function strings(string $name): array
    {
        $strings = [];
        foreach ($this->items($name) as $index => $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException(sprintf('%s.%s[%d] must be a string', $this->path, $name, $index));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * 读取可选的字符串数组字段；没有写为 null，与写了空数组区分开。
     *
     * @return list<string>|null
     *
     * @throws \UnexpectedValueException
     */
    public function optionalStrings(string $name): ?array
    {
        return property_exists($this->object, $name) ? $this->strings($name) : null;
    }

    /**
     * 读取可选的对象数组字段；没有写为空列表。
     *
     * @return list<self>
     *
     * @throws \UnexpectedValueException
     */
    public function objects(string $name): array
    {
        $objects = [];
        foreach ($this->items($name) as $index => $item) {
            $objects[] = self::of($item, sprintf('%s.%s[%d]', $this->path, $name, $index));
        }

        return $objects;
    }

    /**
     * 返回字段的 JSON 路径。
     */
    public function pathOf(string $name): string
    {
        return $this->path . '.' . $name;
    }

    /**
     * @return list<mixed>
     */
    private function items(string $name): array
    {
        $value = $this->value($name);
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('%s.%s must be an array', $this->path, $name));
        }

        return $value;
    }

    private function value(string $name): mixed
    {
        // Schema 里没有可空的字段：显式写 null 与类型不对一样拒绝，不当成没有写
        if (!property_exists($this->object, $name)) {
            return null;
        }
        $value = $this->object->{$name};
        if ($value === null) {
            throw new \UnexpectedValueException(sprintf('%s.%s must not be null', $this->path, $name));
        }

        return $value;
    }
}
