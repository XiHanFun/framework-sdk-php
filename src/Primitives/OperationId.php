<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 操作 ID 的文法 `<resource>.<verb>`（conventions/operation-ids.md，RFC-0011）。
 *
 * 资源是小写复数名词、连字符分隔，动词小写，如 `orders.create`、`order-lines.list`；整个 ID 不超过 MAX_LENGTH 个字符。
 * 放在原语层（与 .NET 一致），操作契约与清单的 `contributes.operations` 共用同一条规则（RFC-0002 解析规则 2）。
 * 向量 `primitives/operation-ids.json`。
 */
final class OperationId
{
    /**
     * 操作 ID 的最大长度（conventions/operation-ids.md，ADR-0276）：与 .NET 的 `OperationId.MaxLength` 相同。
     * 文法只含 ASCII 字符，字符数就是 `strlen` 给出的字节数。
     */
    public const int MAX_LENGTH = 128;

    /** 操作 ID 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*\.[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    private function __construct()
    {
    }

    /**
     * 判断操作 ID 是否合法：符合 `<resource>.<verb>`，且不超过 MAX_LENGTH 个字符。
     *
     * 上限与文法是同一条规则的两半：别处都合文法、只是多出一个字符的 ID 也不合法。
     */
    public static function isValid(string $id): bool
    {
        return strlen($id) <= self::MAX_LENGTH && preg_match(self::PATTERN, $id) === 1;
    }
}
