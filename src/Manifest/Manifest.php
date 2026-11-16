<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

use XiHan\Framework\Manifest\Internal\Checks;
use XiHan\Framework\Manifest\Internal\Fields;
use XiHan\Framework\Manifest\Internal\ManifestShape;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\JsonText;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\KernelMessages;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Stability;
use XiHan\Framework\Primitives\Unit;

/**
 * 一份 `xihan.manifest.json`（RFC-0002，Schema 见 spec/manifest/manifest.schema.json）。
 *
 * 只做数据与格式校验，收集、解析与求解是宿主的职责。构造时核对单个字段的格式，字段之间的约束由 validate() 核对：
 * 插件必须声明隔离，非插件不得声明隔离或权限，提供、依赖与绑定不得重复。read() 两样都核对。
 * 只有 Wasm 插件能贡献中间件，这一条由宿主在发现插件时核对（PLUGINS_VALIDATION_001），读取清单时不核对。
 */
final readonly class Manifest implements \JsonSerializable
{
    /** 清单文件名。 */
    public const string FILE_NAME = 'xihan.manifest.json';

    /** 清单读取失败的错误码（RFC-0002 解析规则 2）。 */
    public const string ERROR_CODE_INVALID = 'KERNEL_CAPABILITY_006';

    /** 写出的 `$schema`。 */
    public const string SCHEMA_URI = 'https://spec.xihanfun.com/manifest/v1.json';

    /** 宿主启动时激活的事件 */
    private const string ON_STARTUP = 'onStartup';

    /** UTF-8 的 BOM：readFile() 跳过它，read() 把它当作语法错误 */
    private const string UTF8_BOM = "\xEF\xBB\xBF";

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

    /** @var list<string>|null 激活事件；null 即没有写（按 onStartup），写了就至少一项 */
    public ?array $activation;

    /** @var list<string> */
    public array $permissions;

    /**
     * 创建清单并校验单个字段的格式；字段之间的约束由 validate() 核对。
     *
     * @param string $id 清单 ID `xihan.<领域>.<名称>`
     * @param string $version SemVer
     * @param int $primitives 依赖的原语层大版本，1 到 2147483647
     * @param Stability $stability 稳定性
     * @param Kind $kind 种类
     * @param string|null $schema 读到的 `$schema` 地址；写出时总是 SCHEMA_URI
     * @param string|null $package 所属包 ID
     * @param Role|null $role Provider 角色；null 即没有写，按 provider 处理
     * @param Isolation|null $isolation 插件隔离级别；只有插件写，且必须写
     * @param list<CapabilityDeclaration> $provides 提供的能力
     * @param list<CapabilityRequirement> $requires 依赖的能力
     * @param list<Binding> $bindings 需要宿主注入的资源
     * @param Contributions|null $contributes 贡献点
     * @param list<string>|null $activation 激活事件；null 即没有写，按 onStartup 处理；写了就至少一项
     * @param list<string> $permissions 插件所需权限
     * @param string|null $entry 入口
     *
     * @throws InvalidArgumentException 格式不合（PRIMITIVES_VALIDATION_001）
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
        ?array $activation = null,
        array $permissions = [],
        public ?string $entry = null,
    ) {
        $this->provides = Checks::listOf(CapabilityDeclaration::class, $provides, 'provides');
        $this->requires = Checks::listOf(CapabilityRequirement::class, $requires, 'requires');
        $this->bindings = Checks::listOf(Binding::class, $bindings, 'bindings');
        $this->activation = $activation === null ? null : Checks::strings($activation, 'activation');
        $this->permissions = Checks::strings($permissions, 'permissions');
        $this->checkFormats();
    }

    /**
     * 读取 JSON 文本里的清单（RFC-0002 解析规则 2，ADR-0236），文本按原样检查，开头的 BOM 是语法错误。
     *
     * 失败都是 KERNEL_CAPABILITY_006，按这个顺序只报第一处：不是 JSON 为 kernel.manifest.invalid_json（reason 为 `$`，
     * 任何一层有重复成员时为第一处重复的路径）；根是 null 为 kernel.manifest.empty；不合 Schema 为
     * kernel.manifest.invalid（reason 为第一处问题的路径）；跨字段约束不合保留 validate() 的消息键与参数 id。
     *
     * @throws ConfigurationException 清单不合法（KERNEL_CAPABILITY_006），toError() 是逐字的错误
     */
    public static function read(string $json): self
    {
        $problem = JsonText::check($json);
        if ($problem !== null) {
            throw self::invalid(KernelMessages::MANIFEST_INVALID_JSON, ['reason' => $problem->path]);
        }
        try {
            $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // 检查已通过：只剩 stdClass 表示不了的成员名（以 U+0000 开头），它不是 Schema 里的成员
            throw self::invalid(KernelMessages::MANIFEST_INVALID, ['reason' => JsonPath::ROOT]);
        }
        if ($root === null) {
            throw self::invalid(KernelMessages::MANIFEST_EMPTY, []);
        }
        $path = ManifestShape::findProblem($root);
        if ($path !== null) {
            throw self::invalid(KernelMessages::MANIFEST_INVALID, ['reason' => $path]);
        }
        // 形状已核对：读取与格式校验不会再失败，失败即两处规则不一致
        $manifest = self::fromFields(Fields::of($root, JsonPath::ROOT));
        $error = $manifest->validate()->error();
        if ($error !== null) {
            throw ConfigurationException::fromError($error->withCode(self::ERROR_CODE_INVALID));
        }

        return $manifest;
    }

    /**
     * 读取清单文件：按 UTF-8 读，跳过开头的 UTF-8 BOM，其余同 read()。
     *
     * @throws ConfigurationException 文件不存在（kernel.manifest.file_missing，参数 path）或清单不合法，都是
     *     KERNEL_CAPABILITY_006
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
            throw self::invalid(
                KernelMessages::MANIFEST_FILE_MISSING,
                ['path' => $path],
                is_string($warning) ? new \RuntimeException($warning) : null,
            );
        }

        return self::read(str_starts_with($json, self::UTF8_BOM) ? substr($json, strlen(self::UTF8_BOM)) : $json);
    }

    /**
     * 核对字段之间的约束（.NET 的 Manifest.Validate），按这个顺序报第一个：插件没有 isolation、非插件写了 isolation 或
     * permissions、provides 的能力 ID 重复、requires 的能力 ID 重复、bindings 的名字重复。
     *
     * 失败为 PRIMITIVES_VALIDATION_001，消息键 primitives.manifest.*，参数只有 id。
     *
     * @return Result<Unit>
     */
    public function validate(): Result
    {
        $provided = array_map(static fn (CapabilityDeclaration $provided): string => $provided->id, $this->provides);
        $required = array_map(static fn (CapabilityRequirement $required): string => $required->id, $this->requires);
        $bound = array_map(static fn (Binding $binding): string => $binding->name, $this->bindings);
        $key = match (true) {
            $this->kind === Kind::Plugin && $this->isolation === null => KernelMessages::MANIFEST_ISOLATION_REQUIRED,
            $this->kind !== Kind::Plugin && ($this->isolation !== null || $this->permissions !== [])
                => KernelMessages::MANIFEST_ISOLATION_NOT_ALLOWED,
            Checks::firstDuplicate($provided) !== null => KernelMessages::MANIFEST_DUPLICATE_PROVIDES,
            Checks::firstDuplicate($required) !== null => KernelMessages::MANIFEST_DUPLICATE_REQUIRES,
            Checks::firstDuplicate($bound) !== null => KernelMessages::MANIFEST_DUPLICATE_BINDINGS,
            default => null,
        };

        return $key === null
            ? Result::success()
            : Result::fail(Error::validation(ErrorCode::INVALID_ARGUMENT, $key, ['id' => $this->id]));
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
        return $this->activation ?? [self::ON_STARTUP];
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
     * 写出清单 JSON（RFC-0002「写出」，ADR-0236），排版各语言逐字相同：两个空格缩进，换行只用 LF，末尾不换行，
     * 成员名与值之间是 `: `；空数组写 `[]`，非空数组每项一行；成员按 jsonSerialize() 的顺序，没有值的可选成员不写，
     * 列表总写；字符串只转义 `"`、`\` 与控制字符。
     */
    public function toJson(): string
    {
        return self::indented(Json::writable($this), 0);
    }

    /**
     * 返回写出的结构，成员顺序：`$schema`（固定为 SCHEMA_URI）、id、package?、version、primitives、stability、kind、
     * role（缺省写 provider）、isolation?、provides、requires、bindings、contributes、activation（缺省写 onStartup）、
     * permissions、entry?。带 ? 的没有值时不写。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $wire = ['$schema' => self::SCHEMA_URI, 'id' => $this->id];
        if ($this->package !== null) {
            $wire['package'] = $this->package;
        }
        $wire['version'] = $this->version;
        $wire['primitives'] = (string) $this->primitives;
        $wire['stability'] = $this->stability->value;
        $wire['kind'] = $this->kind->value;
        $wire['role'] = $this->effectiveRole()->value;
        if ($this->isolation !== null) {
            $wire['isolation'] = $this->isolation->value;
        }
        $wire['provides'] = $this->provides;
        $wire['requires'] = $this->requires;
        $wire['bindings'] = $this->bindings;
        $wire['contributes'] = $this->contributes ?? new Contributions();
        $wire['activation'] = $this->effectiveActivation();
        $wire['permissions'] = $this->permissions;
        if ($this->entry !== null) {
            $wire['entry'] = $this->entry;
        }

        return $wire;
    }

    /**
     * 按规范排版写出一个值：对象是关联数组或 stdClass，数组是列表。
     */
    private static function indented(mixed $value, int $depth): string
    {
        if (is_string($value)) {
            return Json::string($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_array($value) && !$value instanceof \stdClass) {
            return 'null';
        }
        $indent = "\n" . str_repeat('  ', $depth + 1);
        $close = "\n" . str_repeat('  ', $depth);
        if (is_array($value) && array_is_list($value)) {
            if ($value === []) {
                return '[]';
            }
            $items = array_map(static fn (mixed $item): string => self::indented($item, $depth + 1), $value);

            return '[' . $indent . implode(',' . $indent, $items) . $close . ']';
        }
        $members = [];
        foreach ($value instanceof \stdClass ? get_object_vars($value) : $value as $name => $item) {
            $members[] = Json::string((string) $name) . ': ' . self::indented($item, $depth + 1);
        }

        return $members === [] ? '{}' : '{' . $indent . implode(',' . $indent, $members) . $close . '}';
    }

    /**
     * 按 Schema 读取顶层对象；形状已核对过。
     *
     * @throws \UnexpectedValueException 类型不对或有未知字段
     * @throws InvalidArgumentException 格式不合
     */
    private static function fromFields(Fields $fields): self
    {
        $fields->only(self::FIELDS);
        $role = $fields->optionalString('role');
        $isolation = $fields->optionalString('isolation');

        return new self(
            id: $fields->string('id'),
            version: $fields->string('version'),
            primitives: Checks::interfaceVersion($fields->string('primitives'), false)
                ?? throw new \UnexpectedValueException($fields->pathOf('primitives') . ' must be a positive integer'),
            stability: Stability::tryFrom($fields->string('stability'))
                ?? throw new \UnexpectedValueException($fields->pathOf('stability') . ' is not a stability'),
            kind: Kind::tryFrom($fields->string('kind'))
                ?? throw new \UnexpectedValueException($fields->pathOf('kind') . ' is not a kind'),
            schema: $fields->optionalString('$schema'),
            package: $fields->optionalString('package'),
            role: $role === null ? null : (Role::tryFrom($role)
                ?? throw new \UnexpectedValueException($fields->pathOf('role') . ' is not a role')),
            isolation: $isolation === null ? null : (Isolation::tryFrom($isolation)
                ?? throw new \UnexpectedValueException($fields->pathOf('isolation') . ' is not an isolation')),
            provides: array_map(self::declarationOf(...), $fields->objects('provides')),
            requires: array_map(self::requirementOf(...), $fields->objects('requires')),
            bindings: array_map(self::bindingOf(...), $fields->objects('bindings')),
            contributes: self::contributionsOf($fields->optionalObject('contributes')),
            activation: $fields->optionalStrings('activation'),
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
     * 核对单个字段的格式。
     *
     * @throws InvalidArgumentException
     */
    private function checkFormats(): void
    {
        if (!ManifestId::isValid($this->id)) {
            $this->fail("The manifest ID '%s' must match xihan.<domain>.<name>", $this->id);
        }
        if (!Checks::matches(Checks::SEMVER_PATTERN, $this->version)) {
            $this->fail("The version '%s' of '%s' must be a semantic version", $this->version, $this->id);
        }
        if (!Checks::isVersion($this->primitives)) {
            $this->fail("The primitives version of '%s' must be between 1 and 2147483647", $this->id);
        }
        // activation 写了就至少一项：空数组不是「没有写」，缺省才是 onStartup（RFC-0002 解析规则 2）
        if ($this->activation === []) {
            $this->fail(
                "The activation of '%s' must list at least one event; omit it to activate on startup",
                $this->id,
            );
        }
        foreach ($this->activation ?? [] as $event) {
            if (!Checks::matches(Checks::ACTIVATION_PATTERN, $event)) {
                $this->fail("The activation event '%s' of '%s' is not valid", $event, $this->id);
            }
        }
        foreach ($this->permissions as $permission) {
            if (!Checks::matches(Checks::PERMISSION_PATTERN, $permission)) {
                $this->fail("The permission '%s' of '%s' is not valid", $permission, $this->id);
            }
        }
    }

    /**
     * 清单读取失败：KERNEL_CAPABILITY_006。
     *
     * @param array<string, string> $arguments
     */
    private static function invalid(string $key, array $arguments, ?\Throwable $previous = null): ConfigurationException
    {
        return ConfigurationException::fromError(Error::of(self::ERROR_CODE_INVALID, $key, $arguments), $previous);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function fail(string $format, string ...$arguments): never
    {
        throw new InvalidArgumentException(sprintf($format, ...$arguments) . '.');
    }
}
