<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Manifest\Internal\Fields;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Values;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Stability;

/**
 * 一份 `xihan.manifest.json`（RFC-0002，Schema 见 spec/manifest/manifest.schema.json）。
 *
 * 只做数据与格式校验，构造即校验：格式与跨字段约束不合的清单建不出来。收集、解析与求解是宿主的职责。
 * 跨字段约束：插件必须声明隔离，非插件不得声明隔离或权限，只有 Wasm 插件可以贡献中间件，提供、依赖与绑定不得重复。
 */
final readonly class Manifest implements \JsonSerializable
{
    /** 清单文件名。 */
    public const string FILE_NAME = 'xihan.manifest.json';

    /** 版本文法：SemVer 2.0 */
    private const string SEMVER_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/D';

    /** 激活事件文法 */
    private const string ACTIVATION_PATTERN = '/^(onStartup|onOperation:[a-z0-9.*-]+|onEvent:[a-z0-9.*-]+)$/D';

    /** 插件所需权限的文法 */
    private const string PERMISSION_PATTERN = '/^(net\.outbound|fs\.read|fs\.write|capability):.+$/D';

    /** 宿主启动时激活的事件 */
    private const string ON_STARTUP = 'onStartup';

    /** 顶层允许的字段，按 Schema 顺序 */
    private const array FIELDS = [
        '$schema', 'id', 'package', 'version', 'primitives', 'stability', 'kind', 'role', 'isolation',
        'provides', 'requires', 'bindings', 'contributes', 'activation', 'permissions', 'entry',
    ];

    /** @var list<CapabilityDeclaration> */
    public array $provides;

    /** @var list<CapabilityRequirement> */
    public array $requires;

    /** @var list<Binding> */
    public array $bindings;

    /** @var list<string> */
    public array $activation;

    /** @var list<string> */
    public array $permissions;

    /**
     * 创建清单并校验格式与跨字段约束。
     *
     * @param string $id 清单 ID `xihan.<领域>.<名称>`
     * @param string $version SemVer
     * @param int $primitives 依赖的原语层大版本
     * @param Stability $stability 稳定性
     * @param Kind $kind 种类
     * @param string|null $schema `$schema` 地址
     * @param string|null $package 所属包 ID
     * @param Role|null $role Provider 角色；null 即没有写，按 provider 处理
     * @param Isolation|null $isolation 插件隔离级别；只有插件写，且必须写
     * @param list<CapabilityDeclaration> $provides 提供的能力
     * @param list<CapabilityRequirement> $requires 依赖的能力
     * @param list<Binding> $bindings 需要宿主注入的资源
     * @param Contributions|null $contributes 贡献点
     * @param list<string> $activation 激活事件；空即 onStartup
     * @param list<string> $permissions 插件所需权限
     * @param string|null $entry 入口
     *
     * @throws InvalidArgumentException 格式或跨字段约束不合（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $id,
        public string $version,
        public int $primitives,
        public Stability $stability,
        public Kind $kind,
        public ?string $schema = null,
        public ?string $package = null,
        public ?Role $role = null,
        public ?Isolation $isolation = null,
        array $provides = [],
        array $requires = [],
        array $bindings = [],
        public ?Contributions $contributes = null,
        array $activation = [],
        array $permissions = [],
        public ?string $entry = null,
    ) {
        $this->provides = Checks::listOf(CapabilityDeclaration::class, $provides, 'provides');
        $this->requires = Checks::listOf(CapabilityRequirement::class, $requires, 'requires');
        $this->bindings = Checks::listOf(Binding::class, $bindings, 'bindings');
        $this->activation = Checks::strings($activation, 'activation');
        $this->permissions = Checks::strings($permissions, 'permissions');
        $this->validate();
    }

    /**
     * 解析并校验清单 JSON。
     *
     * 未知字段、类型不对、格式与跨字段约束都算失败；字段名区分大小写，接口版本只有 requires 里可以带 `^`。
     *
     * @throws InvalidArgumentException 清单不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function read(string $json): self
    {
        try {
            return self::fromFields(Fields::of(json_decode($json, false, 512, JSON_THROW_ON_ERROR), '$'));
        } catch (\JsonException | \UnexpectedValueException $exception) {
            throw new InvalidArgumentException(
                'The manifest is not valid JSON for the schema: ' . $exception->getMessage() . '.',
                previous: $exception,
            );
        }
    }

    /**
     * 读取并校验清单文件。
     *
     * @throws ConfigurationException 文件读不到（PRIMITIVES_VALIDATION_001）
     * @throws InvalidArgumentException 清单不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function readFile(string $path): self
    {
        $warning = null;
        // 读文件失败时 PHP 发出警告而不是抛出：收下警告文本，作为异常的原因交给调用方
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $json = is_file($path) ? file_get_contents($path) : false;
        } finally {
            restore_error_handler();
        }
        if ($json === false) {
            throw new ConfigurationException(
                sprintf("The manifest file '%s' could not be read.", $path),
                ErrorCode::INVALID_ARGUMENT,
                is_string($warning) ? new \RuntimeException($warning) : null,
            );
        }

        return self::read($json);
    }

    /**
     * 返回角色；没有写时为 provider。
     */
    public function effectiveRole(): Role
    {
        return $this->role ?? Role::Provider;
    }

    /**
     * 返回激活事件；没有写时为 onStartup。
     *
     * @return list<string>
     */
    public function effectiveActivation(): array
    {
        return $this->activation === [] ? [self::ON_STARTUP] : $this->activation;
    }

    /**
     * 判断是否在宿主启动时激活。
     */
    public function activatesOnStartup(): bool
    {
        return in_array(self::ON_STARTUP, $this->effectiveActivation(), true);
    }

    /**
     * 返回贡献的操作 ID；没有贡献时为空列表。
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->contributes->operations ?? [];
    }

    /**
     * 返回贡献的健康项；没有贡献时为空列表。
     *
     * @return list<string>
     */
    public function health(): array
    {
        return $this->contributes->health ?? [];
    }

    /**
     * 返回贡献的配置节；没有时为 null。
     */
    public function configSection(): ?string
    {
        return $this->contributes?->config?->section;
    }

    /**
     * 输出规范化的清单 JSON：两空格缩进，字段按 Schema 顺序，缺省值与空列表省略，末尾没有换行。
     */
    public function toJson(): string
    {
        $json = json_encode($this, JSON_PRETTY_PRINT | Values::JSON_FLAGS);

        // json_encode 固定四空格缩进；字符串里的换行已转义，行首空白只可能是缩进，按层数折半
        return (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)),
            $json,
        );
    }

    /**
     * 返回线上编码：字段按 Schema 顺序，没有写的与空列表省略。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $wire = [];
        if ($this->schema !== null) {
            $wire['$schema'] = $this->schema;
        }
        $wire['id'] = $this->id;
        if ($this->package !== null) {
            $wire['package'] = $this->package;
        }
        $wire['version'] = $this->version;
        $wire['primitives'] = (string) $this->primitives;
        $wire['stability'] = $this->stability->value;
        $wire['kind'] = $this->kind->value;
        if ($this->role !== null) {
            $wire['role'] = $this->role->value;
        }
        if ($this->isolation !== null) {
            $wire['isolation'] = $this->isolation->value;
        }
        $lists = ['provides' => $this->provides, 'requires' => $this->requires, 'bindings' => $this->bindings];
        foreach ($lists as $name => $list) {
            if ($list !== []) {
                $wire[$name] = $list;
            }
        }
        if ($this->contributes !== null) {
            $wire['contributes'] = $this->contributes;
        }
        if ($this->activation !== []) {
            $wire['activation'] = $this->activation;
        }
        if ($this->permissions !== []) {
            $wire['permissions'] = $this->permissions;
        }
        if ($this->entry !== null) {
            $wire['entry'] = $this->entry;
        }

        return $wire;
    }

    /**
     * 按 Schema 读取顶层对象。
     *
     * @throws \UnexpectedValueException 类型不对或有未知字段
     * @throws InvalidArgumentException 格式或跨字段约束不合
     */
    private static function fromFields(Fields $fields): self
    {
        $fields->only(self::FIELDS);
        $id = $fields->string('id');
        $kind = $fields->string('kind');
        $role = $fields->optionalString('role');
        $isolation = $fields->optionalString('isolation');
        $stability = Stability::tryFrom($fields->string('stability'))
            ?? throw new \UnexpectedValueException($fields->pathOf('stability') . ' is not a stability');

        return new self(
            id: $id,
            version: $fields->string('version'),
            primitives: Checks::interfaceVersion($fields->string('primitives'), false)
                ?? throw new \UnexpectedValueException($fields->pathOf('primitives') . ' must be a positive integer'),
            stability: $stability,
            kind: Kind::tryFrom($kind)
                ?? throw new InvalidArgumentException(sprintf("The kind '%s' of '%s' is not valid.", $kind, $id)),
            schema: $fields->optionalString('$schema'),
            package: $fields->optionalString('package'),
            role: $role === null ? null : (Role::tryFrom($role)
                ?? throw new InvalidArgumentException(sprintf("The role '%s' of '%s' is not valid.", $role, $id))),
            isolation: $isolation === null ? null : (Isolation::tryFrom($isolation)
                ?? throw new InvalidArgumentException(
                    sprintf("The isolation '%s' of '%s' is not valid.", $isolation, $id),
                )),
            provides: array_map(self::declarationOf(...), $fields->objects('provides')),
            requires: array_map(self::requirementOf(...), $fields->objects('requires')),
            bindings: array_map(self::bindingOf(...), $fields->objects('bindings')),
            contributes: self::contributionsOf($fields->optionalObject('contributes')),
            activation: $fields->strings('activation'),
            permissions: $fields->strings('permissions'),
            entry: $fields->optionalString('entry'),
        );
    }

    private static function declarationOf(Fields $fields): CapabilityDeclaration
    {
        $fields->only(['id', 'version']);
        $version = Checks::interfaceVersion($fields->string('version'), false)
            ?? throw new \UnexpectedValueException($fields->pathOf('version') . ' must be a positive integer');

        return new CapabilityDeclaration($fields->string('id'), $version);
    }

    private static function requirementOf(Fields $fields): CapabilityRequirement
    {
        $fields->only(['id', 'version', 'optional']);
        $version = Checks::interfaceVersion($fields->string('version'), true)
            ?? throw new \UnexpectedValueException(
                $fields->pathOf('version') . ' must be a positive integer, optionally prefixed with ^',
            );

        return new CapabilityRequirement($fields->string('id'), $version, $fields->optionalBool('optional') ?? false);
    }

    private static function bindingOf(Fields $fields): Binding
    {
        $fields->only(['name', 'type', 'required']);

        return new Binding($fields->string('name'), $fields->string('type'), $fields->optionalBool('required'));
    }

    private static function contributionsOf(?Fields $fields): ?Contributions
    {
        if ($fields === null) {
            return null;
        }
        $fields->only(['pipeline', 'operations', 'config', 'permissions', 'health']);
        $config = $fields->optionalObject('config');
        $config?->only(['section', 'schema']);

        return new Contributions(
            pipeline: array_map(self::pipelineContributionOf(...), $fields->objects('pipeline')),
            operations: $fields->strings('operations'),
            config: $config === null ? null : new ConfigContribution(
                $config->string('section'),
                $config->optionalString('schema'),
            ),
            permissions: $fields->strings('permissions'),
            health: $fields->strings('health'),
        );
    }

    private static function pipelineContributionOf(Fields $fields): PipelineContribution
    {
        $fields->only(['middleware', 'stage', 'before', 'after']);
        $stage = PipelineStage::tryFrom($fields->string('stage'))
            ?? throw new \UnexpectedValueException($fields->pathOf('stage') . ' is not a pipeline stage');

        return new PipelineContribution(
            $fields->string('middleware'),
            $stage,
            $fields->strings('before'),
            $fields->strings('after'),
        );
    }

    /**
     * 校验格式与跨字段约束。
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if (!ManifestId::isValid($this->id)) {
            $this->fail("The manifest ID '%s' must match xihan.<domain>.<name>", $this->id);
        }
        if (preg_match(self::SEMVER_PATTERN, $this->version) !== 1) {
            $this->fail("The version '%s' of '%s' must be a semantic version", $this->version, $this->id);
        }
        if ($this->primitives < 1) {
            $this->fail("The primitives version of '%s' must be a positive integer", $this->id);
        }
        // 隔离只对插件有意义：插件不写就无法决定怎么加载，非插件写了说明清单种类写错了
        if ($this->kind === Kind::Plugin && $this->isolation === null) {
            $this->fail("The plugin '%s' must declare its isolation", $this->id);
        }
        if ($this->kind !== Kind::Plugin && $this->isolation !== null) {
            $this->fail("The manifest '%s' is not a plugin and must not declare an isolation", $this->id);
        }
        if ($this->kind !== Kind::Plugin && $this->permissions !== []) {
            $this->fail("The manifest '%s' is not a plugin and must not declare permissions", $this->id);
        }
        $provided = array_map(static fn (CapabilityDeclaration $provided): string => $provided->id, $this->provides);
        $this->rejectDuplicate($provided, 'provides');
        $required = array_map(static fn (CapabilityRequirement $required): string => $required->id, $this->requires);
        $this->rejectDuplicate($required, 'requires');
        $bound = array_map(static fn (Binding $binding): string => $binding->name, $this->bindings);
        $this->rejectDuplicate($bound, 'declares the binding');
        foreach ($this->activation as $event) {
            if (preg_match(self::ACTIVATION_PATTERN, $event) !== 1) {
                $this->fail("The activation event '%s' of '%s' is not valid", $event, $this->id);
            }
        }
        foreach ($this->permissions as $permission) {
            if (preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
                $this->fail("The permission '%s' of '%s' is not valid", $permission, $this->id);
            }
        }
        // 只有 Wasm 插件能贡献中间件（RFC-0017「范围」）：进程外与进程内插件写了 contributes.pipeline，
        // .NET 宿主在发现或加载时以 PLUGINS_VALIDATION_001 拒绝；这里同样拒绝，不让作者以为中间件生效了、等到宿主那里才失败
        $pipeline = $this->contributes->pipeline ?? [];
        if ($this->kind === Kind::Plugin && $this->isolation !== Isolation::Wasm && $pipeline !== []) {
            $this->fail(
                "The plugin '%s' with isolation '%s' must not declare 'contributes.pipeline' (%s): "
                . 'only wasm plugins can contribute middleware',
                $this->id,
                $this->isolation->value ?? '',
                implode(', ', array_map(static fn (PipelineContribution $c): string => $c->middleware, $pipeline)),
            );
        }
    }

    /**
     * @param list<string> $values
     */
    private function rejectDuplicate(array $values, string $verb): void
    {
        $duplicate = Checks::firstDuplicate($values);
        if ($duplicate !== null) {
            $this->fail("The manifest '%s' %s '%s' more than once", $this->id, $verb, $duplicate);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function fail(string $format, string ...$arguments): never
    {
        throw new InvalidArgumentException(sprintf($format, ...$arguments) . '.');
    }
}
