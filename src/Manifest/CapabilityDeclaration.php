<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 提供的能力：`<能力ID>@<接口版本>`（RFC-0002 `provides`）。
 *
 * 线上编码 `{"id":…,"version":"N"}`，版本是数字字符串、不带 `^`。
 */
final readonly class CapabilityDeclaration implements \JsonSerializable, \Stringable
{
    /** 声明文本的文法；D 修饰符让 $ 只匹配串尾 */
    private const string TEXT_PATTERN = '/^([a-z][a-z0-9]*\.[a-z][a-z0-9-]*)@([1-9][0-9]*)$/D';

    /**
     * 创建声明。
     *
     * @param string $id 能力 ID `<领域>.<能力>`
     * @param int $version 接口版本，正整数
     *
     * @throws InvalidArgumentException 能力 ID 或版本不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $id, public int $version)
    {
        if (!CapabilityId::isValid($id)) {
            throw new InvalidArgumentException(
                sprintf("The capability ID '%s' must match <domain>.<capability>.", $id),
            );
        }
        if ($version < 1) {
            throw new InvalidArgumentException(
                sprintf("The interface version of '%s' must be a positive integer, got %d.", $id, $version),
            );
        }
    }

    /**
     * 解析 `id@version`；不合法时为 null。
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match(self::TEXT_PATTERN, $text, $match) !== 1) {
            return null;
        }
        $version = filter_var($match[2], FILTER_VALIDATE_INT);

        return is_int($version) ? new self($match[1], $version) : null;
    }

    /**
     * 解析 `id@version`。
     *
     * @throws InvalidArgumentException 文本不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function parse(string $text): self
    {
        return self::tryParse($text)
            ?? throw new InvalidArgumentException(
                sprintf("The capability declaration '%s' must match <capability ID>@<version>.", $text),
            );
    }

    /**
     * 返回 `id@version`。
     */
    public function __toString(): string
    {
        return $this->id . '@' . $this->version;
    }

    /**
     * 返回线上编码。
     *
     * @return array{id: string, version: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'version' => (string) $this->version];
    }
}
