<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Plugin\Runtime;

/**
 * 协议版本的协商（handshake.md §3 第 1 条，向量 conformance/vectors/plugin/protocol-versions.json）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Versions
{
    /** 本 SDK 支持的协议版本 */
    public const array SUPPORTED = [Runtime::PROTOCOL_VERSION];

    private function __construct()
    {
    }

    /**
     * 选宿主列表里最高的、插件也支持的版本；没有交集时为 null。
     *
     * @param iterable<mixed> $host
     * @param list<int> $plugin
     */
    public static function negotiate(iterable $host, array $plugin = self::SUPPORTED): ?int
    {
        $selected = null;
        foreach ($host as $candidate) {
            $higher = $selected === null || $candidate > $selected;
            if (is_int($candidate) && in_array($candidate, $plugin, true) && $higher) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    /**
     * 解析环境变量 XIHAN_PLUGIN_PROTOCOL_VERSIONS：逗号分隔的非负整数；写法不合法时为 null。
     *
     * @return list<int>|null
     */
    public static function parse(string $text): ?array
    {
        $versions = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part);
            if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $part) !== 1 || (int) $part > 4294967295) {
                return null;
            }
            $versions[] = (int) $part;
        }

        return $versions;
    }
}
