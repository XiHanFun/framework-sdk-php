<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 端点的网络类型（handshake.md §2、§4）。
 */
enum Network: string
{
    /** Unix 域套接字。 */
    case Unix = 'unix';

    /** TCP，只用回环地址。 */
    case Tcp = 'tcp';

    /** Windows 命名管道。 */
    case NamedPipe = 'npipe';
}
