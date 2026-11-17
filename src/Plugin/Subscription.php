<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 一个事件订阅与它的处理器（handshake.md §3 第 5 条、invocation.md §4）。
 *
 * 投递按（主题，方式）找处理器，再看类型：只交给以这种方式声明、类型为空或等于信封 `type` 的订阅。同一（主题，类型）
 * 对只能声明一次，不能以两种方式各声明一次。投递至少一次，处理器必须幂等（可用信封 `id` 或幂等键去重）。
 */
final readonly class Subscription
{
    /** 主题文法（RFC-0009）；D 修饰符让 $ 只匹配串尾 */
    private const string TOPIC = '/^[a-z0-9][a-z0-9._-]{0,127}$/D';

    /** @var \Closure(Context, Envelope, int): Result<Unit> */
    public \Closure $handler;

    /**
     * @param string $topic 主题
     * @param \Closure(Context, Envelope, int): Result<Unit> $handler 处理器：上下文、信封与投递次数（从 1 起）；
     *     返回失败由宿主的消息代理按重试与死信策略处理
     * @param string $messageType 只投递这个 CloudEvents `type`；空为主题下全部
     * @param SubscriptionMode|null $mode 订阅方式；null 即不写，宿主按竞争消费处理
     *
     * @throws InvalidArgumentException 主题不合文法（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $topic,
        \Closure $handler,
        public string $messageType = '',
        public ?SubscriptionMode $mode = null,
    ) {
        if (preg_match(self::TOPIC, $topic) !== 1) {
            throw new InvalidArgumentException(
                sprintf("The topic '%s' must match ^[a-z0-9][a-z0-9._-]{0,127}$.", $topic),
            );
        }
        $this->handler = $handler;
    }

    /**
     * 返回生效的订阅方式：没写即竞争消费。
     */
    public function effectiveMode(): SubscriptionMode
    {
        return $this->mode ?? SubscriptionMode::Competing;
    }

    /**
     * 判断这个订阅是否接收以 $mode 方式投递的这条消息。
     */
    public function accepts(Envelope $envelope, SubscriptionMode $mode): bool
    {
        // 方式必须相同：同一主题的不同类型可以各用一种方式，一条消息以两种方式各到一次时交给各自的处理器
        return $this->topic === $envelope->topic
            && $this->effectiveMode() === $mode
            && ($this->messageType === '' || $this->messageType === $envelope->type);
    }
}
