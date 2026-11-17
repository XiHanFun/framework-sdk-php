<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

/**
 * 宿主解析好的一个资源绑定（ConfigureRequest.bindings）：只含清单 bindings 里声明、运营方授予了 `binding:<名>` 的绑定。
 */
final readonly class ResolvedBinding
{
    /**
     * @param string $name 绑定名
     * @param string $type 资源类型，如 `connection:relational`、`cache:distributed`、`queue`、`blob`
     * @param string $value 连接串或地址
     */
    public function __construct(public string $name, public string $type, public string $value)
    {
    }
}
