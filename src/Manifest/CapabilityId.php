<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

/**
 * 能力 ID 的文法 `<领域>.<能力>`（conventions/capability-ids.md）。
 */
final class CapabilityId
{
    /** 能力 ID 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^[a-z][a-z0-9]*\.[a-z][a-z0-9-]*$/D';

    private function __construct()
    {
    }

    /**
     * 判断能力 ID 是否符合 `<领域>.<能力>`。
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * 返回能力 ID 的领域段；不合法时为 null。
     */
    public static function domainOf(string $id): ?string
    {
        return self::isValid($id) ? explode('.', $id)[0] : null;
    }
}
