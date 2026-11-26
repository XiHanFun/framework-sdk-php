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
     * 把 JSON 值写成规范文本：对象成员按名字排序，用于按 JSON 值比较（不比较成员顺序与空白，整数与浮点不相等）。
     */
    public static function canonicalJson(mixed $value): string
    {
        // 整数与浮点按值模型区分（ADR-0236）：2.0 与 2 不相等，负零保留
        $flags = JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

        return json_encode(self::sorted(json_decode(json_encode($value, $flags), false)), $flags);
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
     * 按 UTF-16 码元组字节（RFC-0012「一致性」的写法，PHP 的字符串是字节）：成对的代理项合成一个码点写成 4 字节的 UTF-8，
     * 孤立的代理项按 WTF-8 写成 `ED A0 80` 这样的 3 个字节（不是合法的 UTF-8），其余码元照 UTF-8 写。JSON 字符串写不出孤立的
     * 代理项，这类样例在向量里以 `utf16` 列出四位十六进制的码元。
     *
     * @param list<string> $units 码元，如 `['0061', 'D800']`
     */
    public static function wtf8(array $units): string
    {
        $bytes = '';
        $count = count($units);
        for ($index = 0; $index < $count; $index++) {
            $unit = (int) hexdec($units[$index]);
            $next = $index + 1 < $count ? (int) hexdec($units[$index + 1]) : -1;
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $next >= 0xDC00 && $next <= 0xDFFF) {
                $point = 0x10000 + (($unit - 0xD800) << 10) + ($next - 0xDC00);
                $bytes .= chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3F))
                    . chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
                $index++;
                continue;
            }
            if ($unit < 0x80) {
                $bytes .= chr($unit);
            } elseif ($unit < 0x800) {
                $bytes .= chr(0xC0 | ($unit >> 6)) . chr(0x80 | ($unit & 0x3F));
            } else {
                $bytes .= chr(0xE0 | ($unit >> 12)) . chr(0x80 | (($unit >> 6) & 0x3F)) . chr(0x80 | ($unit & 0x3F));
            }
        }

        return $bytes;
    }

    /**
     * 取向量里以 `utf16` 列出码元的样例，按 wtf8() 组成字节串。
     *
     * @param array<array-key, mixed> $vector
     *
     * @return list<string>
     */
    public static function utf16Strings(array $vector, string $field): array
    {
        $strings = [];
        foreach (self::objects($vector, $field) as $case) {
            $units = $case['utf16'] ?? null;
            if (!is_array($units) || !array_is_list($units)) {
                throw new \RuntimeException(sprintf("Every item of the vector field '%s' needs a 'utf16' list.", $field));
            }
            $strings[] = self::wtf8(self::strings(['units' => $units], 'units'));
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
