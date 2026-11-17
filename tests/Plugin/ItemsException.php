<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

/**
 * 测试插件的回调抛出的异常：带命名空间，核对交给宿主的 reason 只是简单类名（lifecycle.md §7.6）。
 */
final class ItemsException extends \RuntimeException
{
}
