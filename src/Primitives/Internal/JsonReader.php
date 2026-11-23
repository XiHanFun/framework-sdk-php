<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

use XiHan\Framework\Primitives\JsonObject;

/**
 * 把已过共同检查的 JSON 文本读成 JsonNode（RFC-0001「JSON 文本」、RFC-0011 规则 2）。
 *
 * json_decode 的对象模式放不下以 U+0000 开头的成员名，关联数组模式分不清 `{}` 与 `[]`，两种都把 `1e400` 读成无穷：
 * 载荷与清单按文本顺序核对、任意 JSON 原样保留时用这里读出的树。只对 JsonText::check() 通过的文本调用，
 * 不再核对语法。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class JsonReader
{
    /** 数字的写法（RFC 8259） */
    private const string NUMBER = '/-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/A';

    /** 整串是一个数字的写法；D 修饰符让 $ 只匹配串尾 */
    private const string WHOLE_NUMBER = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D';

    private int $offset = 0;

    private function __construct(private readonly string $text)
    {
    }

    /**
     * 判断文本整串是不是一个 JSON 数字（RFC 8259 §6）：不带首尾空白、`+`、前导零与十六进制写法。配置节的字符串叶子
     * 读成数字时按这个文法（lifecycle.md §7.2），是数字的文本可以直接交给 parse()。
     */
    public static function isNumber(string $text): bool
    {
        return preg_match(self::WHOLE_NUMBER, $text) === 1;
    }

    /**
     * 读出文本的根值。
     */
    public static function parse(string $text): JsonNode
    {
        $reader = new self($text);

        return $reader->value();
    }

    /**
     * 把读出的树归到值模型（RFC-0010「模板」，ADR-0236）：对象是 JsonObject（成员名原样、按文本顺序），数组是列表，
     * 数字是 int 或 float（上溢为无穷），字符串、布尔与 null 原样。
     */
    public static function toValue(JsonNode $node): mixed
    {
        return match ($node->kind) {
            JsonNode::OBJECT => JsonObject::of(array_combine(
                array_map(static fn (array $member): string => $member[0], $node->members()),
                array_map(static fn (array $member): mixed => self::toValue($member[1]), $node->members()),
            )),
            JsonNode::ARRAY => array_map(self::toValue(...), $node->items()),
            default => $node->value,
        };
    }

    /**
     * 读出已过共同检查的文本，归到值模型。
     */
    public static function decode(string $text): mixed
    {
        return self::toValue(self::parse($text));
    }

    private function value(): JsonNode
    {
        $this->skipWhitespace();
        $start = $this->offset;
        $char = $this->text[$this->offset] ?? '';

        return match ($char) {
            '{' => $this->object($start),
            '[' => $this->array($start),
            '"' => new JsonNode(JsonNode::STRING, $this->string(), $start, $this->offset),
            't' => $this->literal(JsonNode::BOOL, true, 4),
            'f' => $this->literal(JsonNode::BOOL, false, 5),
            'n' => $this->literal(JsonNode::NULL, null, 4),
            default => $this->number($start),
        };
    }

    private function object(int $start): JsonNode
    {
        $this->offset++;
        $members = [];
        $this->skipWhitespace();
        if (($this->text[$this->offset] ?? '') === '}') {
            $this->offset++;

            return new JsonNode(JsonNode::OBJECT, $members, $start, $this->offset);
        }
        while (true) {
            $this->skipWhitespace();
            $name = $this->string();
            $this->skipWhitespace();
            // 冒号
            $this->offset++;
            $members[] = [$name, $this->value()];
            $this->skipWhitespace();
            $char = $this->text[$this->offset] ?? '';
            $this->offset++;
            if ($char !== ',') {
                return new JsonNode(JsonNode::OBJECT, $members, $start, $this->offset);
            }
        }
    }

    private function array(int $start): JsonNode
    {
        $this->offset++;
        $items = [];
        $this->skipWhitespace();
        if (($this->text[$this->offset] ?? '') === ']') {
            $this->offset++;

            return new JsonNode(JsonNode::ARRAY, $items, $start, $this->offset);
        }
        while (true) {
            $items[] = $this->value();
            $this->skipWhitespace();
            $char = $this->text[$this->offset] ?? '';
            $this->offset++;
            if ($char !== ',') {
                return new JsonNode(JsonNode::ARRAY, $items, $start, $this->offset);
            }
        }
    }

    /**
     * 读一个字符串（当前位置是开头的引号），返回转义还原后的值，位置移到闭合引号之后。
     */
    private function string(): string
    {
        $begin = $this->offset + 1;
        $offset = $begin;
        $length = strlen($this->text);
        $escaped = false;
        while ($offset < $length) {
            $offset += strcspn($this->text, '"\\', $offset);
            if ($offset >= $length || $this->text[$offset] === '"') {
                break;
            }
            // 反斜杠转义：跳过它和后一个字符
            $escaped = true;
            $offset += 2;
        }
        $raw = substr($this->text, $begin, $offset - $begin);
        $this->offset = $offset + 1;
        if (!$escaped) {
            return $raw;
        }
        // 文本已核对过：转义合法、代理项成对，解码不会失败
        $decoded = json_decode('"' . $raw . '"');

        return is_string($decoded) ? $decoded : $raw;
    }

    private function literal(string $kind, ?bool $value, int $length): JsonNode
    {
        $start = $this->offset;
        $this->offset += $length;

        return new JsonNode($kind, $value, $start, $this->offset);
    }

    /**
     * 读一个数字：整数写法（没有小数点与指数）且在 64 位范围内是 int，其余是 float（上溢为无穷，下溢为 0）。
     */
    private function number(int $start): JsonNode
    {
        preg_match(self::NUMBER, $this->text, $match, 0, $start);
        $raw = $match[0] ?? '0';
        $this->offset = $start + strlen($raw);
        $value = null;
        if (($match[1] ?? '') === '' && ($match[2] ?? '') === '') {
            $integer = filter_var($raw, FILTER_VALIDATE_INT);
            $value = is_int($integer) ? $integer : null;
        }

        return new JsonNode(JsonNode::NUMBER, $value ?? (float) $raw, $start, $this->offset);
    }

    private function skipWhitespace(): void
    {
        $this->offset += strspn($this->text, " \t\n\r", $this->offset);
    }
}
