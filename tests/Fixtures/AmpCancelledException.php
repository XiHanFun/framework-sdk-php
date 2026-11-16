<?php

declare(strict_types=1);

namespace Amp;

/**
 * 测试用：代替 amphp 的取消异常。SDK 不依赖 amphp，只按类名认出它；CancellationTest 在没有 amphp 时才加载这个文件。
 */
class CancelledException extends \Exception
{
}
