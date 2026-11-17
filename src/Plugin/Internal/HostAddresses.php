<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Network;

/**
 * 宿主的回调端点列表 XIHAN_PLUGIN_HOST_ADDRESS（handshake.md §4，向量 conformance/vectors/plugin/host-addresses.json）。
 *
 * 一个或多个 `<network> <address>`，按宿主的偏好排序、以 `;` 分隔；每项同握手行的端点、地址另不得含 `;`，任何一项不合法
 * 整个值就不合法。插件按顺序取第一个自己能连的 network，之后不换。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class HostAddresses
{
    private function __construct()
    {
    }

    /**
     * 解析整个值；空串或任何一项不合法时为 null。
     *
     * @return list<Endpoint>|null
     */
    public static function parse(string $value): ?array
    {
        $endpoints = [];
        // 按 ; 切开后地址里自然没有 ;；开头、结尾或连续的 ; 留下空项，空项不是合法的端点
        foreach (explode(';', $value) as $part) {
            $endpoint = Endpoint::tryParse($part);
            if ($endpoint === null) {
                return null;
            }
            $endpoints[] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * 选回调端点：值合法时按顺序取第一个 network 在 $supported 里的项；变量缺失（null）、不合法或没有可用的项时为 null。
     *
     * @param list<Network> $supported
     */
    public static function select(?string $value, array $supported): ?Endpoint
    {
        foreach (self::parse($value ?? '') ?? [] as $endpoint) {
            if (in_array($endpoint->network, $supported, true)) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * 本 SDK 的 gRPC 客户端能连的 network：tcp 处处可连；unix 只在 Windows 之外、且 PHP 带 unix 套接字传输时可连；
     * 命名管道 PHP 连不了（PHP 不能以非阻塞方式读写命名管道）。
     *
     * @return list<Network>
     */
    public static function supported(): array
    {
        $transports = stream_get_transports();
        $supported = [];
        if (PHP_OS_FAMILY !== 'Windows' && in_array('unix', $transports, true)) {
            $supported[] = Network::Unix;
        }
        $supported[] = Network::Tcp;

        return $supported;
    }
}
