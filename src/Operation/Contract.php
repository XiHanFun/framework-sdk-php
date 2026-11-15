<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

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
     * @param string $id 操作 ID `<resource>.<verb>`
     * @param Stability $stability 稳定性；缺省 stable
     * @param list<Expose> $expose 暴露的协议
     * @param string|null $permission 权限码；与 policy 二选一
     * @param string|null $policy 策略名；与 permission 二选一
     *
     * @throws InvalidArgumentException ID 不合法、权限码与策略同时给出或为空串（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $id,
        public Stability $stability = Stability::Stable,
        array $expose = [],
        public ?string $permission = null,
        public ?string $policy = null,
    ) {
        if (!OperationId::isValid($id)) {
            throw new InvalidArgumentException(sprintf("The operation ID '%s' must match <resource>.<verb>.", $id));
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
        $this->expose = self::checkedExpose($id, $expose);
    }

    /**
     * @param array<array-key, mixed> $expose
     *
     * @return list<Expose>
     */
    private static function checkedExpose(string $id, array $expose): array
    {
        $checked = [];
        foreach ($expose as $index => $protocol) {
            if ($index !== count($checked) || !$protocol instanceof Expose) {
                throw new InvalidArgumentException(
                    sprintf("The exposure of the operation '%s' must be a list of %s.", $id, Expose::class),
                );
            }
            $checked[] = $protocol;
        }

        return $checked;
    }
}
