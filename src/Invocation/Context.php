<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\KernelMessages;

/**
 * 一次调用的上下文（RFC-0003）：取消与截止时间、W3C 追踪、Baggage 与请求作用域对象袋。
 *
 * 不可变，按派生传递：`with*` 返回新上下文，派生上下文共享取消令牌、截止时间、时钟与对象袋，Baggage 按值复制。
 * 不绑定任何协议，载体映射由协议适配器与插件运行时实现。中间件与处理器的签名是同步的，可以运行在 Fiber 里。
 */
final class Context
{
    /** 调用超过截止时间。 */
    public const string ERROR_CODE_DEADLINE_EXCEEDED = 'KERNEL_TIMEOUT_001';

    /** 调用被取消。 */
    public const string ERROR_CODE_CANCELLED = 'KERNEL_CANCELLED_001';

    /** 超时的消息键。 */
    public const string MESSAGE_DEADLINE_EXCEEDED = KernelMessages::DEADLINE_EXCEEDED;

    /** 取消的消息键。 */
    public const string MESSAGE_CANCELLED = KernelMessages::CANCELLED;

    /** 相对超时的上限（秒，约 31.7 年）：再大就超出微秒整数的可靠范围 */
    private const float MAX_TIMEOUT = 1_000_000_000.0;

    /**
     * @param array<string, string> $baggage
     */
    private function __construct(
        private readonly Cancellation $cancellation,
        private readonly ?\DateTimeImmutable $deadline,
        private readonly Clock $clock,
        private readonly TraceParent $trace,
        private readonly array $baggage,
        private readonly Items $items,
    ) {
    }

    /**
     * 创建上下文。
     *
     * 截止时间与相对超时二选一；traceparent 与单独的追踪 ID / Span ID 二选一，都没有时生成新的追踪（采样）。
     * 有截止时间时取消令牌是 DeadlineCancellation：外部取消或到达截止时间都会触发（RFC-0003 规则 1）。
     *
     * @param Cancellation|null $cancellation 外部取消的令牌；null 即不会被外部取消
     * @param \DateTimeImmutable|null $deadline 绝对截止时间，按 UTC 保存
     * @param float|null $timeout 相对超时（秒），从时钟的当前时刻算起
     * @param string|null $traceParent W3C traceparent；不合法的当作没有给出、生成新的追踪（RFC-0003 规则 7）
     * @param string|null $traceId 追踪 ID；没有时生成
     * @param string|null $spanId Span ID；没有时生成
     * @param array<string, string> $baggage Baggage；键必须符合 `xihan.<name>`。已登记键的值不合约束
     *                                      （BaggageKeys::isAcceptableValue）的成员丢弃，xihan.idempotency-key 除外
     * @param Clock|null $clock 判定截止时间的时钟；null 即系统时钟
     *
     * @throws InvalidArgumentException 选项冲突、超时不合法、追踪 ID 或 Span ID 不合法、Baggage 键不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function create(
        ?Cancellation $cancellation = null,
        ?\DateTimeImmutable $deadline = null,
        ?float $timeout = null,
        ?string $traceParent = null,
        ?string $traceId = null,
        ?string $spanId = null,
        array $baggage = [],
        ?Clock $clock = null,
    ): self {
        $clock ??= new SystemClock();
        $cancellation ??= new NullCancellation();
        if ($deadline !== null && $timeout !== null) {
            throw new InvalidArgumentException('A context takes either a deadline or a timeout, not both.');
        }
        if ($timeout !== null) {
            if (!is_finite($timeout) || $timeout < 0.0 || $timeout > self::MAX_TIMEOUT) {
                throw new InvalidArgumentException(sprintf(
                    'The timeout must be a finite number of seconds between 0 and %d, got %s.',
                    (int) self::MAX_TIMEOUT,
                    var_export($timeout, true),
                ));
            }
            $seconds = (int) floor($timeout);
            $interval = new \DateInterval('PT' . $seconds . 'S');
            $interval->f = $timeout - $seconds;
            $deadline = $clock->now()->add($interval);
        }
        $deadline = $deadline?->setTimezone(new \DateTimeZone('UTC'));

        return new self(
            $deadline === null ? $cancellation : new DeadlineCancellation($cancellation, $deadline, $clock),
            $deadline,
            $clock,
            self::traceOf($traceParent, $traceId, $spanId),
            self::checkedBaggage($baggage),
            new Items(),
        );
    }

    /**
     * 返回取消令牌：外部取消与截止时间合在一起。
     */
    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    /**
     * 把取消与超时翻译成原语层错误：超时为 KERNEL_TIMEOUT_001，外部取消为 KERNEL_CANCELLED_001；未取消为 null。
     *
     * @phpstan-impure 结果随时间与外部取消变化
     */
    public function cancellationError(): ?Error
    {
        return $this->cancellation->reason()?->toError();
    }

    /**
     * 返回截止时间（UTC）；没有时为 null。
     */
    public function deadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    /**
     * 判断是否有截止时间。
     */
    public function hasDeadline(): bool
    {
        return $this->deadline !== null;
    }

    /**
     * 判断在给定时刻是否已过截止时间；没有截止时间时为假。
     */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->deadline !== null && $now >= $this->deadline;
    }

    /**
     * 返回到截止时间的剩余秒数；没有截止时间时为 null，已过期为 0。
     */
    public function remainingTime(\DateTimeImmutable $now): ?float
    {
        if ($this->deadline === null) {
            return null;
        }
        $remaining = (float) $this->deadline->format('U.u') - (float) $now->format('U.u');

        return max(0.0, $remaining);
    }

    /**
     * 返回判定截止时间用的时钟。
     */
    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * 返回追踪 ID（32 位小写十六进制）。
     */
    public function traceId(): string
    {
        return $this->trace->traceId;
    }

    /**
     * 返回 Span ID（16 位小写十六进制）。
     */
    public function spanId(): string
    {
        return $this->trace->spanId;
    }

    /**
     * 返回是否采样：来自上游 trace-flags 的最低位；本 SDK 生成或只给了追踪 ID 时为真。
     */
    public function isSampled(): bool
    {
        return $this->trace->sampled;
    }

    /**
     * 返回 W3C traceparent。
     */
    public function traceParent(): string
    {
        return (string) $this->trace;
    }

    /**
     * 返回全部 Baggage。
     *
     * @return array<string, string>
     */
    public function baggage(): array
    {
        return $this->baggage;
    }

    /**
     * 取一个 Baggage 值；没有时为 null。
     */
    public function baggageValue(string $key): ?string
    {
        return $this->baggage[$key] ?? null;
    }

    /**
     * 派生一个多一个 Baggage 键值的上下文；同名的键被替换。
     *
     * 程序内逐项派生给已登记的键一个不合约束的值是编程错误（conventions/baggage-keys.md，ADR-0235）：抛出，
     * 不静默丢弃，也不保留原值，与键不合文法时一样；幂等键例外。外部输入走 create(baggage:)，那里丢弃不合的成员。
     *
     * @throws InvalidArgumentException 键不符合 `xihan.<name>`，或已登记键的值不合约束（PRIMITIVES_VALIDATION_001）
     */
    public function withBaggage(string $key, string $value): self
    {
        self::checkKey($key);
        if (!BaggageKeys::admits($key, $value)) {
            throw new InvalidArgumentException(sprintf(
                "The baggage value of '%s' must be non-empty, at most %d UTF-16 code units "
                . 'and free of control characters.',
                $key,
                BaggageKeys::MAX_VALUE_LENGTH,
            ));
        }
        $baggage = $this->baggage;
        $baggage[$key] = $value;

        return $this->derive($baggage);
    }

    /**
     * 派生一个只保留已登记键的上下文：载体出站时只写已登记的键（RFC-0003 规则 3）。
     */
    public function withoutUnregisteredBaggage(): self
    {
        return $this->derive(array_filter(
            $this->baggage,
            static fn (string $key): bool => BaggageKeys::isRegistered($key),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * 返回当前租户 ID；没有时为 null。
     */
    public function tenant(): ?string
    {
        return $this->baggageValue(BaggageKeys::TENANT);
    }

    /**
     * 返回当前主体 ID；没有时为 null。
     */
    public function principal(): ?string
    {
        return $this->baggageValue(BaggageKeys::PRINCIPAL);
    }

    /**
     * 返回业务关联 ID；没有时为 null。
     */
    public function correlation(): ?string
    {
        return $this->baggageValue(BaggageKeys::CORRELATION);
    }

    /**
     * 返回幂等键；没有时为 null。
     */
    public function idempotencyKey(): ?string
    {
        return $this->baggageValue(BaggageKeys::IDEMPOTENCY_KEY);
    }

    /**
     * 返回请求文化（BCP 47）；没有时为 null。
     */
    public function locale(): ?string
    {
        return $this->baggageValue(BaggageKeys::LOCALE);
    }

    /**
     * 返回请求作用域对象袋；派生上下文共享同一个。
     */
    public function items(): Items
    {
        return $this->items;
    }

    /**
     * @param array<string, string> $baggage
     */
    private function derive(array $baggage): self
    {
        return new self($this->cancellation, $this->deadline, $this->clock, $this->trace, $baggage, $this->items);
    }

    private static function traceOf(?string $traceParent, ?string $traceId, ?string $spanId): TraceParent
    {
        if ($traceParent !== null && ($traceId !== null || $spanId !== null)) {
            throw new InvalidArgumentException(
                'A context takes either a traceparent or a trace ID and span ID, not both.',
            );
        }
        // 不合法的 traceparent 当作没有给出（RFC-0003 规则 7）：不报错，也不把坏值带进链路，改为生成新的追踪
        $parsed = $traceParent === null ? null : TraceParent::tryParse($traceParent);
        if ($parsed !== null) {
            return $parsed;
        }

        // 没有上游的标志（生成追踪、或只给了追踪 ID）时按已采样传下去，由下游的采样器决定
        return new TraceParent($traceId ?? TraceParent::newTraceId(), $spanId ?? TraceParent::newSpanId(), true);
    }

    /**
     * 核对 Baggage：键符合 `xihan.<name>`、值是字符串（调用方不一定经过静态检查，运行期也核对），并丢弃值不合约束的已登记成员。
     *
     * @param array<array-key, mixed> $baggage
     *
     * @return array<string, string>
     */
    private static function checkedBaggage(array $baggage): array
    {
        $checked = [];
        foreach ($baggage as $key => $value) {
            $key = (string) $key;
            self::checkKey($key);
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf("The baggage value of '%s' must be a string.", $key));
            }
            // 由一组 Baggage 构造上下文是外部输入：丢弃不合的成员（与 W3C 忽略不合法的成员一致），上下文只携带已经通过的值
            if (BaggageKeys::admits($key, $value)) {
                $checked[$key] = $value;
            }
        }

        return $checked;
    }

    private static function checkKey(string $key): void
    {
        if (!BaggageKeys::isWellFormed($key)) {
            throw new InvalidArgumentException(sprintf("The baggage key '%s' must match xihan.<name>.", $key));
        }
    }
}
