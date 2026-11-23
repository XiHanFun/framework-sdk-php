<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\KernelErrors;

/**
 * 取消的原因（RFC-0003 规则 1）。
 */
enum CancellationReason
{
    /** 调用方在截止时间到达之前取消：KERNEL_CANCELLED_001。 */
    case Cancelled;

    /** 到达截止时间：KERNEL_TIMEOUT_001；截止时间已到时调用方同时取消也算超时。 */
    case DeadlineExceeded;

    /**
     * 翻译成原语层错误：超时为 KernelErrors::deadlineExceeded()，取消为 KernelErrors::cancelled()。
     */
    public function toError(): Error
    {
        return match ($this) {
            self::DeadlineExceeded => KernelErrors::deadlineExceeded(),
            self::Cancelled => KernelErrors::cancelled(),
        };
    }
}
