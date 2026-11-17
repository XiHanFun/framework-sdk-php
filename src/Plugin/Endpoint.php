<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * 一个本机 gRPC 端点：网络类型与地址（handshake.md §2、§4）。
 *
 * 文本写法 `<network> <address>` 用于环境变量 XIHAN_PLUGIN_HOST_ADDRESS；握手行在前面加 `xihan-plugin/1`。
 * tcp 的地址只能是回环地址 `127.0.0.1:<port>` 或 `[::1]:<port>`，主机名不算。
 */
final readonly class Endpoint implements \Stringable
{
    /** 握手行的格式版本：只在行格式变化时递增，与协议版本无关。 */
    public const string HANDSHAKE_LINE_VERSION = 'xihan-plugin/1';

    /** `<network> <address>` 的文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^(unix|tcp|npipe) (\S+)$/D';

    /** 回环的 TCP 地址 */
    private const string LOOPBACK = '/^(?:127\.0\.0\.1|\[::1\]):[0-9]+$/D';

    /**
     * @throws InvalidArgumentException 地址为空或含空白，或 tcp 的地址不是回环地址（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public Network $network, public string $address)
    {
        if ($address === '' || preg_match('/\s/', $address) === 1) {
            throw new InvalidArgumentException('An endpoint address must be non-empty and free of whitespace.');
        }
        if ($network === Network::Tcp && preg_match(self::LOOPBACK, $address) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "A tcp endpoint must use a loopback address (127.0.0.1:<port> or [::1]:<port>), got '%s'.",
                $address,
            ));
        }
    }

    /**
     * 解析 `<network> <address>`；不合法时为 null。
     */
    public static function tryParse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $match) !== 1) {
            return null;
        }
        $network = Network::from($match[1]);
        if ($network === Network::Tcp && preg_match(self::LOOPBACK, $match[2]) !== 1) {
            return null;
        }

        return new self($network, $match[2]);
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
