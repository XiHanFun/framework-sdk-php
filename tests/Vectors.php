<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

/**
 * 读取规范仓库的一致性向量（spec/conformance/vectors/），不复制向量文件。
 */
final class Vectors
{
    private function __construct()
    {
    }

    /**
     * 读取 `spec/conformance/vectors/primitives/<name>.json`，按关联数组解码。
     *
     * @return array<array-key, mixed>
     */
    public static function primitives(string $name): array
    {
        $data = json_decode(self::text($name), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf("The conformance vector '%s' must be a JSON object.", $name));
        }

        return $data;
    }

    /**
     * 读取 `spec/conformance/vectors/primitives/<name>.json`，按对象解码：空对象与空数组不混淆，线上编码的向量用它。
     */
    public static function primitivesAsObject(string $name): \stdClass
    {
        return self::object('primitives/' . $name);
    }

    /**
     * 读取 `spec/conformance/vectors/<path>.json`（如 `manifest/manifests`），按对象解码。
     */
    public static function object(string $path): \stdClass
    {
        $data = json_decode(self::read($path), false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException(sprintf("The conformance vector '%s' must be a JSON object.", $path));
        }

        return $data;
    }

    /**
     * 把 JSON 值写成规范文本：对象成员按名字排序，用于按 JSON 值比较（不比较成员顺序与空白）。
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sorted(json_decode(json_encode($value, JSON_THROW_ON_ERROR), false)), JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);

            return (object) array_map(self::sorted(...), $members);
        }

        return is_array($value) ? array_map(self::sorted(...), $value) : $value;
    }

    private static function text(string $name): string
    {
        return self::read('primitives/' . $name);
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__) . '/spec/conformance/vectors/' . $relative . '.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(
                sprintf("The conformance vector '%s' is missing; run 'git submodule update --init'.", $path),
            );
        }

        return $json;
    }

    /**
     * 取向量里的一个字符串数组字段。
     *
     * @param array<array-key, mixed> $vector
     *
     * @return list<string>
     */
    public static function strings(array $vector, string $field): array
    {
        $values = $vector[$field] ?? null;
        if (!is_array($values)) {
            throw new \RuntimeException(sprintf("The vector field '%s' must be an array.", $field));
        }
        $strings = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException(sprintf("The vector field '%s' must only contain strings.", $field));
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * 取向量里的一个对象数组字段。
     *
     * @param array<array-key, mixed> $vector
     *
     * @return list<array<array-key, mixed>>
     */
    public static function objects(array $vector, string $field): array
    {
        $values = $vector[$field] ?? null;
        if (!is_array($values)) {
            throw new \RuntimeException(sprintf("The vector field '%s' must be an array.", $field));
        }
        $objects = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \RuntimeException(sprintf("The vector field '%s' must only contain objects.", $field));
            }
            $objects[] = $value;
        }

        return $objects;
    }
}
