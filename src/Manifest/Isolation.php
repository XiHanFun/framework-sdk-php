<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

/**
 * 插件隔离级别（RFC-0002 `isolation`）；只有插件声明，且必须声明。
 */
enum Isolation: string
{
    /** 进程内加载。 */
    case InProcess = 'in-process';

    /** Wasm 沙箱；只有它能贡献中间件（RFC-0017「范围」）。 */
    case Wasm = 'wasm';

    /** 独立进程，经 xihan.plugin.v1 协议通信。 */
    case OutOfProcess = 'out-of-process';
}
