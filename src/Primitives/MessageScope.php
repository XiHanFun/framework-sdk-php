<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 进程默认的消息解析器与区域（RFC-0001 MessageScope.Default）。
 *
 * `Error::message()` 在没有已解析文案时用它；解析器解析不出时，内核消息键用 KernelMessages 的 en-US 模板，
 * 其余退回「键 (参数)」的描述。
 * 这是进程级状态：PHP 没有抢占式线程，Fiber 只在挂起点切换，读写都是一步完成，不需要锁。
 */
final class MessageScope
{
    private static ?MessageResolver $resolver = null;

    private static string $locale = LocaleName::DEFAULT;

    private function __construct()
    {
    }

    /**
     * 设置进程默认的消息解析器与区域；解析器为 null 即清除。
     *
     * @throws InvalidArgumentException 区域名不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function setDefault(?MessageResolver $resolver, string $locale): void
    {
        LocaleName::validate($locale);
        self::$resolver = $resolver;
        self::$locale = $locale;
    }

    /**
     * 返回进程默认区域。
     */
    public static function defaultLocale(): string
    {
        return self::$locale;
    }

    /**
     * 用进程默认解析器与区域解析消息键；解析器解析不出的内核消息键用本 SDK 自带的 en-US 模板，
     * 都没有或参数缺值时返回 null。
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function resolve(string $key, array $arguments): ?string
    {
        return self::$resolver?->resolve($key, self::$locale, $arguments) ?? KernelMessages::english($key, $arguments);
    }
}
