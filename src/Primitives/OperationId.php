<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 操作 ID 的文法 `<resource>.<verb>`（conventions/operation-ids.md，RFC-0011）。
 *
 * 资源是小写复数名词、连字符分隔，动词小写，如 `orders.create`、`order-lines.list`。放在原语层（与 .NET 一致），
 * 操作契约与清单的 `contributes.operations` 共用同一文法（RFC-0002 解析规则 2）。
 */
final class OperationId
{
    /** 操作 ID 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*\.[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    private function __construct()
    {
    }

    /**
     * 判断操作 ID 是否符合 `<resource>.<verb>`。
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
