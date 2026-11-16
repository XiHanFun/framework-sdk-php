<?php

declare(strict_types=1);

namespace XiHan\Framework\Pipeline;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\Internal\Registration;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\PipelineStage;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 十一阶段的管道构建器（RFC-0004）：收集中间件与顺序约束，按规范排序后构建管道。
 *
 * 排序：先按阶段；阶段内先按位置（Beginning 在前），再按归属的序数字典序，再按登记顺序，
 * 最后按 before / after 约束做稳定拓扑排序——约束只调整必要的相对位置，其余保持基准次序。
 *
 * 次序约束的两端都是中间件名（RFC-0004 排序规则 7）：按名字登记的实例名，或只登记一次、没有实例名的中间件名。
 * 中间件名有多个实例时按它约束有歧义，改用实例名。
 *
 * 报错的时机照 .NET（RFC-0004 排序规则 8）：重复登记、未知阶段（parseStage）、约束主体未登记或按名字有歧义在登记或
 * 声明时立即报；约束目标未登记、有歧义或不在同一阶段，以及约束成环，在 describe / dump / build 排序时报。
 * 运行期的成组登记与按归属撤回是宿主能力，本 SDK 不提供（RFC-0004「运行期改动」）。
 */
final class PipelineBuilder
{
    /** 应用自己登记的中间件的归属。 */
    public const string APP_OWNER = 'app';

    /** 中间件顺序约束成环。 */
    public const string ERROR_CODE_CYCLE = 'KERNEL_PIPELINE_001';

    /** 阶段名未知。 */
    public const string ERROR_CODE_UNKNOWN_STAGE = 'KERNEL_PIPELINE_002';

    /** 中间件重复登记，约束主体声明时未登记，或顺序约束的目标未登记、有歧义或跨阶段。 */
    public const string ERROR_CODE_REGISTRATION = 'KERNEL_PIPELINE_003';

    /** @var list<Registration> */
    private array $registrations = [];

    /** @var list<array{string, string}> 每条约束是「前者在后者之前」 */
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
                sprintf("The name '%s' is not a pipeline stage.", $name),
                self::ERROR_CODE_UNKNOWN_STAGE,
            );
    }

    /**
     * 登记中间件到 transaction 阶段末尾，归属 app（RFC-0004 排序规则 1）。
     *
     * @throws InvalidArgumentException 中间件名为空（PRIMITIVES_VALIDATION_001）
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
     * 约束 `$middleware` 在 `$other` 之前；两端都是实例名或中间件名。
     *
     * @throws ConfigurationException `$middleware` 还没有登记（KERNEL_PIPELINE_003）
     */
    public function before(string $middleware, string $other): self
    {
        $this->requireSubject($middleware);
        $this->constraints[] = [$middleware, $other];

        return $this;
    }

    /**
     * 约束 `$middleware` 在 `$other` 之后；两端都是实例名或中间件名。
     *
     * @throws ConfigurationException `$middleware` 还没有登记（KERNEL_PIPELINE_003）
     */
    public function after(string $middleware, string $other): self
    {
        $this->requireSubject($middleware);
        $this->constraints[] = [$other, $middleware];

        return $this;
    }

    /**
     * 返回排序后的中间件描述。
     *
     * @return list<MiddlewareDescriptor>
     *
     * @throws ConfigurationException 重复登记、约束引用不合法（KERNEL_PIPELINE_003）或成环（KERNEL_PIPELINE_001）
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
        $descriptor = new MiddlewareDescriptor($stage, $name, $instance, $owner, $placement);
        // 重复登记在登记时立即报（RFC-0004 排序规则 8）：同一中间件的默认实例只登记一次，实例名在中间件名内唯一
        foreach ($this->registrations as $registration) {
            if ($registration->descriptor->middleware === $name && $registration->descriptor->instance === $instance) {
                throw self::registrationError(
                    sprintf("The middleware '%s' is registered more than once.", $descriptor->display()),
                );
            }
        }
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
        // 约束按名字引用：目标未登记、有歧义、跨阶段都是登记错误，在排序时才报
        $resolved = [];
        foreach ($this->constraints as [$first, $second]) {
            $before = $this->resolve($first);
            $after = $this->resolve($second);
            if ($before->descriptor->stage !== $after->descriptor->stage) {
                throw self::registrationError(
                    sprintf("The ordering constraint between '%s' and '%s' crosses stages.", $first, $second),
                );
            }
            $resolved[] = [$before, $after];
        }

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
            array_push($result, ...self::topologicalSort($stage, $members, $resolved));
        }

        return $result;
    }

    /**
     * 核对约束主体在声明时已登记、按名字没有歧义（RFC-0004 排序规则 7、8）；不合即立即报。
     */
    private function requireSubject(string $name): void
    {
        foreach ($this->registrations as $registration) {
            if ($registration->descriptor->instance === $name || $registration->descriptor->middleware === $name) {
                $this->resolve($name);

                return;
            }
        }
        throw self::registrationError(sprintf(
            "The middleware '%s' must be registered before an ordering constraint on it is declared.",
            $name,
        ));
    }

    /**
     * 按名字取唯一的登记：实例名，或只登记一次、没有实例名的中间件名；没有或有歧义都是登记错误。
     */
    private function resolve(string $name): Registration
    {
        $byType = array_values(array_filter(
            $this->registrations,
            static fn (Registration $registration): bool => $registration->descriptor->middleware === $name,
        ));
        // 中间件名有多个实例时，按它约束不知道指哪一个
        if (count($byType) > 1) {
            throw self::registrationError(sprintf(
                "The middleware '%s' has several instances; ordering constraints by type are ambiguous, "
                . 'use an instance name.',
                $name,
            ));
        }
        $candidates = array_values(array_filter(
            $this->registrations,
            static fn (Registration $registration): bool => $registration->descriptor->instance === $name
                || ($registration->descriptor->middleware === $name && $registration->descriptor->instance === null),
        ));
        if (count($candidates) > 1) {
            throw self::registrationError(
                sprintf("The name '%s' refers to several middleware; ordering constraints on it are ambiguous.", $name),
            );
        }
        if ($candidates === []) {
            throw self::registrationError($byType === []
                ? sprintf("The ordering constraint references the unregistered middleware '%s'.", $name)
                : sprintf(
                    "The middleware '%s' is only registered as the named instance '%s'; "
                    . 'refer to it by its instance name.',
                    $name,
                    (string) $byType[0]->descriptor->instance,
                ));
        }

        return $candidates[0];
    }

    /**
     * 在基准次序上应用约束做稳定拓扑排序（Kahn）：每次取基准次序里最靠前、已没有前驱的节点。
     *
     * @param list<Registration> $members 已按基准次序排好的同一阶段的登记
     * @param list<array{Registration, Registration}> $constraints 已解析的全部约束
     *
     * @return list<Registration>
     */
    private static function topologicalSort(PipelineStage $stage, array $members, array $constraints): array
    {
        $position = [];
        foreach ($members as $index => $member) {
            $position[$member->order] = $index;
        }
        $incoming = array_fill(0, count($members), 0);
        $outgoing = [];
        foreach ($constraints as [$before, $after]) {
            // 约束已核对过同阶段，不在本阶段的属于别的阶段
            if (!isset($position[$before->order], $position[$after->order])) {
                continue;
            }
            $outgoing[$position[$before->order]][] = $position[$after->order];
            $incoming[$position[$after->order]]++;
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
            // 剩下的每个节点都还有前驱：约束成环，任何次序都满足不了
            if ($picked === null) {
                throw new ConfigurationException(
                    sprintf("The ordering constraints in stage '%s' form a cycle.", $stage->manifestName()),
                    self::ERROR_CODE_CYCLE,
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
        return new ConfigurationException($message, self::ERROR_CODE_REGISTRATION);
    }
}
