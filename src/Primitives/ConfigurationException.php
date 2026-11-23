<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 配置或装配错误，带错误码。
 *
 * 例如清单文件读不到、管道约束成环、操作重复登记：这些在配置期就该修好，不是调用期可恢复的失败。
 * 共用表里有消息键的错误（如 KERNEL_OPERATION_001）以 fromError() 创建：异常消息是英文文案，toError() 交回带键与参数的 Error。
 */
final class ConfigurationException extends \LogicException implements FrameworkException
{
    /**
     * 创建异常。
     *
     * @param string $message 面向开发者的英文句子，标识符加单引号
     * @param string $errorCode 错误码
     * @param Error|null $error 带键与参数的错误；有时 toError() 交回它
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        ?\Throwable $previous = null,
        private readonly ?Error $error = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 用带键与参数的错误创建异常：消息按 en-US 模板（内核消息键）或描述写成英文，不随进程默认区域变化。
     */
    public static function fromError(Error $error, ?\Throwable $previous = null): self
    {
        $message = $error->text
            ?? ($error->messageKey === null ? null : KernelMessages::english($error->messageKey, $error->details))
            ?? MessageTemplate::describe($error->messageKey ?? $error->code, $error->details);

        return new self($message, $error->code, $previous, $error);
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
     *
     * 以 fromError() 创建的交回原来的错误（保留消息键与参数，接收方可再翻译）。
     */
    #[\Override]
    public function toError(): Error
    {
        return $this->error ?? Error::literal(
            ErrorCode::isValid($this->errorCode) ? $this->errorCode : ErrorCode::INVALID_ARGUMENT,
            $this->getMessage(),
        );
    }
}
