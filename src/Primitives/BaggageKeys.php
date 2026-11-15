<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 已登记的 Baggage 键与键、值的文法（conventions/baggage-keys.md）。
 *
 * 键格式 `xihan.<name>`，小写、连字符分隔；载体的入站只收、出站只写已登记的键，
 * 进程内可以带格式合法的其他 `xihan.` 键，它们不出进程（RFC-0003 规则 3）。
 */
final class BaggageKeys
{
    /** 当前租户 ID。 */
    public const string TENANT = 'xihan.tenant';

    /** 当前主体 ID。 */
    public const string PRINCIPAL = 'xihan.principal';

    /** 业务关联 ID。 */
    public const string CORRELATION = 'xihan.correlation';

    /** 幂等键。 */
    public const string IDEMPOTENCY_KEY = 'xihan.idempotency-key';

    /** 请求文化（BCP 47）。 */
    public const string LOCALE = 'xihan.locale';

    /**
     * 已登记键的值的最大长度（字符）。
     *
     * 租户 ID、主体 ID、关联 ID 与区域都远短于它；W3C Baggage 整个头的建议上限是 8192 字节（ADR-0204）。
     */
    public const int MAX_VALUE_LENGTH = 256;

    /** 键文法；D 修饰符让 $ 只匹配串尾 */
    private const string KEY_PATTERN = '/^xihan\.[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    /** 已登记的键，按登记顺序 */
    private const array REGISTERED = [
        self::TENANT,
        self::PRINCIPAL,
        self::CORRELATION,
        self::IDEMPOTENCY_KEY,
        self::LOCALE,
    ];

    private function __construct()
    {
    }

    /**
     * 返回已登记的键，按登记顺序。
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return self::REGISTERED;
    }

    /**
     * 判断键是否符合 `xihan.<name>`。
     */
    public static function isWellFormed(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * 判断键是否已登记。
     */
    public static function isRegistered(string $key): bool
    {
        return in_array($key, self::REGISTERED, true);
    }

    /**
     * 判断值是否可以随已登记的键进入调用上下文（conventions/baggage-keys.md，ADR-0204、ADR-0233）。
     *
     * 非空、按 UTF-16 码元计不超过 MAX_VALUE_LENGTH（与 .NET、JavaScript、Java 的字符串长度相同，基本多文种平面
     * 以外的字符算两个）、不含 U+0000–U+001F 与 U+007F–U+009F。这些值会写进日志、审计与消息信封，换行能伪造日志行。
     * 不合的成员在建或派生调用上下文时丢弃，xihan.idempotency-key 例外。PHP 字符串是字节：不是合法 UTF-8 的值不可接受。
     */
    public static function isAcceptableValue(string $value): bool
    {
        // 每个 UTF-16 码元在 UTF-8 里至多占 3 个字节，超过 3 × 上限的字节数一定超长，先挡掉再做逐字的核对
        if ($value === '' || strlen($value) > self::MAX_VALUE_LENGTH * 3 || preg_match('//u', $value) !== 1) {
            return false;
        }
        if (preg_match('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', $value) === 1) {
            return false;
        }
        $units = (int) preg_match_all('/./su', $value) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);

        return $units <= self::MAX_VALUE_LENGTH;
    }

    /**
     * 判断一个成员能否进入调用上下文：未登记的键与幂等键不按值丢弃，其余已登记的键要求值可接受。
     */
    public static function admits(string $key, string $value): bool
    {
        return !self::isRegistered($key) || $key === self::IDEMPOTENCY_KEY || self::isAcceptableValue($value);
    }
}
