<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 需要宿主注入的资源（RFC-0002 `bindings`）。
 *
 * 线上编码 `{"name":…,"type":…,"required":…}`；required 不写时为必需，写了就原样保留。
 */
final readonly class Binding implements \JsonSerializable
{
    /** 绑定名文法；D 修饰符让 $ 只匹配串尾 */
    private const string NAME_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    /** 资源类型文法，如 connection:relational、queue */
    private const string TYPE_PATTERN = '/^[a-z]+(:[a-z]+)?$/D';

    /**
     * 创建绑定。
     *
     * @param string $name 绑定名
     * @param string $type 资源类型，如 connection:relational、cache:distributed、queue、blob
     * @param bool|null $required 是否必需；null 表示没有写，按必需处理
     *
     * @throws InvalidArgumentException 名字或类型不合法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $name, public string $type, public ?bool $required = null)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "The binding '%s' (%s) is not valid: the name must match ^[a-z][a-z0-9-]*$ "
                . 'and the type ^[a-z]+(:[a-z]+)?$.',
                $name,
                $type,
            ));
        }
    }

    /**
     * 判断绑定是否必需；没有写 required 时为必需。
     */
    public function isRequired(): bool
    {
        return $this->required ?? true;
    }

    /**
     * 返回线上编码。
     *
     * @return array{name: string, type: string, required?: bool}
     */
    public function jsonSerialize(): array
    {
        $wire = ['name' => $this->name, 'type' => $this->type];
        if ($this->required !== null) {
            $wire['required'] = $this->required;
        }

        return $wire;
    }
}
