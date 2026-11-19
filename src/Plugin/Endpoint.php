<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Internal\Whitespace;

/**
 * 一个本机 gRPC 端点：网络类型与地址（handshake.md §2、§4）。
 *
 * 文本写法 `<network> <address>` 用于环境变量 XIHAN_PLUGIN_HOST_ADDRESS 的每一项；握手行在前面加 `xihan-plugin/1`。
 * 地址是一个或多个规范所说的空白（RFC-0001「空白字符」）之外的字符（非 ASCII 字母与 `;` 都可以），tcp 的地址只能是回环地址加端口：
 * `127.0.0.0/8` 的点分十进制（四段、每段 0–255、不带前导零）或 `[::1]`，端口 1–65535、不带正负号与前导零。
 */
final readonly class Endpoint implements \Stringable
{
    /** 握手行的格式版本：只在行格式变化时递增，与协议版本无关。 */
    public const string HANDSHAKE_LINE_VERSION = 'xihan-plugin/1';

    /** 回环 IPv4 的后三段：0–255、不带前导零 */
    private const string OCTET = '(?:0|[1-9][0-9]?|1[0-9]{2}|2[0-4][0-9]|25[0-5])';

    /** 端口：1–65535、不带正负号与前导零 */
    private const string PORT = '(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]'
        . '|6553[0-5])';

    /**
     * @throws InvalidArgumentException 地址为空、含空白或不是合法的 UTF-8，或 tcp 的地址不是回环地址加端口
     *     （PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public Network $network, public string $address)
    {
        if (!self::isAddress($address)) {
            throw new InvalidArgumentException(
                'An endpoint address must be non-empty, valid UTF-8 and free of whitespace.',
            );
        }
        if ($network === Network::Tcp && !self::isLoopback($address)) {
            throw new InvalidArgumentException(sprintf(
                "A tcp endpoint must be a loopback address with a port (127.x.y.z:<port> or [::1]:<port>), got '%s'.",
                $address,
            ));
        }
    }

    /**
     * 解析 `<network> <address>`；不合法时为 null。network 与地址之间恰好一个空格。
     */
    public static function tryParse(string $text): ?self
    {
        $separator = strpos($text, ' ');
        if ($separator === false) {
            return null;
        }
        $network = Network::tryFrom(substr($text, 0, $separator));
        $address = substr($text, $separator + 1);
        if ($network === null || !self::isAddress($address)) {
            return null;
        }
        if ($network === Network::Tcp && !self::isLoopback($address)) {
            return null;
        }

        return new self($network, $address);
    }

    /**
     * 解析握手行 `xihan-plugin/1 <network> <address>`（不含行尾）；不合法时为 null。
     */
    public static function tryParseHandshakeLine(string $line): ?self
    {
        $prefix = self::HANDSHAKE_LINE_VERSION . ' ';

        return str_starts_with($line, $prefix) ? self::tryParse(substr($line, strlen($prefix))) : null;
    }

    /**
     * 判断地址能否写进握手行与端点列表：非空、合法的 UTF-8、不含规范所说的空白。
     */
    public static function isAddress(string $address): bool
    {
        // preg_match 遇到不合法的 UTF-8 返回 false：宿主按 UTF-8 解码时会把它换成 U+FFFD、连不上，所以一律不合法
        return $address !== '' && preg_match(Whitespace::PATTERN, $address) === 0;
    }

    /**
     * 判断 tcp 端点的地址是不是回环地址加端口；主机名与操作系统也接受的其他写法（`127.1`、`0177.0.0.1`）都不算。
     */
    public static function isLoopback(string $address): bool
    {
        $octet = self::OCTET;

        return preg_match(
            '/^(?:127\.' . $octet . '\.' . $octet . '\.' . $octet . '|\[::1\]):' . self::PORT . '$/D',
            $address,
        ) === 1;
    }

    /**
     * 返回握手行（不含行尾）。
     */
    public function handshakeLine(): string
    {
        return self::HANDSHAKE_LINE_VERSION . ' ' . $this;
    }

    /**
     * 返回 amphp/socket 的地址：`unix:///path` 或 `tcp://host:port`；命名管道没有这种写法，为 null。
     */
    public function socketUri(): ?string
    {
        return match ($this->network) {
            Network::Unix => 'unix://' . $this->address,
            Network::Tcp => 'tcp://' . $this->address,
            Network::NamedPipe => null,
        };
    }

    /**
     * 返回 `<network> <address>`。
     */
    public function __toString(): string
    {
        return $this->network->value . ' ' . $this->address;
    }
}
