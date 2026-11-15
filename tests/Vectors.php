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
     * 读取 `spec/conformance/vectors/primitives/<name>.json`。
     *
     * @return array<array-key, mixed>
     */
    public static function primitives(string $name): array
    {
        $path = dirname(__DIR__) . '/spec/conformance/vectors/primitives/' . $name . '.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(
                sprintf("The conformance vector '%s' is missing; run 'git submodule update --init'.", $path),
            );
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf("The conformance vector '%s' must be a JSON object.", $path));
        }

        return $data;
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
