<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 贡献的配置节（RFC-0002「配置节」）。
 *
 * 领域节 `XiHan:<领域>`，可再带一层或多层 PascalCase 子节（如 `XiHan:Caching:Redis`）。
 */
final readonly class ConfigContribution implements \JsonSerializable
{
    /**
     * 创建配置节贡献。
     *
     * @param string $section 配置节
     * @param string|null $schema 配置的 JSON Schema 文本；没有为 null
     *
     * @throws InvalidArgumentException 配置节不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $section, public ?string $schema = null)
    {
        if (!Checks::matches(Checks::SECTION_PATTERN, $section)) {
            throw new InvalidArgumentException(sprintf(
                "The configuration section '%s' must match XiHan:<Domain> or XiHan:<Domain>:<Section>..., "
                . 'with PascalCase segments.',
                $section,
            ));
        }
    }

    /**
     * 返回线上编码。
     *
     * @return array{section: string, schema?: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $wire = ['section' => $this->section];
        if ($this->schema !== null) {
            $wire['schema'] = $this->schema;
        }

        return $wire;
    }
}
