<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 按区域把消息键解析成已格式化的文案（RFC-0010 IMessageResolver）。
 */
interface MessageResolver
{
    /**
     * 返回文案；没有该键、该区域，或占位符缺值时返回 null。
     *
     * @param array<array-key, mixed> $arguments 模板参数
     */
    public function resolve(string $key, string $locale, array $arguments): ?string;
}
