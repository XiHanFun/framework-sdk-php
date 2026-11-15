<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

/**
 * Provider 角色（RFC-0002 `role`）；清单不写时为 provider。
 */
enum Role: string
{
    /** 普通 Provider。 */
    case Provider = 'provider';

    /** 没有显式 Provider 时被选中的实现。 */
    case Default = 'default';
}
