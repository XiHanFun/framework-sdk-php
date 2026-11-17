<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\OperationId;

/**
 * 贡献点（RFC-0002 `contributes`）：中间件、操作、配置节、权限码与健康项。
 *
 * 操作 ID 按操作 ID 文法校验（RFC-0002 解析规则 2，与操作登记表同一文法）；权限码是此包定义的权限
 * （RFC-0008 文法：小写、点分、至少两段，如 orders.read），不重复；插件的由宿主在登记它的代理操作时一并登记
 * （plugin/invocation.md §1）。
 */
final readonly class Contributions implements \JsonSerializable
{
    /** @var list<PipelineContribution> */
    public array $pipeline;

    /** @var list<string> */
    public array $operations;

    /** @var list<string> */
    public array $permissions;

    /** @var list<string> */
    public array $health;

    /**
     * 创建贡献点。
     *
     * @param list<PipelineContribution> $pipeline 中间件
     * @param list<string> $operations 操作 ID `<resource>.<verb>`
     * @param ConfigContribution|null $config 配置节
     * @param list<string> $permissions 此包定义的权限码
     * @param list<string> $health 健康项名
     *
     * @throws InvalidArgumentException 任一项不合法，或权限码重复（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        array $pipeline = [],
        array $operations = [],
        public ?ConfigContribution $config = null,
        array $permissions = [],
        array $health = [],
    ) {
        $this->pipeline = Checks::listOf(PipelineContribution::class, $pipeline, 'contributes.pipeline');
        $this->operations = Checks::strings($operations, 'contributes.operations');
        // 按操作 ID 文法而不是旧 Schema 较松的形式：清单不能声明登记表会拒绝的 ID
        foreach ($this->operations as $operation) {
            if (!OperationId::isValid($operation)) {
                throw new InvalidArgumentException(
                    sprintf("The operation '%s' in 'contributes.operations' must match <resource>.<verb>.", $operation),
                );
            }
        }
        // 插件定义的权限码由宿主在登记代理操作时一并登记：不合文法或重复的码宿主在发现时拒绝整个插件，
        // 这里同样拒绝，不让它等到宿主那里才失败
        $this->permissions = Checks::strings($permissions, 'contributes.permissions');
        foreach ($this->permissions as $code) {
            if (!Checks::matches(Checks::PERMISSION_CODE_PATTERN, $code)) {
                throw new InvalidArgumentException(sprintf(
                    "The permission code '%s' in 'contributes.permissions' must be a lowercase dot-separated code "
                    . "with at least two segments, such as 'orders.read'.",
                    $code,
                ));
            }
        }
        $duplicate = Checks::firstDuplicate($this->permissions);
        if ($duplicate !== null) {
            throw new InvalidArgumentException(
                sprintf("The permission code '%s' appears more than once in 'contributes.permissions'.", $duplicate),
            );
        }
        $this->health = Checks::strings($health, 'contributes.health');
        foreach ($this->health as $item) {
            if (!Checks::matches(Checks::HEALTH_PATTERN, $item)) {
                throw new InvalidArgumentException(
                    sprintf("The health item '%s' in 'contributes.health' must match ^[a-z][a-z0-9.-]*$.", $item),
                );
            }
        }
    }

    /**
     * 返回线上编码，成员顺序同清单写出（RFC-0002「写出」）：列表总写，没有配置节时不写 config。
     *
     * @return array{pipeline: list<PipelineContribution>, operations: list<string>, config?: ConfigContribution,
     *     permissions: list<string>, health: list<string>}
     */
    public function jsonSerialize(): array
    {
        $wire = ['pipeline' => $this->pipeline, 'operations' => $this->operations];
        if ($this->config !== null) {
            $wire['config'] = $this->config;
        }
        $wire['permissions'] = $this->permissions;
        $wire['health'] = $this->health;

        return $wire;
    }
}
