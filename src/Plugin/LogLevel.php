<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 插件日志的级别；枚举值是宿主认的写法（invocation.md §6）。
 */
enum LogLevel: string
{
    case Trace = 'trace';
    case Debug = 'debug';
    case Information = 'information';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /**
     * 返回级别的序：越大越严重。
     */
    public function severity(): int
    {
        return match ($this) {
            self::Trace => 0,
            self::Debug => 1,
            self::Information => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
        };
    }
}
