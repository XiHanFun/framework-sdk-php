<?php

declare(strict_types=1);

namespace XiHan\Framework\Manifest;

/**
 * 清单种类（RFC-0002 `kind`）。
 */
enum Kind: string
{
    /** 能力包。 */
    case Capability = 'capability';

    /** 能力的 Provider。 */
    case Provider = 'provider';

    /** 插件。 */
    case Plugin = 'plugin';

    /** 宿主。 */
    case Host = 'host';

    /** 协议适配器。 */
    case Protocol = 'protocol';
}
