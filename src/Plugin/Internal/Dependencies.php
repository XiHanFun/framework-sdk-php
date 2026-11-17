<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;

/**
 * 插件运行时的可选依赖（composer.json 的 suggest）：用到运行时之前核对，缺了就说清楚要装什么。
 *
 * 核心五块只依赖 PHP 核心与 ext-json；gRPC 服务端与客户端、HTTP/2 帧与协议消息只有插件运行时用。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Dependencies
{
    /** 要用到的类 → 提供它的包 */
    private const array REQUIRED = [
        'Revolt\EventLoop' => 'revolt/event-loop',
        'Amp\Future' => 'amphp/amp',
        'Amp\Socket\Socket' => 'amphp/socket',
        'Amp\Http\Http2\Http2Parser' => 'amphp/http',
        'Amp\Http\HPack' => 'amphp/hpack',
        'Google\Protobuf\Internal\Message' => 'google/protobuf',
    ];

    private function __construct()
    {
    }

    /**
     * 核对依赖都已安装。
     *
     * @throws ConfigurationException 缺依赖（PRIMITIVES_VALIDATION_001），消息列出要装的包
     */
    public static function check(): void
    {
        $missing = [];
        foreach (self::REQUIRED as $class => $package) {
            if (!class_exists($class) && !interface_exists($class)) {
                $missing[$package] = $package;
            }
        }
        if ($missing !== []) {
            throw new ConfigurationException(sprintf(
                'The out-of-process plugin runtime needs the optional packages %s: run composer require '
                . 'amphp/socket amphp/http google/protobuf.',
                implode(', ', array_map(static fn (string $package): string => "'" . $package . "'", $missing)),
            ), ErrorCode::INVALID_ARGUMENT);
        }
    }
}
