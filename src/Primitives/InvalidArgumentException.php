<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 参数非法的编程错误，带错误码。
 *
 * 缺省错误码是 PRIMITIVES_VALIDATION_001；其他包在参数非法时用自己登记的码。
 */
final class InvalidArgumentException extends \InvalidArgumentException implements FrameworkException
{
    /**
     * 创建异常。
     *
     * @param string $message 面向开发者的英文句子，标识符加单引号
     * @param string $errorCode 错误码
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = ErrorCode::INVALID_ARGUMENT,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function toError(): Error
    {
        return Error::literal(
            ErrorCode::isValid($this->errorCode) ? $this->errorCode : ErrorCode::INVALID_ARGUMENT,
            $this->getMessage(),
        );
    }
}
