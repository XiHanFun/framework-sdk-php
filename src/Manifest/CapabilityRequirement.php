<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 依赖的能力：`<能力ID>@[^]<接口版本>[?]`（RFC-0002 `requires`，conventions/capability-ids.md）。
 *
 * 接口版本是整数，`^N` 与 N 等价；`?` 表示可选依赖，缺失不阻止启动、对应特性进入 Degraded。
 * 线上编码 `{"id":…,"version":"N","optional":true}`，optional 为假时省略。
 */
final readonly class CapabilityRequirement implements \JsonSerializable, \Stringable
{
    /** 依赖文本的文法；D 修饰符让 $ 只匹配串尾 */
    private const string TEXT_PATTERN = '/^([a-z][a-z0-9]*\.[a-z][a-z0-9-]*)@(\^?)([1-9][0-9]*)(\??)$/D';

    /**
     * 创建依赖。
     *
     * @param string $id 能力 ID `<领域>.<能力>`
     * @param int $version 接口版本，正整数
     * @param bool $optional 是否可选
     *
     * @throws InvalidArgumentException 能力 ID 或版本不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $id, public int $version, public bool $optional = false)
    {
        if (!CapabilityId::isValid($id)) {
            throw new InvalidArgumentException(
                sprintf("The capability ID '%s' must match <domain>.<capability>.", $id),
            );
        }
        if (!Checks::isVersion($version)) {
            throw new InvalidArgumentException(sprintf(
                "The interface version of '%s' must be between 1 and %d, got %d.",
                $id,
                Checks::MAX_VERSION,
                $version,
            ));
        }
    }

    /**
     * 解析 `id@[^]version[?]`；不合法时为 null。
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match(self::TEXT_PATTERN, $text, $match) !== 1) {
            return null;
        }
        // 版本不带前导零，不超过 2147483647（conventions/capability-ids.md，ADR-0236）
        $version = Checks::interfaceVersion($match[3], false);

        return $version === null ? null : new self($match[1], $version, $match[4] === '?');
    }

    /**
     * 解析 `id@[^]version[?]`。
     *
     * @throws InvalidArgumentException 文本不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function parse(string $text): self
    {
        return self::tryParse($text)
            ?? throw new InvalidArgumentException(
                sprintf("The capability requirement '%s' must match <capability ID>@[^]<version>[?].", $text),
            );
    }

    /**
     * 判断一个声明是否满足本依赖：同一能力且同一接口版本。
     */
    public function isSatisfiedBy(CapabilityDeclaration $declaration): bool
    {
        return $this->id === $declaration->id && $this->version === $declaration->version;
    }

    /**
     * 返回 `id@version[?]`（规范形式不带 `^`）。
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->id . '@' . $this->version . ($this->optional ? '?' : '');
    }

    /**
     * 返回线上编码。
     *
     * @return array{id: string, version: string, optional: bool}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'version' => (string) $this->version, 'optional' => $this->optional];
    }
}
