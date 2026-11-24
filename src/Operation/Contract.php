<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Internal\Lists;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\OperationId;
use XiHan\Framework\Primitives\Stability;

/**
 * 操作契约（RFC-0011 OperationContract）：ID、稳定性、暴露的协议与授权要求。
 *
 * 授权要求是权限码与策略名二选一，都没有即无要求；暴露列表为空表示不对外发布。
 */
final readonly class Contract
{
    /** @var list<Expose> */
    public array $expose;

    /**
     * 创建契约。
     *
     * @param string $id 操作 ID `<resource>.<verb>`，不超过 128 个字符（OperationId::MAX_LENGTH）
     * @param Stability $stability 稳定性；缺省 stable
     * @param list<Expose> $expose 暴露的协议
     * @param string|null $permission 权限码；与 policy 二选一
     * @param string|null $policy 策略名；与 permission 二选一
     *
     * @throws InvalidArgumentException ID 不合法、权限码与策略同时给出或为空串、暴露的协议不是 Expose 的列表
     *     （PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $id,
        public Stability $stability = Stability::Stable,
        array $expose = [],
        public ?string $permission = null,
        public ?string $policy = null,
    ) {
        if (!OperationId::isValid($id)) {
            throw new InvalidArgumentException(sprintf(
                "The operation ID '%s' must match <resource>.<verb> and be at most %d characters long.",
                $id,
                OperationId::MAX_LENGTH,
            ));
        }
        if ($permission !== null && $policy !== null) {
            throw new InvalidArgumentException(
                sprintf("The operation '%s' must declare either a permission or a policy.", $id),
            );
        }
        if ($permission === '' || $policy === '') {
            throw new InvalidArgumentException(
                sprintf("The permission or policy of the operation '%s' must not be empty; use null for none.", $id),
            );
        }
        // 调用方不一定经过静态检查，运行期也核对暴露列表的类型
        $this->expose = Lists::of(Expose::class, $expose, sprintf("The exposure of the operation '%s'", $id));
    }
}
