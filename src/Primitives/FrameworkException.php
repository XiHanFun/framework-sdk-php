<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 带错误码的框架异常（RFC-0001 FrameworkException）。
 *
 * 只用于配置错误与编程错误，消息是英文、不本地化；可恢复的业务失败用 Error 与 Result，不抛异常。
 * 具体类分别继承 SPL 的 InvalidArgumentException、LogicException 与 RuntimeException，
 * 调用方既可以按 SPL 类型捕获，也可以按本接口取错误码。
 */
interface FrameworkException extends \Throwable
{
    /**
     * 返回错误码 `<领域大写>_<KIND>_<NNN>`。
     */
    public function errorCode(): string;

    /**
     * 转成可上线的 Error：已解析的英文文案，没有消息键。
     */
    public function toError(): Error;
}
