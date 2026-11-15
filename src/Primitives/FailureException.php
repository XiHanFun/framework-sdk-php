<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 强行从失败的 Result 或无值的 Maybe 取值时抛出的异常。
 *
 * 错误码与文案取自失败原因；`toError()` 原样交回那个 Error（保留消息键与参数）。
 */
final class FailureException extends \RuntimeException implements FrameworkException
{
    /**
     * 用失败原因创建异常。
     */
    public function __construct(private readonly Error $error)
    {
        parent::__construct($error->code . ': ' . $error->message());
    }

    /**
     * 返回失败原因。
     */
    public function error(): Error
    {
        return $this->error;
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return $this->error->code;
    }

    /**
     * {@inheritDoc}
     */
    public function toError(): Error
    {
        return $this->error;
    }
}
