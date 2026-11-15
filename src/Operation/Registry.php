<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Pipeline\PipelineBuilder;
use XiHan\Framework\Primitives\ConfigurationException;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;

/**
 * 操作登记表（RFC-0011 OperationRegistry）：ID 唯一，容量 CAPACITY。
 *
 * PHP 没有抢占式线程，Fiber 只在挂起点切换，登记与查找中间没有挂起点，不需要锁。
 */
final class Registry implements \Countable
{
    /** 登记表的容量。 */
    public const int CAPACITY = 10000;

    /** @var array<string, Descriptor> */
    private array $descriptors = [];

    /**
     * 登记一个操作。
     *
     * @template TRequest
     * @template TResponse
     *
     * @param Contract $contract 契约
     * @param \Closure(Context, TRequest): Result<TResponse> $handler 处理函数；可恢复失败返回失败的 Result，不抛异常
     * @param Codec<TRequest, TResponse>|null $codec 编解码；null 即 Codec::json()，请求是关联数组
     * @param Schema|null $schema 请求与响应的 JSON Schema
     * @param string $owner 归属：清单 ID 或 app
     *
     * @throws InvalidArgumentException 归属为空（PRIMITIVES_VALIDATION_001）
     * @throws ConfigurationException 重复登记（KERNEL_OPERATION_001）或登记表已满（KERNEL_OPERATION_002）
     */
    public function add(
        Contract $contract,
        \Closure $handler,
        ?Codec $codec = null,
        ?Schema $schema = null,
        string $owner = PipelineBuilder::APP_OWNER,
    ): Descriptor {
        if ($owner === '') {
            throw new InvalidArgumentException(sprintf(
                "The owner of the operation '%s' must not be empty; use '%s' for the app.",
                $contract->id,
                PipelineBuilder::APP_OWNER,
            ));
        }
        if (isset($this->descriptors[$contract->id])) {
            throw new ConfigurationException(
                sprintf("The operation '%s' is already registered.", $contract->id),
                OperationErrors::DUPLICATE,
            );
        }
        // 容量在登记时核对：登记表不随插件握手或配置无界增长，满了就是配置错误
        if (count($this->descriptors) >= self::CAPACITY) {
            throw new ConfigurationException(
                sprintf('The operation registry is full (%d operations).', self::CAPACITY),
                OperationErrors::REGISTRY_FULL,
            );
        }
        // 描述按擦除后的类型保存；请求与响应的类型由 Codec 的类型名在类型化调用时核对
        /** @var Codec<mixed, mixed> $erased */
        $erased = $codec ?? Codec::json();
        /** @var \Closure(Context, mixed): Result<mixed> $handler */
        $descriptor = new Descriptor($contract, $handler, $erased, $schema, $owner);
        $this->descriptors[$contract->id] = $descriptor;

        return $descriptor;
    }

    /**
     * 按 ID 查找操作；没有登记时为 null。
     */
    public function find(string $id): ?Descriptor
    {
        return $this->descriptors[$id] ?? null;
    }

    /**
     * 按 ID 的字节序列出全部操作。
     *
     * @return list<Descriptor>
     */
    public function list(): array
    {
        $descriptors = $this->descriptors;
        ksort($descriptors, SORT_STRING);

        return array_values($descriptors);
    }

    /**
     * 返回已登记的操作数。
     */
    public function count(): int
    {
        return count($this->descriptors);
    }
}
