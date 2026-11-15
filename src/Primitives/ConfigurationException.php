<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 配置或装配错误，带错误码。
 *
 * 例如清单文件读不到、管道约束成环、操作重复登记：这些在配置期就该修好，不是调用期可恢复的失败。
 */
final class ConfigurationException extends \LogicException implements FrameworkException
{
    /**
     * 创建异常。
     *
     * @param string $message 面向开发者的英文句子，标识符加单引号
     * @param string $errorCode 错误码
     */
    public function __construct(string $message, private readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * {@inheritDoc}
     */
    public function toError(): Error
    {
        return Error::literal(
            ErrorCode::isValid($this->errorCode) ? $this->errorCode : ErrorCode::INVALID_ARGUMENT,
            $this->getMessage(),
        );
    }
}
