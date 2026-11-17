<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;

/**
 * 写给宿主的结构化日志（invocation.md §6）：每条一行 JSON 对象，`level`、`message`，其余成员是作用域属性。
 *
 * 宿主把插件的 stderr 逐行转成自己的日志（来源 `plugins.<清单 ID>`）。stdout 只用于握手行，日志不能写到 stdout。
 */
final class Logger
{
    /** @var array<string, mixed> 每条都带的属性 */
    private array $scope;

    /**
     * @param resource|null $stream 写入的流；null 即 STDERR
     * @param LogLevel $minimum 最低记录的级别
     * @param array<array-key, mixed> $scope 每条都带的属性，如 `plugin`
     */
    public function __construct(
        private mixed $stream = null,
        private readonly LogLevel $minimum = LogLevel::Information,
        array $scope = [],
    ) {
        if ($stream !== null && !is_resource($stream)) {
            throw new InvalidArgumentException('The log stream must be a stream resource.');
        }
        $this->scope = self::properties($scope);
    }

    /**
     * 派生一个多带几个属性的日志器。
     *
     * @param array<array-key, mixed> $scope
     */
    public function with(array $scope): self
    {
        $logger = clone $this;
        $logger->scope = [...$this->scope, ...self::properties($scope)];

        return $logger;
    }

    /**
     * 判断级别是否会被记录。
     */
    public function isEnabled(LogLevel $level): bool
    {
        return $level->severity() >= $this->minimum->severity();
    }

    /**
     * 记一条日志；写不出（流已关闭）时丢弃，日志不让调用失败。
     *
     * @param array<array-key, mixed> $properties 这一条的属性
     */
    public function log(LogLevel $level, string $message, array $properties = []): void
    {
        if (!$this->isEnabled($level)) {
            return;
        }
        $entry = ['level' => $level->value, 'message' => $message, ...$this->scope, ...self::properties($properties)];
        // level 与 message 不被属性覆盖：宿主按这两个成员解析
        $entry['level'] = $level->value;
        $entry['message'] = $message;
        try {
            $line = Json::encode(JsonObject::of($entry));
        } catch (\JsonException) {
            $line = Json::encode(JsonObject::of(['level' => $level->value, 'message' => $message]));
        }
        @fwrite($this->stream ?? STDERR, $line . "\n");
    }

    /**
     * 记 trace 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function trace(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Trace, $message, $properties);
    }

    /**
     * 记 debug 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function debug(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Debug, $message, $properties);
    }

    /**
     * 记 information 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function information(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Information, $message, $properties);
    }

    /**
     * 记 warning 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function warning(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Warning, $message, $properties);
    }

    /**
     * 记 error 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function error(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Error, $message, $properties);
    }

    /**
     * 记 critical 级别的日志。
     *
     * @param array<array-key, mixed> $properties
     */
    public function critical(string $message, array $properties = []): void
    {
        $this->log(LogLevel::Critical, $message, $properties);
    }

    /**
     * 把属性归成 JSON 能写的值：异常写成类型与消息，其余不是 JSON 值的写成文本。
     *
     * @param array<array-key, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function properties(array $properties): array
    {
        $normalized = [];
        foreach ($properties as $name => $value) {
            $normalized[(string) $name] = match (true) {
                $value instanceof \Throwable => $value::class . ': ' . $value->getMessage(),
                $value instanceof \Stringable => (string) $value,
                $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
                is_object($value) && !$value instanceof \JsonSerializable => $value::class,
                is_resource($value) => get_resource_type($value),
                default => $value,
            };
        }

        return $normalized;
    }
}
