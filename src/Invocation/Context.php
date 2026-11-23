<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

use XiHan\Framework\Invocation\Internal\LinkedCancellation;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\KernelMessages;

/**
 * 一次调用的上下文（RFC-0003）：取消与截止时间、W3C 追踪、Baggage 与请求作用域对象袋。
 *
 * 不可变，按派生传递：`with*` 返回新上下文，源上下文不变。派生上下文共享时钟、追踪与对象袋，Baggage 按值复制；
 * withBaggage() 一类的派生共享取消令牌与截止时间，withCancellation() 与 withDeadline() 派生出只会更早取消的令牌：
 * 源上下文取消时派生上下文连带取消，反过来不影响源上下文（RFC-0003 规则 1、2）。
 * 不绑定任何协议，载体映射由协议适配器与插件运行时实现。中间件与处理器的签名是同步的，可以运行在 Fiber 里。
 */
final class Context
{
    /**
     * 调用超过截止时间。
     *
     * @deprecated 改用 KernelErrors::DEADLINE_EXCEEDED：内核错误码集中在 Primitives\KernelErrors。
     */
    public const string ERROR_CODE_DEADLINE_EXCEEDED = KernelErrors::DEADLINE_EXCEEDED;

    /**
     * 调用被取消。
     *
     * @deprecated 改用 KernelErrors::CANCELLED：内核错误码集中在 Primitives\KernelErrors。
     */
    public const string ERROR_CODE_CANCELLED = KernelErrors::CANCELLED;

    /**
     * 超时的消息键。
     *
     * @deprecated 改用 KernelMessages::DEADLINE_EXCEEDED：消息键集中在 Primitives\KernelMessages。
     */
    public const string MESSAGE_DEADLINE_EXCEEDED = KernelMessages::DEADLINE_EXCEEDED;

    /**
     * 取消的消息键。
     *
     * @deprecated 改用 KernelMessages::CANCELLED：消息键集中在 Primitives\KernelMessages。
     */
    public const string MESSAGE_CANCELLED = KernelMessages::CANCELLED;

    /** 相对超时的上限（秒，约 31.7 年）：再大就超出微秒整数的可靠范围 */
    private const float MAX_TIMEOUT = 1_000_000_000.0;

    /**
     * @param bool|null $sampled 上游的采样决定；没有上游（这次调用是链路的根）为 null
     * @param array<string, string> $baggage
     */
    private function __construct(
        private readonly Cancellation $cancellation,
        private readonly ?\DateTimeImmutable $deadline,
        private readonly Clock $clock,
        private readonly TraceParent $trace,
        private readonly ?bool $sampled,
        private readonly array $baggage,
        private readonly Items $items,
    ) {
    }

    /**
     * 创建上下文。
     *
     * 截止时间与相对超时二选一；traceparent 与单独的追踪 ID / Span ID 二选一，都没有时生成新的追踪：没有上游的采样决定
     * （sampled() 为 null），写出时按已采样。只给追踪 ID 的按上游已采样处理（RFC-0003 规则 7）。
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
        [$trace, $sampled] = self::traceOf($traceParent, $traceId, $spanId);
        $baggage = self::checkedBaggage($baggage);

        return new self(
            $deadline === null ? $cancellation : new DeadlineCancellation($cancellation, $deadline, $clock),
            $deadline,
            $clock,
            $trace,
            $sampled,
            $baggage,
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
     * 判断调用是否已取消：外部取消或到达截止时间，等同 cancellation()->isRequested()。
     *
     * @phpstan-impure 结果随时间与外部取消变化
     */
    public function isCancelled(): bool
    {
        return $this->cancellation->isRequested();
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
     * 返回上游的采样决定（RFC-0003 规则 7 的 TraceFlags）：载体的 traceparent 给了标志时是它的最低位，「不采样」原样保留；
     * 只给追踪 ID 的创建方按上游已采样处理；追踪是本 SDK 生成的为 null，即没有上游、这次调用是链路的根。
     */
    public function sampled(): ?bool
    {
        return $this->sampled;
    }

    /**
     * 返回写出的 traceparent 带的采样位：上游的决定；没有上游时本 SDK 没有采样器，按已采样传下去、由下游的采样器决定
     * （RFC-0003 规则 7「写出」）。它分不出「上游已采样」与「没有上游」，要区分时用 sampled()。
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
     * 派生一个还能被 `$cancellation` 取消的上下文：本上下文的令牌或 `$cancellation` 任一触发，派生上下文即取消；
     * 取消它不影响本上下文。截止时间、追踪、Baggage 与对象袋不变。
     *
     * 归因照 RFC-0003 规则 1：触发时本上下文的令牌有原因就取它的（截止时间已到即超时，`$cancellation` 同时取消也一样），
     * 否则取 `$cancellation` 的。通常传 CancellationSource::cancellation()，由持有来源的一方调用 cancel()。
     */
    public function withCancellation(Cancellation $cancellation): self
    {
        return new self(
            new LinkedCancellation($this->cancellation, $cancellation),
            $this->deadline,
            $this->clock,
            $this->trace,
            $this->sampled,
            $this->baggage,
            $this->items,
        );
    }

    /**
     * 派生一个截止时间更早的上下文：下游的截止时间不得晚于上游（RFC-0003 规则 2），`$deadline` 不早于现有的截止时间时
     * 保持现有的。追踪、Baggage 与对象袋不变。
     *
     * 派生上下文的令牌在本上下文的令牌触发或到达 `$deadline` 时触发（规则 1），到达 `$deadline` 不影响本上下文。
     * 同步执行模型下没有定时器：新的截止时间在下一次轮询（isCancelled()，令牌的 isRequested / reason / subscribe）时核对时钟。
     *
     * @param \DateTimeImmutable $deadline 绝对截止时间，按 UTC 保存
     */
    public function withDeadline(\DateTimeImmutable $deadline): self
    {
        $deadline = $deadline->setTimezone(new \DateTimeZone('UTC'));
        if ($this->deadline !== null && $deadline >= $this->deadline) {
            return $this->derive($this->baggage);
        }

        return new self(
            new DeadlineCancellation($this->cancellation, $deadline, $this->clock),
            $deadline,
            $this->clock,
            $this->trace,
            $this->sampled,
            $this->baggage,
            $this->items,
        );
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
        return new self(
            $this->cancellation,
            $this->deadline,
            $this->clock,
            $this->trace,
            $this->sampled,
            $baggage,
            $this->items,
        );
    }

    /**
     * 定出追踪与上游的采样决定（RFC-0003 规则 7）。
     *
     * @return array{TraceParent, bool|null} 写出用的 traceparent，与上游的采样决定（没有上游为 null）
     */
    private static function traceOf(?string $traceParent, ?string $traceId, ?string $spanId): array
    {
        if ($traceParent !== null && ($traceId !== null || $spanId !== null)) {
            throw new InvalidArgumentException(
                'A context takes either a traceparent or a trace ID and span ID, not both.',
            );
        }
        // 不合法的 traceparent 当作没有给出（RFC-0003 规则 7）：不报错，也不把坏值带进链路，改为生成新的追踪
        $parsed = $traceParent === null ? null : TraceParent::tryParse($traceParent);
        if ($parsed !== null) {
            return [$parsed, $parsed->sampled];
        }
        // 只给追踪 ID 不给标志的创建方按上游已采样处理；追踪 ID 是生成的即没有上游，这次调用是链路的根
        $sampled = $traceId === null ? null : true;
        // 写出的 traceparent 总带标志：没有上游的标志时按已采样传下去，由下游的采样器决定
        $trace = new TraceParent($traceId ?? TraceParent::newTraceId(), $spanId ?? TraceParent::newSpanId(), true);

        return [$trace, $sampled];
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
