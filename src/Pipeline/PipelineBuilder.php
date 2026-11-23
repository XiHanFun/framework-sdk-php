<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\Internal\Registration;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\KernelErrors;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 十一阶段的管道构建器（RFC-0004）：收集中间件与顺序约束，按规范排序后构建管道。
 *
 * 排序：先按阶段；阶段内先按位置（Beginning 在前），再按归属的序数字典序，再按登记顺序，
 * 最后按 before / after 约束做稳定拓扑排序——约束只调整必要的相对位置，其余保持基准次序。
 *
 * 次序约束的两端都是名字（RFC-0004 排序规则 7）：名字匹配按名字登记的实例名，或没有实例名的登记的中间件名；
 * 恰好一个时就是它，多个时（不同中间件的实例同名，或实例名与某个中间件名相同）有歧义。
 *
 * 报错的时机照 .NET（RFC-0004 排序规则 8）：重复登记、未知阶段（parseStage）、约束主体未登记或按名字有歧义在登记或
 * 声明时立即报；约束目标未登记、有歧义或不在同一阶段，以及约束成环，在 describe / dump / build 排序时报，阶段按顺序、
 * 阶段内按基准次序逐个中间件先解析它的 before、再解析它的 after。错误消息是 .NET 的英文原文，按名字登记的实例写成
 * `中间件名[实例名]`，成环时列出全部仍被卡住的中间件（ADR-0236）。
 * 运行期的成组登记与按归属撤回是宿主能力，本 SDK 不提供（RFC-0004「运行期改动」）。
 */
final class PipelineBuilder
{
    /** 应用自己登记的中间件的归属。 */
    public const string APP_OWNER = 'app';

    /**
     * 中间件顺序约束成环。
     *
     * @deprecated 改用 KernelErrors::PIPELINE_CYCLE：内核错误码集中在 Primitives\KernelErrors。
     */
    public const string ERROR_CODE_CYCLE = KernelErrors::PIPELINE_CYCLE;

    /**
     * 阶段名未知。
     *
     * @deprecated 改用 KernelErrors::UNKNOWN_STAGE：内核错误码集中在 Primitives\KernelErrors。
     */
    public const string ERROR_CODE_UNKNOWN_STAGE = KernelErrors::UNKNOWN_STAGE;

    /**
     * 中间件重复登记，约束主体声明时未登记，或顺序约束的目标未登记、有歧义或跨阶段。
     *
     * @deprecated 改用 KernelErrors::PIPELINE_REGISTRATION：内核错误码集中在 Primitives\KernelErrors。
     */
    public const string ERROR_CODE_REGISTRATION = KernelErrors::PIPELINE_REGISTRATION;

    /** @var list<Registration> */
    private array $registrations = [];

    /** @var array<int, array{before?: list<string>, after?: list<string>}> 按登记序号索引：主体在哪些名字之前、之后 */
    private array $constraints = [];

    /**
     * 解析清单里的阶段名。
     *
     * @throws ConfigurationException 阶段名未知（KERNEL_PIPELINE_002）
     */
    public static function parseStage(string $name): PipelineStage
    {
        return PipelineStage::tryFrom($name)
            ?? throw new ConfigurationException(
                sprintf("Unknown pipeline stage '%s'.", $name),
                KernelErrors::UNKNOWN_STAGE,
            );
    }

    /**
     * 登记中间件到 transaction 阶段末尾，归属 app（RFC-0004 排序规则 1）。
     *
     * @throws InvalidArgumentException 中间件名为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException 重复登记（KERNEL_PIPELINE_003）
     */
    public function use(Middleware $middleware): self
    {
        return $this->useAt($middleware, PipelineStage::Transaction);
    }

    /**
     * 登记中间件到指定阶段。
     *
     * @param string $owner 归属：清单 ID 或 app
     *
     * @throws InvalidArgumentException 中间件名或归属为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException 重复登记（KERNEL_PIPELINE_003）
     */
    public function useAt(
        Middleware $middleware,
        PipelineStage $stage,
        Placement $placement = Placement::End,
        string $owner = self::APP_OWNER,
    ): self {
        return $this->add($middleware, null, $stage, $placement, $owner);
    }

    /**
     * 按名字登记同一类型的另一个实例（RFC-0004 排序规则 7）；实例名在中间件名内唯一，打印为 `中间件名[实例名]`。
     *
     * @param string $owner 归属：清单 ID 或 app
     *
     * @throws InvalidArgumentException 实例名、中间件名或归属为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException 重复登记（KERNEL_PIPELINE_003）
     */
    public function useNamedInstance(
        string $instance,
        Middleware $middleware,
        PipelineStage $stage,
        Placement $placement = Placement::End,
        string $owner = self::APP_OWNER,
    ): self {
        if ($instance === '') {
            throw new InvalidArgumentException('A named middleware instance needs a name.');
        }

        return $this->add($middleware, $instance, $stage, $placement, $owner);
    }

    /**
     * 约束 `$middleware` 在 `$other` 之前；两端都是名字（实例名或没有实例名的中间件名）。
     *
     * @throws InvalidArgumentException 名字为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException `$middleware` 还没有登记或按名字有歧义（KERNEL_PIPELINE_003）
     */
    public function before(string $middleware, string $other): self
    {
        $subject = $this->requireByName($middleware);
        $this->constraints[$subject->order]['before'][] = $other;

        return $this;
    }

    /**
     * 约束 `$middleware` 在 `$other` 之后；两端都是名字（实例名或没有实例名的中间件名）。
     *
     * @throws InvalidArgumentException 名字为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException `$middleware` 还没有登记或按名字有歧义（KERNEL_PIPELINE_003）
     */
    public function after(string $middleware, string $other): self
    {
        $subject = $this->requireByName($middleware);
        $this->constraints[$subject->order]['after'][] = $other;

        return $this;
    }

    /**
     * 返回排序后的中间件描述。
     *
     * @return list<MiddlewareDescriptor>
     *
     * @throws ConfigurationException 约束目标不合法（KERNEL_PIPELINE_003）或成环（KERNEL_PIPELINE_001）
     */
    public function describe(): array
    {
        return array_map(
            static fn (Registration $registration): MiddlewareDescriptor => $registration->descriptor,
            $this->sorted(),
        );
    }

    /**
     * 按 `xihan pipeline` 的格式打印：一行一个中间件，阶段名左对齐占 13 列，后接打印名；没有中间件的阶段不打印。
     *
     * @throws ConfigurationException 同 describe()
     */
    public function dump(): string
    {
        $lines = '';
        foreach ($this->describe() as $descriptor) {
            $lines .= sprintf("%-12s %s\n", $descriptor->stage->manifestName(), $descriptor->display());
        }

        return $lines;
    }

    /**
     * 构建管道：`$terminal` 是 handle 阶段之后的终点（通常是操作分派）。
     *
     * 构建时按当时的登记排序并定型，之后的登记不影响已构建的管道。
     *
     * @param \Closure(Context): Result<Unit> $terminal
     *
     * @return \Closure(Context): Result<Unit>
     *
     * @throws ConfigurationException 同 describe()
     */
    public function build(\Closure $terminal): \Closure
    {
        // 包一层带返回类型的闭包：终点返回的不是 Result 时在边界上以 TypeError 失败
        $next = static fn (Context $context): Result => $terminal($context);
        $sorted = $this->sorted();
        for ($index = count($sorted) - 1; $index >= 0; $index--) {
            $middleware = $sorted[$index]->middleware;
            $downstream = $next;
            $next = static fn (Context $context): Result => $middleware->invoke($context, $downstream);
        }

        return $next;
    }

    private function add(
        Middleware $middleware,
        ?string $instance,
        PipelineStage $stage,
        Placement $placement,
        string $owner,
    ): self {
        $name = $middleware->name();
        if ($name === '') {
            throw new InvalidArgumentException('A middleware needs a name.');
        }
        if ($owner === '') {
            throw new InvalidArgumentException(sprintf(
                "The owner of the middleware '%s' must not be empty; use '%s' for the app.",
                $name,
                self::APP_OWNER,
            ));
        }
        // 重复登记在登记时立即报（RFC-0004 排序规则 8）：同一中间件的默认实例只登记一次，实例名在中间件名内唯一
        foreach ($this->registrations as $registration) {
            if ($registration->descriptor->middleware === $name && $registration->descriptor->instance === $instance) {
                throw self::registrationError($instance === null
                    ? sprintf(
                        "Middleware '%s' is already registered; each middleware type may appear only once in a "
                        . 'pipeline unless its instances are named.',
                        $name,
                    )
                    : sprintf(
                        "Middleware '%s' named '%s' is already registered; instance names must be unique within "
                        . 'a type.',
                        $name,
                        $instance,
                    ));
            }
        }
        $descriptor = new MiddlewareDescriptor($stage, $name, $instance, $owner, $placement);
        $this->registrations[] = new Registration($middleware, $descriptor, count($this->registrations));

        return $this;
    }

    /**
     * 按规则排序：先阶段；阶段内先位置、再归属、再登记顺序，最后按约束做稳定拓扑排序。
     *
     * @return list<Registration>
     */
    private function sorted(): array
    {
        $result = [];
        foreach (PipelineStage::cases() as $stage) {
            $members = array_values(array_filter(
                $this->registrations,
                static fn (Registration $registration): bool => $registration->descriptor->stage === $stage,
            ));
            // usort 是稳定排序；归属按字节序比较，与 .NET 的序数比较一致，纯数字的归属也不会按数值比较
            usort($members, static function (Registration $left, Registration $right): int {
                return $left->descriptor->placement->order() <=> $right->descriptor->placement->order()
                    ?: strcmp($left->descriptor->owner, $right->descriptor->owner)
                    ?: $left->order <=> $right->order;
            });
            array_push($result, ...$this->topologicalSort($stage, $members));
        }

        return $result;
    }

    /**
     * 取按名字唯一确定的登记；未登记或有歧义即立即报（RFC-0004 排序规则 7、8）。
     */
    private function requireByName(string $name): Registration
    {
        if ($name === '') {
            throw new InvalidArgumentException('An ordering constraint needs a middleware name.');
        }

        return $this->findByName($name) ?? throw self::registrationError(
            sprintf("Middleware '%s' is not registered, so no ordering constraint can be declared for it.", $name),
        );
    }

    /**
     * 按名字找登记：名字匹配按名字登记的实例名，或没有实例名的登记的中间件名；恰好一个返回它，没有返回 null，多个即有歧义。
     */
    private function findByName(string $name): ?Registration
    {
        $found = null;
        foreach ($this->registrations as $registration) {
            $instance = $registration->descriptor->instance;
            if (($instance ?? $registration->descriptor->middleware) !== $name) {
                continue;
            }
            if ($found !== null) {
                throw self::registrationError(sprintf(
                    "The middleware name '%s' matches several registrations, so ordering constraints by name are "
                    . 'ambiguous for it.',
                    $name,
                ));
            }
            $found = $registration;
        }

        return $found;
    }

    /**
     * 解析约束的目标：未登记、有歧义或不在主体的阶段都是登记错误。
     */
    private function resolveTarget(Registration $source, string $target, PipelineStage $stage): Registration
    {
        $other = $this->findByName($target) ?? throw self::registrationError(sprintf(
            "The ordering constraint of middleware '%s' references unregistered middleware '%s'.",
            $source->descriptor->display(),
            $target,
        ));
        if ($other->descriptor->stage !== $stage) {
            throw self::registrationError(sprintf(
                "Middleware '%s' (stage '%s') and '%s' (stage '%s') are not in the same stage, so no relative order "
                . 'can be declared between them.',
                $source->descriptor->display(),
                $stage->manifestName(),
                $target,
                $other->descriptor->stage->manifestName(),
            ));
        }

        return $other;
    }

    /**
     * 在基准次序上应用约束做稳定拓扑排序（Kahn）：每次取基准次序里最靠前、已没有前驱的节点。
     *
     * @param list<Registration> $members 已按基准次序排好的同一阶段的登记
     *
     * @return list<Registration>
     */
    private function topologicalSort(PipelineStage $stage, array $members): array
    {
        $position = [];
        foreach ($members as $index => $member) {
            $position[$member->order] = $index;
        }
        $incoming = array_fill(0, count($members), 0);
        $outgoing = [];
        // 按基准次序逐个中间件解析约束：先 before、再 after，与 .NET 报出同一个错误
        foreach ($members as $index => $member) {
            $constraints = $this->constraints[$member->order] ?? [];
            foreach ($constraints['before'] ?? [] as $later) {
                $other = $position[$this->resolveTarget($member, $later, $stage)->order];
                $outgoing[$index][] = $other;
                $incoming[$other]++;
            }
            foreach ($constraints['after'] ?? [] as $earlier) {
                $other = $position[$this->resolveTarget($member, $earlier, $stage)->order];
                $outgoing[$other][] = $index;
                $incoming[$index]++;
            }
        }
        $ordered = [];
        $remaining = array_keys($members);
        while ($remaining !== []) {
            $picked = null;
            foreach ($remaining as $slot => $candidate) {
                if ($incoming[$candidate] === 0) {
                    $picked = $slot;
                    break;
                }
            }
            // 剩下的每个节点都还有前驱：约束成环，按基准次序列出仍被卡住的中间件
            if ($picked === null) {
                $stuck = array_map(
                    static fn (int $node): string => "'" . $members[$node]->descriptor->display() . "'",
                    array_values(array_filter($remaining, static fn (int $node): bool => $incoming[$node] > 0)),
                );
                throw new ConfigurationException(
                    sprintf(
                        "Middleware ordering constraints in stage '%s' form a cycle: %s.",
                        $stage->manifestName(),
                        implode(', ', $stuck),
                    ),
                    KernelErrors::PIPELINE_CYCLE,
                );
            }
            $node = $remaining[$picked];
            unset($remaining[$picked]);
            $ordered[] = $members[$node];
            foreach ($outgoing[$node] ?? [] as $successor) {
                $incoming[$successor]--;
            }
        }

        return $ordered;
    }

    private static function registrationError(string $message): ConfigurationException
    {
        return new ConfigurationException($message, KernelErrors::PIPELINE_REGISTRATION);
    }
}
