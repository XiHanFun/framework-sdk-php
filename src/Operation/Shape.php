<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Internal\JsonNode;
use XiHan\Framework\Primitives\Internal\JsonReader;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\RawJson;

/**
 * 请求载荷的形状：声明请求类型的每个成员，解码时按它核对（RFC-0011 规则 2，ADR-0236，照 System.Text.Json 的缺省读取选项）。
 *
 * PHP 没有不经反射的类型化 JSON 解码，所以请求类型的结构用形状声明，Codec::typed() 先按形状核对、再把核对过的值交给
 * 创建函数。核对的规则各语言一致：
 *
 * - 成员名区分大小写，未知成员忽略；数字不从字符串读，字符串也不从数字读。
 * - int32 / int64 不接受小数点与指数（`1.0`、`1e2` 都不是整数），超出范围即不符；float 接受整数写法，上溢为无穷，下溢为 0。
 * - string、listOf、mapOf、object 与 any 接受 null，等于没有值；int32、int64、float、bool（含它们的数组项与字典值）
 *   不接受，nullable() 之后才接受。
 * - required() 的成员缺少时，在所在对象结束时报该对象的路径（根上为 `$`）；只要求出现，值为 null 也算出现。
 *   没有声明为必填的成员缺少时，交给创建函数的数组里就没有这个键。
 * - 按文本顺序报第一处类型不符；嵌套对象里缺少的成员先于外层后面的类型不符。
 *
 * 交给创建函数的值：string 是 string、int32 / int64 是 int、float 是 float、bool 是 bool、listOf 是列表、mapOf 与 object 是以
 * 成员名为键的数组（object 只含声明过、出现了的成员；PHP 会把纯数字的键转成整数）、any 是 RawJson，原样保留读到的原文
 * （`1e400`、超出双精度的整数与 `2.0` 写回时不变，decode() 取解码后的值）；接受 null 的值为 null 时就是 null。
 *
 * 载荷按 JsonReader 读，不经 stdClass：以 U+0000 开头的成员名照常核对。
 *
 * 插件的配置节也按形状读（Plugin\Configuration::decode()，lifecycle.md §7.2），规则只多三条：对象的成员名按 ASCII
 * 不区分大小写对上声明的成员；字符串叶子读成数字时按 JSON 数字的文法；读成布尔时只认 `true`、`false`（ASCII 不区分
 * 大小写）。其余照上面的规则。
 */
final readonly class Shape
{
    private const string STRING = 'string';
    private const string INT32 = 'int32';
    private const string INT64 = 'int64';
    private const string FLOAT = 'float';
    private const string BOOL = 'bool';
    private const string ANY = 'any';
    private const string LIST = 'list';
    private const string MAP = 'map';
    private const string OBJECT = 'object';

    private const int INT32_MIN = -2147483648;
    private const int INT32_MAX = 2147483647;

    /** 接受 null 的种类：引用类型的成员，与 .NET 一致 */
    private const array NULLABLE_KINDS = [self::STRING, self::ANY, self::LIST, self::MAP, self::OBJECT];

    /**
     * @param array<string, Shape> $members
     */
    private function __construct(
        private string $kind,
        private ?Shape $item = null,
        private array $members = [],
        private bool $required = false,
        private bool $nullable = false,
    ) {
    }

    /**
     * 字符串；接受 null。
     */
    public static function string(): self
    {
        return new self(self::STRING, nullable: true);
    }

    /**
     * 32 位有符号整数。
     */
    public static function int32(): self
    {
        return new self(self::INT32);
    }

    /**
     * 64 位有符号整数（PHP 的 int）。
     */
    public static function int64(): self
    {
        return new self(self::INT64);
    }

    /**
     * 双精度浮点；接受整数写法。
     */
    public static function float(): self
    {
        return new self(self::FLOAT);
    }

    /**
     * 布尔。
     */
    public static function bool(): self
    {
        return new self(self::BOOL);
    }

    /**
     * 任意 JSON，以 RawJson 原样交出读到的原文；接受 null。
     */
    public static function any(): self
    {
        return new self(self::ANY, nullable: true);
    }

    /**
     * 数组，每项按 `$item` 核对；接受 null。
     */
    public static function listOf(self $item): self
    {
        return new self(self::LIST, $item, nullable: true);
    }

    /**
     * 以字符串为键的字典（JSON 对象），每个值按 `$value` 核对；接受 null。
     */
    public static function mapOf(self $value): self
    {
        return new self(self::MAP, $value, nullable: true);
    }

    /**
     * 对象，成员按名字声明（区分大小写）；接受 null。
     *
     * @param array<string, Shape> $members 成员名 → 形状
     *
     * @throws InvalidArgumentException 成员不是 Shape（PRIMITIVES_VALIDATION_001）
     */
    public static function object(array $members): self
    {
        foreach ($members as $name => $member) {
            // @phpstan-ignore instanceof.alwaysTrue (调用方传的数组在运行时才知道内容)
            if (!$member instanceof self) {
                throw new InvalidArgumentException(
                    sprintf("The member '%s' of an object shape must be a Shape.", $name),
                );
            }
        }

        return new self(self::OBJECT, members: $members, nullable: true);
    }

    /**
     * 声明为必填的成员：缺少时在所在对象结束时报该对象的路径；只要求出现，值为 null 也算出现。
     */
    public function required(): self
    {
        return new self($this->kind, $this->item, $this->members, true, $this->nullable);
    }

    /**
     * 接受 null 的整数、浮点或布尔（如 C# 的 `long?`）。
     */
    public function nullable(): self
    {
        return new self($this->kind, $this->item, $this->members, $this->required, true);
    }

    /**
     * 是否是对象或字典：请求载荷的根必须是这两种之一。
     */
    public function isObjectLike(): bool
    {
        return $this->kind === self::OBJECT || $this->kind === self::MAP;
    }

    /**
     * 按形状核对 JsonReader 读出的值，核对过的值写进 `$out`。
     *
     * @internal 由 Codec 与 Plugin\Configuration 调用。
     *
     * @param string $text 载荷原文：任意 JSON 按原文保留
     * @param bool $configuration 按配置节的读法（lifecycle.md §7.2）：成员名按 ASCII 不区分大小写，字符串叶子可以读成
     *     数字与布尔
     *
     * @return string|null 第一处问题的路径；合形状时为 null
     */
    public function read(JsonNode $node, string $path, string $text, mixed &$out, bool $configuration = false): ?string
    {
        $out = null;
        if ($node->kind === JsonNode::NULL) {
            return $this->nullable || in_array($this->kind, self::NULLABLE_KINDS, true) ? null : $path;
        }
        if ($configuration && is_string($node->value)) {
            $node = $this->configurationLeaf($node, $node->value);
        }
        $value = $node->value;

        return match ($this->kind) {
            self::STRING => $node->kind === JsonNode::STRING ? self::accept($value, $out) : $path,
            self::INT32 => is_int($value) && $value >= self::INT32_MIN && $value <= self::INT32_MAX
                ? self::accept($value, $out)
                : $path,
            self::INT64 => is_int($value) ? self::accept($value, $out) : $path,
            self::FLOAT => $node->kind === JsonNode::NUMBER ? self::accept((float) $value, $out) : $path,
            self::BOOL => is_bool($value) ? self::accept($value, $out) : $path,
            // 任意 JSON 原样保留读到的原文：1e400、超出双精度的整数与 2.0 写回时不变（ADR-0236）
            self::ANY => self::accept(RawJson::checked($node->raw($text)), $out),
            self::LIST => $node->kind === JsonNode::ARRAY
                ? $this->readList($node, $path, $text, $out, $configuration)
                : $path,
            self::MAP => $node->kind === JsonNode::OBJECT
                ? $this->readMap($node, $path, $text, $out, $configuration)
                : $path,
            default => $node->kind === JsonNode::OBJECT
                ? $this->readObject($node, $path, $text, $out, $configuration)
                : $path,
        };
    }

    /**
     * 配置节的字符串叶子（lifecycle.md §7.2）：宿主写出的叶子一律是 JSON 字符串。目标是数字且文本合 JSON 数字的文法时
     * 换成那个数字，目标是布尔且文本是 true 或 false（ASCII 不区分大小写）时换成布尔；其余原样，照常规读法核对。
     */
    private function configurationLeaf(JsonNode $node, string $leaf): JsonNode
    {
        if (in_array($this->kind, [self::INT32, self::INT64, self::FLOAT], true) && JsonReader::isNumber($leaf)) {
            // 当作同一段文本的 JSON 数字读：整数写法是 int，带小数点或指数、超出 64 位的是 float，整数的形状照常不接受
            return JsonReader::parse($leaf);
        }
        if ($this->kind === self::BOOL) {
            // strcasecmp 自 PHP 8.2 起与区域设置无关，只折叠 ASCII 字母
            $flag = match (true) {
                strcasecmp($leaf, 'true') === 0 => true,
                strcasecmp($leaf, 'false') === 0 => false,
                default => null,
            };

            return $flag === null ? $node : new JsonNode(JsonNode::BOOL, $flag, $node->start, $node->end);
        }

        return $node;
    }

    private function readList(JsonNode $node, string $path, string $text, mixed &$out, bool $configuration): ?string
    {
        $items = [];
        foreach ($node->items() as $index => $item) {
            $problem = $this->itemShape()->read($item, JsonPath::index($path, $index), $text, $read, $configuration);
            if ($problem !== null) {
                return $problem;
            }
            $items[] = $read;
        }
        $out = $items;

        return null;
    }

    private function readMap(JsonNode $node, string $path, string $text, mixed &$out, bool $configuration): ?string
    {
        $members = [];
        foreach ($node->members() as [$name, $item]) {
            // 字典的键不是成员名：配置节的读法也原样保留
            $problem = $this->itemShape()->read($item, JsonPath::member($path, $name), $text, $read, $configuration);
            if ($problem !== null) {
                return $problem;
            }
            $members[$name] = $read;
        }
        $out = $members;

        return null;
    }

    private function readObject(JsonNode $node, string $path, string $text, mixed &$out, bool $configuration): ?string
    {
        $members = [];
        $matched = $configuration ? $this->matchedMembers($node) : null;
        foreach ($node->members() as $index => [$name, $item]) {
            // 成员名区分大小写；配置节的读法先按名字对上声明的成员，路径仍照文本里的写法
            $declared = $matched === null ? $name : ($matched[$index] ?? null);
            if ($declared === null || !isset($this->members[$declared])) {
                continue;
            }
            $memberPath = JsonPath::member($path, $name);
            $problem = $this->members[$declared]->read($item, $memberPath, $text, $read, $configuration);
            if ($problem !== null) {
                return $problem;
            }
            $members[$declared] = $read;
        }
        // 缺少的必填成员在对象的成员都读完后才发现，报所在对象的路径
        foreach ($this->members as $name => $member) {
            if ($member->required && !array_key_exists($name, $members)) {
                return $path;
            }
        }
        $out = $members;

        return null;
    }

    /**
     * 配置节的成员名匹配（lifecycle.md §7.2，与 IConfiguration 一致）：每个声明的成员取逐字同名的文本成员，没有时取文本里
     * 靠前的、只差 ASCII 大小写的那个；一个文本成员只对上一个声明的成员。
     *
     * @return array<int, string> 文本成员的下标 → 声明的成员名；没对上的文本成员不在其中，照未知成员忽略
     */
    private function matchedMembers(JsonNode $node): array
    {
        $names = array_map(static fn (array $member): string => $member[0], $node->members());
        $matched = [];
        $unmatched = [];
        foreach (array_keys($this->members) as $declared) {
            // PHP 把纯数字的成员名存成整数键
            $declared = (string) $declared;
            $index = array_search($declared, $names, true);
            if ($index === false) {
                $unmatched[] = $declared;
            } else {
                $matched[$index] = $declared;
            }
        }
        foreach ($unmatched as $declared) {
            foreach ($names as $index => $name) {
                // strcasecmp 自 PHP 8.2 起与区域设置无关，只折叠 ASCII 字母
                if (!isset($matched[$index]) && strcasecmp($name, $declared) === 0) {
                    $matched[$index] = $declared;
                    break;
                }
            }
        }

        return $matched;
    }

    private function itemShape(): self
    {
        return $this->item ?? self::any();
    }

    private static function accept(mixed $value, mixed &$out): null
    {
        $out = $value;

        return null;
    }
}
