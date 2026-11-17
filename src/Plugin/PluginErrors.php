<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Operation\OperationErrors;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\LifecycleState;

/**
 * 插件运行时产生的错误：码取自 RFC-0017「错误码」与 conventions/error-codes.md，键见 PluginMessages。
 */
final class PluginErrors
{
    /** 插件未就绪、正在停止或不可达；回调时宿主不可达。 */
    public const string UNAVAILABLE = 'PLUGINS_UNAVAILABLE_001';

    /** 插件回调被拒：会话令牌无效、所需权限未在清单声明或未被授予、租户越界。 */
    public const string CALLBACK_DENIED = 'PLUGINS_DENIED_002';

    /** 配置、启动、停止或回调时的未预期失败。 */
    public const string UNEXPECTED = 'PLUGINS_UNEXPECTED_001';

    private function __construct()
    {
    }

    /**
     * 插件不在可服务的状态：调用与投递在 Start 之前或停止时到达（lifecycle.md §2、§5）。
     */
    public static function unavailable(string $id, LifecycleState $state): Error
    {
        return Error::unavailable(self::UNAVAILABLE, PluginMessages::UNAVAILABLE, [
            'id' => $id,
            'state' => $state->value,
        ]);
    }

    /**
     * 某一步（configure、start、stop、callback）失败；$code 缺省为 PLUGINS_UNEXPECTED_001。
     */
    public static function failed(string $id, string $phase, string $reason, string $code = self::UNEXPECTED): Error
    {
        return Error::of($code, PluginMessages::FAILED, ['id' => $id, 'phase' => $phase, 'reason' => $reason]);
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
     * 回调或发布带的租户不是宿主此刻交给插件处理的调用的租户（invocation.md §5，ADR-0195）。
     */
    public static function tenantDenied(string $id, string $tenant): Error
    {
        return Error::denied(self::CALLBACK_DENIED, PluginMessages::CALLBACK_TENANT_DENIED, [
            'id' => $id,
            'tenant' => $tenant,
        ]);
    }

    /**
     * 投递的消息没有以这种方式订阅、类型也匹配的处理器（invocation.md §4）；$mode 是投递带来的写法，空写成 competing。
     */
    public static function noSubscription(string $topic, string $type, string $mode): Error
    {
        return Error::notFound(OperationErrors::NOT_FOUND, PluginMessages::NO_SUBSCRIPTION, [
            'topic' => $topic,
            'type' => $type,
            'mode' => $mode === '' ? SubscriptionMode::Competing->value : $mode,
        ]);
    }
}
