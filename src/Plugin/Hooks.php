<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 插件作者接入生命周期的回调（lifecycle.md）；都可以不给。
 *
 * 返回的失败原样作为 Status.error 交给宿主；抛出的异常记进日志，交给宿主的是 PLUGINS_UNEXPECTED_001
 * （plugins.process.failed，参数 phase 为 configure、start 或 stop），不带异常的消息。
 */
final readonly class Hooks
{
    /**
     * @param (\Closure(Configuration): Result<Unit>)|null $configure Configure 时调用；失败即插件 Failed
     * @param (\Closure(): Result<Unit>)|null $start Start 时调用；成功后插件接受调用
     * @param (\Closure(string, float): Result<Unit>)|null $stop Stop 时调用（原因、宽限秒数）：完成在途工作并释放资源
     * @param (\Closure(): Health)|null $health 给出当前健康；不给时按运行时的状态上报，没有健康项
     */
    public function __construct(
        public ?\Closure $configure = null,
        public ?\Closure $start = null,
        public ?\Closure $stop = null,
        public ?\Closure $health = null,
    ) {
    }
}
