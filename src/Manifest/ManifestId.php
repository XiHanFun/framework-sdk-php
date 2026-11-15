<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

/**
 * 清单 ID 的文法 `xihan.<领域>.<名称>`（conventions/package-ids.md）。
 */
final class ManifestId
{
    /** 清单 ID 文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^xihan\.[a-z][a-z0-9]*\.[a-z][a-z0-9-]*$/D';

    private function __construct()
    {
    }

    /**
     * 判断清单 ID 是否符合 `xihan.<领域>.<名称>`。
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * 返回清单 ID 的领域段；不合法时为 null。
     */
    public static function domainOf(string $id): ?string
    {
        return self::isValid($id) ? explode('.', $id)[1] : null;
    }
}
