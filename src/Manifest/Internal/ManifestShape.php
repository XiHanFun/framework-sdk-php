<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest\Internal;

use XiHan\Framework\Manifest\CapabilityId;
use XiHan\Framework\Manifest\Isolation;
use XiHan\Framework\Manifest\Kind;
use XiHan\Framework\Manifest\ManifestId;
use XiHan\Framework\Manifest\Role;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\OperationId;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Stability;

/**
 * 清单 JSON 的形状检查（RFC-0002 解析规则 2，ADR-0236）：照 manifest.schema.json 逐项核对，找出第一处问题的路径。
 *
 * 按成员在文本里出现的顺序检查（json_decode 的 stdClass 保留文本顺序），每个成员依次是 Schema 之外的成员、null、类型、
 * 取值，对象与数组进到里面再查，数组项按下标顺序；一个对象的成员查完后按 Schema 的 required 顺序查缺少的成员，
 * 路径是缺少的成员本身。跨字段的约束（插件必须声明隔离、能力不得重复等）由 Manifest::validate() 在之后核对。
 *
 * @internal 不属于公开 API，随时可能改变。
 *
 * @phpstan-type ValueShape array{kind: 'string'|'bool'|'object'|'array', accepts?: \Closure(string): bool,
 *     object?: array{members: array<string, mixed>, required: list<string>}, item?: array<string, mixed>,
 *     nonEmpty?: bool, unique?: bool}
 * @phpstan-type ObjectShape array{members: array<string, ValueShape>, required: list<string>}
 */
final class ManifestShape
{
    /** @var ObjectShape|null */
    private static ?array $root = null;

    private function __construct()
    {
    }

    /**
     * 返回清单 JSON（json_decode 的对象模式结果）不合 Schema 的第一处的路径；合 Schema 时为 null。
     */
    public static function findProblem(mixed $root): ?string
    {
        return $root instanceof \stdClass
            ? self::checkObject($root, self::$root ??= self::buildRoot(), JsonPath::ROOT)
            : JsonPath::ROOT;
    }

    /**
     * @param ObjectShape $shape
     */
    private static function checkObject(\stdClass $object, array $shape, string $path): ?string
    {
        $members = get_object_vars($object);
        foreach ($members as $name => $value) {
            $name = (string) $name;
            $memberPath = JsonPath::member($path, $name);
            $member = $shape['members'][$name] ?? null;
            if ($member === null) {
                return $memberPath;
            }
            $problem = self::checkValue($value, $member, $memberPath);
            if ($problem !== null) {
                return $problem;
            }
        }
        // 缺少的成员在对象的成员都查完之后才查
        foreach ($shape['required'] as $required) {
            if (!array_key_exists($required, $members)) {
                return JsonPath::member($path, $required);
            }
        }

        return null;
    }

    /**
     * Schema 里没有哪个成员或数组项能取 null：可选成员缺失表示没有，写成 null 不合法。
     *
     * @param ValueShape $shape
     */
    private static function checkValue(mixed $value, array $shape, string $path): ?string
    {
        if ($value === null) {
            return $path;
        }
        switch ($shape['kind']) {
            case 'string':
                $accepts = $shape['accepts'] ?? null;

                return is_string($value) && ($accepts === null || $accepts($value)) ? null : $path;
            case 'bool':
                return is_bool($value) ? null : $path;
            case 'object':
                /** @var ObjectShape $object */
                $object = $shape['object'] ?? ['members' => [], 'required' => []];

                return $value instanceof \stdClass ? self::checkObject($value, $object, $path) : $path;
        }
        if (!is_array($value) || (($shape['nonEmpty'] ?? false) && $value === [])) {
            return $path;
        }
        /** @var ValueShape $item */
        $item = $shape['item'] ?? ['kind' => 'string'];
        // 不得重复的字符串数组：先核对这一项本身，再看它是否与前面的项重复，重复时报第二次出现的位置
        $seen = [];
        foreach (array_values($value) as $index => $element) {
            $itemPath = JsonPath::index($path, $index);
            $problem = self::checkValue($element, $item, $itemPath);
            if ($problem !== null) {
                return $problem;
            }
            if (($shape['unique'] ?? false) && is_string($element)) {
                if (isset($seen[$element])) {
                    return $itemPath;
                }
                $seen[$element] = true;
            }
        }

        return null;
    }

    /**
     * 按 manifest.schema.json 建出根对象的形状。
     *
     * @return ObjectShape
     */
    private static function buildRoot(): array
    {
        $any = ['kind' => 'string'];
        $version = self::text(static fn (string $text): bool => Checks::interfaceVersion($text, false) !== null);
        $flag = ['kind' => 'bool'];
        $capability = self::object([
            'id' => self::text(CapabilityId::isValid(...)),
            'version' => $version,
        ], ['id', 'version']);
        $requirement = self::object([
            'id' => self::text(CapabilityId::isValid(...)),
            'version' => self::text(static fn (string $text): bool => Checks::interfaceVersion($text, true) !== null),
            'optional' => $flag,
        ], ['id', 'version']);
        $binding = self::object([
            'name' => self::pattern(Checks::BINDING_NAME_PATTERN),
            'type' => self::pattern(Checks::BINDING_TYPE_PATTERN),
            'required' => $flag,
        ], ['name', 'type']);
        $pipeline = self::object([
            'middleware' => self::text(static fn (string $text): bool => $text !== ''),
            'stage' => self::text(static fn (string $text): bool => PipelineStage::tryFrom($text) !== null),
            'before' => self::listOf($any),
            'after' => self::listOf($any),
        ], ['middleware', 'stage']);
        $config = self::object([
            'section' => self::pattern(Checks::SECTION_PATTERN),
            'schema' => $any,
        ], ['section']);
        $contributions = self::object([
            'pipeline' => self::listOf($pipeline),
            'operations' => self::listOf(self::text(OperationId::isValid(...))),
            'config' => $config,
            'permissions' => ['unique' => true] + self::listOf(self::pattern(Checks::PERMISSION_CODE_PATTERN)),
            'health' => self::listOf(self::pattern(Checks::HEALTH_PATTERN)),
        ], []);

        return [
            'members' => [
                '$schema' => $any,
                'id' => self::text(ManifestId::isValid(...)),
                'package' => $any,
                'version' => self::pattern(Checks::SEMVER_PATTERN),
                'primitives' => $version,
                'stability' => self::text(static fn (string $text): bool => Stability::tryFrom($text) !== null),
                'kind' => self::text(static fn (string $text): bool => Kind::tryFrom($text) !== null),
                'role' => self::text(static fn (string $text): bool => Role::tryFrom($text) !== null),
                'isolation' => self::text(static fn (string $text): bool => Isolation::tryFrom($text) !== null),
                'provides' => self::listOf($capability),
                'requires' => self::listOf($requirement),
                'bindings' => self::listOf($binding),
                'contributes' => $contributions,
                'activation' => ['nonEmpty' => true] + self::listOf(self::pattern(Checks::ACTIVATION_PATTERN)),
                'permissions' => self::listOf(self::pattern(Checks::PERMISSION_PATTERN)),
                'entry' => $any,
            ],
            'required' => ['id', 'version', 'primitives', 'stability', 'kind'],
        ];
    }

    /**
     * 取值受约束的字符串。
     *
     * @param \Closure(string): bool $accepts
     *
     * @return ValueShape
     */
    private static function text(\Closure $accepts): array
    {
        return ['kind' => 'string', 'accepts' => $accepts];
    }

    /**
     * 整串符合文法的字符串。
     *
     * @return ValueShape
     */
    private static function pattern(string $pattern): array
    {
        return self::text(static fn (string $text): bool => Checks::matches($pattern, $text));
    }

    /**
     * @param ValueShape $item
     *
     * @return ValueShape
     */
    private static function listOf(array $item): array
    {
        return ['kind' => 'array', 'item' => $item];
    }

    /**
     * @param array<string, ValueShape> $members
     * @param list<string> $required
     *
     * @return ValueShape
     */
    private static function object(array $members, array $required): array
    {
        return ['kind' => 'object', 'object' => ['members' => $members, 'required' => $required]];
    }
}
