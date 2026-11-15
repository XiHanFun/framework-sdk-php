<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\Values;

/**
 * 成功或失败（RFC-0001 Result / Result<T>）。
 *
 * 成功带值，失败带 Error，二者互斥；成功值不能为 null，「成功但没有值」用 `Result::success()`（值为 Unit）
 * 或 `Result<Maybe<T>>`。可恢复失败走 Result，不抛异常。
 *
 * 线上编码：`{"ok":true,"value":…}` 或 `{"ok":false,"error":{…}}`。
 *
 * @template-covariant T
 */
final readonly class Result implements \JsonSerializable, \Stringable
{
    private function __construct(private bool $ok, private mixed $value, private ?Error $error)
    {
    }

    /**
     * 创建成功结果。
     *
     * @template TValue
     *
     * @param TValue $value 成功值；不能为 null
     *
     * @return self<TValue>
     *
     * @throws InvalidArgumentException 值为 null（PRIMITIVES_VALIDATION_001）
     */
    public static function ok(mixed $value): self
    {
        if ($value === null) {
            throw new InvalidArgumentException(
                'A successful result needs a value; use Result::success() or a Maybe for no value.',
            );
        }

        return new self(true, $value, null);
    }

    /**
     * 创建失败结果。
     *
     * @return self<never>
     */
    public static function fail(Error $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * 创建没有值的成功结果，值为 Unit::Value。
     *
     * @return self<Unit>
     */
    public static function success(): self
    {
        return new self(true, Unit::Value, null);
    }

    /**
     * 从线上编码还原（已用 `json_decode(..., true)` 解码成关联数组）。
     *
     * 成功值先按 JSON 解码的形状交给 `$value` 变换成目标类型；不给时原样作为值。
     *
     * @template TValue
     *
     * @param array<array-key, mixed> $wire
     * @param (\Closure(mixed): TValue)|null $value 把解码出的成功值变换成目标类型
     *
     * @return ($value is null ? self<mixed> : self<TValue>)
     *
     * @throws InvalidArgumentException 线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromArray(array $wire, ?\Closure $value = null): self
    {
        $ok = $wire['ok'] ?? null;
        if (!is_bool($ok)) {
            throw new InvalidArgumentException("The field 'ok' of a result on the wire must be a boolean.");
        }
        if (!$ok) {
            $error = $wire['error'] ?? null;
            if (!is_array($error)) {
                throw new InvalidArgumentException('A failed result on the wire needs an error.');
            }

            return self::fail(Error::fromArray($error));
        }
        $decoded = $wire['value'] ?? null;
        if ($decoded === null) {
            throw new InvalidArgumentException('A successful result on the wire needs a value.');
        }

        return self::ok($value === null ? $decoded : $value($decoded));
    }

    /**
     * 从线上编码的 JSON 文本还原。
     *
     * @template TValue
     *
     * @param (\Closure(mixed): TValue)|null $value 把解码出的成功值变换成目标类型
     *
     * @return ($value is null ? self<mixed> : self<TValue>)
     *
     * @throws InvalidArgumentException 不是 JSON 对象，或线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromJson(string $json, ?\Closure $value = null): self
    {
        try {
            $wire = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The result on the wire is not valid JSON.', previous: $exception);
        }
        if (!is_array($wire)) {
            throw new InvalidArgumentException('The result on the wire must be a JSON object.');
        }

        return self::fromArray($wire, $value);
    }

    /**
     * 判断是否成功。
     */
    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * 判断是否失败。
     */
    public function isFailed(): bool
    {
        return !$this->ok;
    }

    /**
     * 返回失败原因；成功时为 null。
     */
    public function error(): ?Error
    {
        return $this->error;
    }

    /**
     * 返回成功值。
     *
     * @return T
     *
     * @throws FailureException 结果是失败（错误码取失败原因的码）
     */
    public function valueOrThrow(): mixed
    {
        if ($this->error !== null) {
            throw new FailureException($this->error);
        }

        /** @var T */
        return $this->value;
    }

    /**
     * 返回成功值，失败时返回备选值。
     *
     * @template TFallback
     *
     * @param TFallback $fallback
     *
     * @return T|TFallback
     */
    public function valueOr(mixed $fallback): mixed
    {
        if (!$this->ok) {
            return $fallback;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * 按成功或失败分别求值。
     *
     * @template TOut
     *
     * @param \Closure(T): TOut $onOk
     * @param \Closure(Error): TOut $onFail
     *
     * @return TOut
     */
    public function match(\Closure $onOk, \Closure $onFail): mixed
    {
        if ($this->error !== null) {
            return $onFail($this->error);
        }

        /** @var T $value */
        $value = $this->value;

        return $onOk($value);
    }

    /**
     * 把成功值变换成另一个值；失败原样传递。
     *
     * @template TOut
     *
     * @param \Closure(T): TOut $map 变换结果不能为 null
     *
     * @return self<TOut>
     */
    public function map(\Closure $map): self
    {
        if ($this->error !== null) {
            return self::fail($this->error);
        }

        /** @var T $value */
        $value = $this->value;

        return self::ok($map($value));
    }

    /**
     * 把成功值交给返回 Result 的函数；失败原样传递。
     *
     * @template TOut
     *
     * @param \Closure(T): self<TOut> $bind
     *
     * @return self<TOut>
     */
    public function bind(\Closure $bind): self
    {
        if ($this->error !== null) {
            return self::fail($this->error);
        }

        /** @var T $value */
        $value = $this->value;

        return $bind($value);
    }

    /**
     * 丢掉值，只保留成功与否。
     *
     * @return self<Unit>
     */
    public function toUnit(): self
    {
        return $this->error === null ? self::success() : self::fail($this->error);
    }

    /**
     * 返回 `Ok(值)` 或 `Fail(码: 消息)`，便于日志。
     */
    public function __toString(): string
    {
        return $this->error === null ? 'Ok(' . Values::text($this->value) . ')' : 'Fail(' . $this->error . ')';
    }

    /**
     * 返回线上编码。
     *
     * @return array{ok: true, value: mixed}|array{ok: false, error: Error}
     */
    public function jsonSerialize(): array
    {
        return $this->error === null
            ? ['ok' => true, 'value' => $this->value]
            : ['ok' => false, 'error' => $this->error];
    }
}
