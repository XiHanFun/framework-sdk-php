<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use Revolt\EventLoop;

/**
 * 监视宿主保持打开的 stdin：读到结尾（宿主退出、崩溃或要求立即退出）即通知，插件不留成孤儿进程（handshake.md §1）。
 *
 * 宿主不往 stdin 写东西，读到的内容丢弃。Unix 上以非阻塞方式定时读。Windows 上 PHP 读非阻塞的管道时，管道开着却没有
 * 数据会一直等（PeekNamedPipe 的轮询），stream_select 也分不出管道是否可读，所以另起一个只阻塞读 stdin 的 PHP 子进程，
 * 定时看它是否已退出；本进程退出时终止它，它不比插件活得长。stdin 是套接字（测试里）时各平台都按非阻塞读。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class StdinWatcher
{
    /** 检查的间隔（秒） */
    private const float POLL = 0.2;

    private ?string $timer = null;

    /** @var resource|null Windows 上读 stdin 的子进程 */
    private mixed $child = null;

    /**
     * @param resource $stdin
     * @param \Closure(): void $onClosed stdin 关闭时调用一次
     */
    private function __construct(private readonly mixed $stdin, private readonly \Closure $onClosed)
    {
    }

    /**
     * 开始监视；stdin 既不能非阻塞地读、也起不了子进程时返回 null（控制台里手工运行）。
     *
     * @param resource $stdin
     * @param \Closure(): void $onClosed
     */
    public static function start(mixed $stdin, \Closure $onClosed): ?self
    {
        $watcher = new self($stdin, $onClosed);
        $type = stream_get_meta_data($stdin)['stream_type'];
        $socket = str_contains(strtolower($type), 'socket');
        if (PHP_OS_FAMILY === 'Windows' && !$socket) {
            return $watcher->startChild() ? $watcher : null;
        }

        return $watcher->startPolling() ? $watcher : null;
    }

    /**
     * 停止监视；Windows 上终止读 stdin 的子进程。
     */
    public function stop(): void
    {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        if (is_resource($this->child)) {
            @proc_terminate($this->child);
            @proc_close($this->child);
        }
        $this->child = null;
    }

    /**
     * 以非阻塞方式定时读，读到结尾即通知。
     */
    private function startPolling(): bool
    {
        if (!stream_set_blocking($this->stdin, false)) {
            return false;
        }
        $this->timer = EventLoop::unreference(EventLoop::repeat(self::POLL, function (): void {
            while (($chunk = @fread($this->stdin, 65536)) !== false && $chunk !== '') {
                // 丢弃：宿主不经 stdin 传任何东西
            }
            if (feof($this->stdin)) {
                $this->closed();
            }
        }));

        return true;
    }

    /**
     * Windows：起子进程阻塞读 stdin，定时看它是否退出。
     */
    private function startChild(): bool
    {
        $null = 'NUL';
        $child = @proc_open(
            [PHP_BINARY, '-n', '-r', 'while (!feof(STDIN) && fread(STDIN, 65536) !== false) {}'],
            [0 => $this->stdin, 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($child)) {
            return false;
        }
        $this->child = $child;
        // 本进程以任何方式退出（含处理函数里的 exit）都带走子进程，它手里有继承来的 stdout 与 stderr
        register_shutdown_function(function (): void {
            $this->stop();
        });
        $this->timer = EventLoop::unreference(EventLoop::repeat(self::POLL, function (): void {
            $status = is_resource($this->child) ? proc_get_status($this->child) : null;
            if ($status === null || !$status['running']) {
                $this->closed();
            }
        }));

        return true;
    }

    /**
     * stdin 已关闭：停止监视并通知一次。
     */
    private function closed(): void
    {
        $this->stop();
        ($this->onClosed)();
    }
}
