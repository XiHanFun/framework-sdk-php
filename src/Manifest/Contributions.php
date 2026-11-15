<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 贡献点（RFC-0002 `contributes`）：中间件、操作、配置节、权限码与健康项。
 *
 * 权限码是此包定义的权限（RFC-0008 文法：小写、点分、至少两段，如 orders.read），不重复；
 * 插件的由宿主在登记它的代理操作时一并登记（plugin/invocation.md §1）。
 */
final readonly class Contributions implements \JsonSerializable
{
    /** 清单里操作 ID 的文法（Schema 的形式校验） */
    private const string OPERATION_PATTERN = '/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/D';

    /** 权限码文法（RFC-0008） */
    private const string PERMISSION_CODE_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/D';

    /** 健康项文法，约定 <领域>.<机制>.<对象> */
    private const string HEALTH_PATTERN = '/^[a-z][a-z0-9.-]*$/D';

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
        foreach ($this->operations as $operation) {
            if (preg_match(self::OPERATION_PATTERN, $operation) !== 1) {
                throw new InvalidArgumentException(
                    sprintf("The operation '%s' in 'contributes.operations' must match <resource>.<verb>.", $operation),
                );
            }
        }
        // 插件定义的权限码由宿主在登记代理操作时一并登记：不合文法或重复的码宿主在发现时拒绝整个插件，
        // 这里同样拒绝，不让它等到宿主那里才失败
        $this->permissions = Checks::strings($permissions, 'contributes.permissions');
        foreach ($this->permissions as $code) {
            if (preg_match(self::PERMISSION_CODE_PATTERN, $code) !== 1) {
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
            if (preg_match(self::HEALTH_PATTERN, $item) !== 1) {
                throw new InvalidArgumentException(
                    sprintf("The health item '%s' in 'contributes.health' must match ^[a-z][a-z0-9.-]*$.", $item),
                );
            }
        }
    }

    /**
     * 返回线上编码：字段按 Schema 顺序，空列表与空配置节省略；什么都没有时是空对象。
     */
    public function jsonSerialize(): \stdClass
    {
        $wire = new \stdClass();
        if ($this->pipeline !== []) {
            $wire->pipeline = $this->pipeline;
        }
        if ($this->operations !== []) {
            $wire->operations = $this->operations;
        }
        if ($this->config !== null) {
            $wire->config = $this->config;
        }
        if ($this->permissions !== []) {
            $wire->permissions = $this->permissions;
        }
        if ($this->health !== []) {
            $wire->health = $this->health;
        }

        return $wire;
    }
}
