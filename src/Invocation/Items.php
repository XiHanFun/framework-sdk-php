<?php

declare(strict_types=1);

namespace XiHan\Framework\Invocation;

/**
 * 请求作用域对象袋（RFC-0003 `Items`）。
 *
 * 同一次调用派生出的上下文共享同一个袋子；值不跨进程传播。PHP 没有抢占式线程，Fiber 只在挂起点切换，
 * 每次读写都是一步完成，并发读写不需要锁。键建议用类名或带命名空间的字符串，避免与别的包冲突。
 */
final class Items
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * 放一个值；同名的值被替换。
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * 判断是否有这个键（值为 null 也算有）。
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * 取一个值；没有这个键时为 null。
     */
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * 移除一个值；没有这个键时什么都不做。
     */
    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }
}
