<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\SocketException;
use Amp\Socket\UnixAddress;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\ErrorCode;

use function Amp\Socket\listen;

/**
 * 插件的监听端点（handshake.md §4）：只接受本机连接。
 *
 * Unix 域套接字放在本进程新建的私有目录里：目录名随机、权限 0700，已经存在就换一个名字（可能是别人抢先建的、指向
 * 别处的链接），套接字文件 0600，停止后删除套接字与目录。Windows 上 PHP 不能以非阻塞方式服务命名管道，用回环 TCP，
 * 同机的其他用户连得上，隔离只靠会话令牌。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Listener
{
    /** 套接字文件名 */
    private const string SOCKET_NAME = 'plugin.sock';

    /** Unix 域套接字路径的长度上限：Linux 是 108 字节、macOS 是 104，留出余量 */
    private const int MAX_SOCKET_PATH = 100;

    private function __construct(
        public readonly ResourceServerSocket $socket,
        public readonly Endpoint $endpoint,
        private readonly ?string $directory,
    ) {
    }

    /**
     * 打开监听端点：网络为 null 时 Windows 用回环 TCP、其他系统用 Unix 域套接字；地址为 null 时自动生成。
     *
     * @throws ConfigurationException 不支持的网络（npipe）、地址不是回环地址，或监听失败
     */
    public static function open(?Network $network, ?string $address): self
    {
        $network ??= PHP_OS_FAMILY === 'Windows' ? Network::Tcp : Network::Unix;
        try {
            return match ($network) {
                Network::Unix => self::unix($address),
                Network::Tcp => self::tcp($address ?? '127.0.0.1:0'),
                Network::NamedPipe => throw self::failure(
                    'The PHP plugin runtime cannot serve named pipes; use tcp or unix.',
                ),
            };
        } catch (SocketException $exception) {
            throw self::failure(sprintf('The plugin cannot listen: %s', $exception->getMessage()), $exception);
        }
    }

    /**
     * 停止监听，删除私有目录里的套接字与目录。
     */
    public function close(): void
    {
        $this->socket->close();
        if ($this->directory !== null) {
            @unlink($this->directory . '/' . self::SOCKET_NAME);
            @rmdir($this->directory);
        }
    }

    /**
     * @throws ConfigurationException
     * @throws SocketException
     */
    private static function unix(?string $address): self
    {
        $directory = null;
        if ($address === null) {
            $directory = self::privateDirectory();
            $address = $directory . '/' . self::SOCKET_NAME;
            // 进程以 exit() 结束时（如处理函数里）不经过 close()：退出时同样删掉套接字与目录
            register_shutdown_function(static function () use ($directory): void {
                @unlink($directory . '/' . self::SOCKET_NAME);
                @rmdir($directory);
            });
        }
        // 套接字以 0600 创建：先收紧 umask，绑定之后再 chmod，中间没有别人可用的窗口
        $previous = umask(0177);
        try {
            $socket = listen(new UnixAddress($address));
        } finally {
            umask($previous);
        }
        @chmod($address, 0600);

        return new self($socket, new Endpoint(Network::Unix, $address), $directory);
    }

    /**
     * @throws ConfigurationException
     * @throws SocketException
     */
    private static function tcp(string $address): self
    {
        $port = preg_match('/^(127\.0\.0\.1|\[::1\]):([0-9]{1,5})$/D', $address, $match) === 1 ? (int) $match[2] : -1;
        if ($port < 0 || $port > 65535) {
            throw self::failure(sprintf("A tcp endpoint must listen on a loopback address, got '%s'.", $address));
        }
        $socket = listen(new InternetAddress(trim($match[1], '[]'), $port));
        $bound = $socket->getAddress();
        $port = $bound instanceof InternetAddress ? $bound->getPort() : $port;

        return new self($socket, new Endpoint(Network::Tcp, $match[1] . ':' . $port), null);
    }

    /**
     * 新建只有本用户能进的目录：名字随机，已存在即换一个；建好后核对它是本用户的目录而不是链接。
     *
     * @throws ConfigurationException
     */
    private static function privateDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        if (strlen($base) + 40 > self::MAX_SOCKET_PATH) {
            $base = '/tmp';
        }
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $directory = $base . '/xihan-plugin-' . bin2hex(random_bytes(8));
            // mkdir 遇到已存在的路径（含链接）失败，不会复用别人建的目录
            if (!@mkdir($directory, 0700)) {
                continue;
            }
            @chmod($directory, 0700);
            $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
            if (is_link($directory) || !is_dir($directory) || ($owner !== null && fileowner($directory) !== $owner)) {
                throw self::failure(sprintf("The private directory '%s' is not owned by this process.", $directory));
            }

            return $directory;
        }

        throw self::failure(sprintf("Cannot create a private directory under '%s'.", $base));
    }

    private static function failure(string $message, ?\Throwable $previous = null): ConfigurationException
    {
        return new ConfigurationException($message, ErrorCode::INVALID_ARGUMENT, $previous);
    }
}
