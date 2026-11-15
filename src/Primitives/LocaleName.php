<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 区域名的文法（RFC-0010「区域」）。
 *
 * 文法 `^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$`，最长 35 个字符，比较不区分大小写；默认区域 en-US 也是语言文件的基准。
 */
final class LocaleName
{
    /** 默认区域。 */
    public const string DEFAULT = 'en-US';

    /** 区域名的最大长度。 */
    public const int MAX_LENGTH = 35;

    /** 区域名文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D';

    private function __construct()
    {
    }

    /**
     * 判断区域名是否合法。
     */
    public static function isValid(string $locale): bool
    {
        return strlen($locale) <= self::MAX_LENGTH && preg_match(self::PATTERN, $locale) === 1;
    }

    /**
     * 校验区域名并原样返回。
     *
     * @throws InvalidArgumentException 区域名不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function validate(string $locale): string
    {
        if (!self::isValid($locale)) {
            throw new InvalidArgumentException(sprintf(
                "The locale '%s' must match <language>[-<subtag>]... with at most %d characters.",
                $locale,
                self::MAX_LENGTH,
            ));
        }

        return $locale;
    }
}
