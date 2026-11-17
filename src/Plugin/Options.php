<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Operation\Registry;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * Runtime::serve() 的输入。
 *
 * 清单是装配的唯一事实源：握手原样发送清单原文，宿主逐字段与插件目录里的文件比对（handshake.md §3），所以通常读工作
 * 目录（宿主把它设为插件目录）里的 xihan.manifest.json。操作、流式操作与健康项都必须在清单里声明。
 */
final readonly class Options
{
    /** @var list<Subscription> */
    public array $subscriptions;

    /** @var list<Catalog> */
    public array $catalogs;

    /** @var list<StreamingOperation> */
    public array $streams;

    /** @var array<string, string>|null */
    public ?array $environment;

    /**
     * @param string $manifest 清单原文（xihan.manifest.json）
     * @param Registry $registry 插件服务的一元操作；调用经 Invoker 与 $pipeline 执行
     * @param PipelineBuilder|null $pipeline 插件内的管道；null 即只有调用器的内置步骤
     * @param list<Subscription> $subscriptions 事件订阅与处理器
     * @param list<Catalog> $catalogs 消息目录，握手时交给宿主；至少应含 en-US
     * @param Hooks $hooks 生命周期回调
     * @param list<StreamingOperation> $streams 流式操作
     * @param Network|null $network 监听的网络；null 即 Windows 用 tcp、其他系统用 unix
     * @param string|null $address 监听地址；null 即自动生成（unix 为新建的私有目录里的套接字，tcp 为 127.0.0.1 的随机端口）
     * @param float $stopGrace 宿主没有给宽限时（stdin 关闭、Stop 的 grace_seconds 为 0）等在途调用的秒数
     * @param LogLevel $logLevel 运行时日志的最低级别
     * @param resource|null $stdin 宿主保持打开的输入，关闭即退出；null 即 STDIN
     * @param resource|null $stdout 只写握手行；null 即 STDOUT
     * @param resource|null $stderr 日志；null 即 STDERR
     * @param array<array-key, mixed>|null $environment 环境变量（名字 → 值）；null 即进程的环境变量
     *
     * @throws InvalidArgumentException 列表的元素类型不对或宽限不是正数（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $manifest,
        public Registry $registry = new Registry(),
        public ?PipelineBuilder $pipeline = null,
        array $subscriptions = [],
        array $catalogs = [],
        public Hooks $hooks = new Hooks(),
        array $streams = [],
        public ?Network $network = null,
        public ?string $address = null,
        public float $stopGrace = 5.0,
        public LogLevel $logLevel = LogLevel::Information,
        public mixed $stdin = null,
        public mixed $stdout = null,
        public mixed $stderr = null,
        ?array $environment = null,
    ) {
        $this->subscriptions = self::listOf(Subscription::class, $subscriptions, 'subscriptions');
        $this->catalogs = self::listOf(Catalog::class, $catalogs, 'catalogs');
        $this->streams = self::listOf(StreamingOperation::class, $streams, 'streams');
        if (!is_finite($stopGrace) || $stopGrace <= 0.0) {
            throw new InvalidArgumentException('The stop grace must be a positive number of seconds.');
        }
        foreach (['stdin' => $stdin, 'stdout' => $stdout, 'stderr' => $stderr] as $name => $stream) {
            if ($stream !== null && !is_resource($stream)) {
                throw new InvalidArgumentException(sprintf("The option '%s' must be a stream resource.", $name));
            }
        }
        if ($environment !== null) {
            $checked = [];
            foreach ($environment as $name => $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        sprintf("The environment variable '%s' must be a string.", $name),
                    );
                }
                $checked[(string) $name] = $value;
            }
            $environment = $checked;
        }
        $this->environment = $environment;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<array-key, mixed> $items
     *
     * @return list<T>
     */
    private static function listOf(string $class, array $items, string $name): array
    {
        $checked = [];
        foreach ($items as $index => $item) {
            if ($index !== count($checked) || !$item instanceof $class) {
                throw new InvalidArgumentException(sprintf("The option '%s' must be a list of %s.", $name, $class));
            }
            $checked[] = $item;
        }

        return $checked;
    }
}
