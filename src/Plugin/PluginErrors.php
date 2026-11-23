<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\LifecycleState;

/**
 * 插件运行时产生的错误（invocation.md §8）：码、键与参数以 conformance/vectors/plugin/plugin-messages.json 为准，
 * 参数就是错误的 details，不另加成员；`id` 一律是插件的清单 ID。
 */
final class PluginErrors
{
    /** 插件不在可服务状态；回调连不上宿主或没有可用的回调端点。 */
    public const string UNAVAILABLE = 'PLUGINS_UNAVAILABLE_001';

    /** 插件回调被拒：会话令牌无效、所需权限未在清单声明或未被授予、租户越界。 */
    public const string CALLBACK_DENIED = 'PLUGINS_DENIED_002';

    /** 回调抛出、配置节不是对象、对方写来的错误不合法、没有对应的 gRPC 状态。 */
    public const string UNEXPECTED = 'PLUGINS_UNEXPECTED_001';

    /** 投递找不到订阅：与内核的操作未登记同码（KERNEL_NOTFOUND_001）。 */
    public const string UNSUBSCRIBED = KernelErrors::UNKNOWN_OPERATION;

    /** 没有可用的回调端点时 plugins.host.unavailable 的 reason（handshake.md §4）。 */
    public const string REASON_NO_ENDPOINT = 'no supported callback endpoint';

    /** 配置节不是 JSON 对象时 plugins.process.failed 的 reason（lifecycle.md §7.2）。 */
    public const string REASON_NOT_AN_OBJECT = 'the configuration is not a JSON object';

    /** 宿主写来的 Status 为失败却没有错误时 plugins.process.failed 的 reason（invocation.md §3）。 */
    public const string REASON_NO_ERROR = 'the host reported a failure without an error';

    private function __construct()
    {
    }

    /**
     * 插件不在可服务状态（Ready、Degraded）时到达的调用与投递（lifecycle.md §7.3）；`state` 写状态名。
     */
    public static function unavailable(string $id, LifecycleState $state): Error
    {
        return Error::unavailable(self::UNAVAILABLE, PluginMessages::UNAVAILABLE, [
            'id' => $id,
            'state' => $state->value,
        ]);
    }

    /**
     * 插件一侧的失败：$phase 是小写的阶段名（configure、start、stop、health、invoke、publish）或回调的操作 ID，
     * $reason 是不本地化的英文诊断文本（invocation.md §8）。
     */
    public static function failed(string $id, string $phase, string $reason): Error
    {
        return Error::unexpected(self::UNEXPECTED, PluginMessages::FAILED, [
            'id' => $id,
            'phase' => $phase,
            'reason' => $reason,
        ]);
    }

    /**
     * 投递找不到以这种方式订阅、类型也匹配的处理器（invocation.md §4）；$mode 是投递带来的写法，空写成 competing。
     */
    public static function unsubscribed(string $id, string $topic, string $type, string $mode): Error
    {
        return Error::notFound(self::UNSUBSCRIBED, PluginMessages::EVENT_UNSUBSCRIBED, [
            'id' => $id,
            'topic' => $topic,
            'type' => $type,
            'mode' => $mode === '' ? SubscriptionMode::Competing->value : $mode,
        ]);
    }

    /**
     * 回调连不上宿主（Unavailable、调用方没有取消的 Cancelled、连接失败），或没有可用的回调端点（invocation.md §5）。
     */
    public static function hostUnavailable(string $id, string $reason): Error
    {
        return Error::unavailable(self::UNAVAILABLE, PluginMessages::HOST_UNAVAILABLE, [
            'id' => $id,
            'reason' => $reason,
        ]);
    }

    /**
     * 宿主以 Unauthenticated 拒绝了回调：会话令牌缺失或无效（handshake.md §4）。
     */
    public static function sessionInvalid(): Error
    {
        return Error::denied(self::CALLBACK_DENIED, PluginMessages::SESSION_INVALID);
    }

    /**
     * 回调所需的权限未在清单声明或未被授予（invocation.md §5）。
     */
    public static function callbackDenied(string $id, string $permission): Error
    {
        return Error::denied(self::CALLBACK_DENIED, PluginMessages::CALLBACK_DENIED, [
            'id' => $id,
            'permission' => $permission,
        ]);
    }

    /**
     * 回调上下文的租户不是插件此刻正在处理的调用或投递的租户（invocation.md §5，ADR-0195）。
     */
    public static function tenantDenied(string $id, string $tenant): Error
    {
        return Error::denied(self::CALLBACK_DENIED, PluginMessages::CALLBACK_TENANT_DENIED, [
            'id' => $id,
            'tenant' => $tenant,
        ]);
    }
}
