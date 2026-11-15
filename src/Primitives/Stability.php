<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 五级稳定性（RFC-0001「稳定性级别」）。
 *
 * 枚举值是清单与协议里的小写名；级别是对使用者的承诺，同一大版本内只升不降。
 */
enum Stability: string
{
    /** 同一大版本内不破坏。 */
    case Stable = 'stable';

    /** 小版本可以破坏，补丁版本不破坏。 */
    case Preview = 'preview';

    /** 小版本可以破坏或移除，补丁版本不破坏。 */
    case Experimental = 'experimental';

    /** 不承诺，只供声明它的包自己使用。 */
    case Internal = 'internal';

    /** 已弃用，只在大版本移除。 */
    case Deprecated = 'deprecated';
}
