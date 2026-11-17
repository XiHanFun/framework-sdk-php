<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 插件作者接入生命周期的回调（lifecycle.md §7）；都可以不给。
 *
 * 返回的失败原样作为 Status.error 交给宿主。抛出的异常连同消息只写进 stderr 日志，交给宿主的是 PLUGINS_UNEXPECTED_001
 * （plugins.process.failed，`phase` 为 configure、start、stop 或 health，`reason` 是异常的简单类名，不带命名空间与消息，
 * lifecycle.md §7.6）；返回的不是 Result（或健康回调不是 Health）按抛出 TypeError 处理。
 */
final readonly class Hooks
{
    /**
     * @param (\Closure(Configuration): Result<Unit>)|null $configure Configure 时调用；配置节不是 JSON 对象时不调用，失败即
     *     插件 Failed
     * @param (\Closure(): Result<Unit>)|null $start Start 时调用；成功后（有健康回调时先求一次健康）插件进入 Ready、Degraded
     *     或 Failed
     * @param (\Closure(string, float): Result<Unit>)|null $stop 停止时调用，进程里至多一次（原因、剩余的宽限秒数）：只在启动
     *     回调成功过时调用，此时在途调用已经结束或已被取消；stdin 关闭与 SIGTERM 时原因为 shutdown，一共只有 3 秒
     * @param (\Closure(): Health)|null $health 给出当前健康：Start 时与可服务期间的每次 Health 调用，WatchHealth 另每 10 秒
     *     调用一次；不给时按运行时的状态上报，没有健康项
     */
    public function __construct(
        public ?\Closure $configure = null,
        public ?\Closure $start = null,
        public ?\Closure $stop = null,
        public ?\Closure $health = null,
    ) {
    }
}
