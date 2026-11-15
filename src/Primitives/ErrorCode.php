<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 错误码的文法与原语层登记的错误码（RFC-0001、conventions/error-codes.md）。
 *
 * 错误码是 `<领域大写>_<KIND>_<NNN>`：面向程序，调用方按码判断；KIND 常量是约定的八类，
 * 领域可以自定义其他 KIND，文法只要求大写字母开头。
 */
final class ErrorCode
{
    /** 原语参数非法：空 Code、空消息键、清单跨字段约束等。 */
    public const string INVALID_ARGUMENT = 'PRIMITIVES_VALIDATION_001';

    /** 使用了未初始化的 Result；PHP 的 Result 只能经工厂创建，保留此码供线上还原与对照。 */
    public const string UNINITIALIZED_RESULT = 'PRIMITIVES_VALIDATION_002';

    /** KIND：调用方给出的输入不合法。 */
    public const string KIND_VALIDATION = 'VALIDATION';

    /** KIND：要找的东西不存在。 */
    public const string KIND_NOT_FOUND = 'NOTFOUND';

    /** KIND：与现有状态冲突。 */
    public const string KIND_CONFLICT = 'CONFLICT';

    /** KIND：没有权限。 */
    public const string KIND_DENIED = 'DENIED';

    /** KIND：依赖暂时不可用。 */
    public const string KIND_UNAVAILABLE = 'UNAVAILABLE';

    /** KIND：超过截止时间。 */
    public const string KIND_TIMEOUT = 'TIMEOUT';

    /** KIND：被取消。 */
    public const string KIND_CANCELLED = 'CANCELLED';

    /** KIND：意外失败。 */
    public const string KIND_UNEXPECTED = 'UNEXPECTED';

    /** 错误码文法；D 修饰符让 $ 只匹配串尾，不放过结尾的换行 */
    private const string PATTERN = '/^[A-Z][A-Z0-9]*_[A-Z][A-Z0-9]*_[0-9]{3}$/D';

    private function __construct()
    {
    }

    /**
     * 判断错误码是否符合 `<领域大写>_<KIND>_<NNN>`。
     */
    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /**
     * 校验错误码并原样返回。
     *
     * @throws InvalidArgumentException 错误码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function validate(string $code): string
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException(sprintf("The error code '%s' must match <DOMAIN>_<KIND>_<NNN>.", $code));
        }

        return $code;
    }

    /**
     * 返回错误码的 KIND 段；不合法时为 null。
     */
    public static function kindOf(string $code): ?string
    {
        return self::isValid($code) ? explode('_', $code)[1] : null;
    }

    /**
     * 返回错误码的领域段；不合法时为 null。
     */
    public static function domainOf(string $code): ?string
    {
        return self::isValid($code) ? explode('_', $code)[0] : null;
    }
}
