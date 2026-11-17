<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 订阅方式（RFC-0009「订阅方式」）：宿主按它向消息代理订阅主题，投递时随 HandleEvent 带回（invocation.md §4）。
 */
enum SubscriptionMode: string
{
    /** 竞争消费：订阅了主题的宿主实例里只有一个把消息交给插件；声明与投递里的空都同它。 */
    case Competing = 'competing';

    /** 广播：每个在线的宿主实例都把消息交给自己的插件实例，尽力而为、不进死信。 */
    case Broadcast = 'broadcast';

    /**
     * 按线上的写法解析：空串是竞争消费，不认识的写法为 null。
     */
    public static function fromWire(string $mode): ?self
    {
        return $mode === '' ? self::Competing : self::tryFrom($mode);
    }
}
