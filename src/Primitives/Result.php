<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\Values;
use XiHan\Framework\Primitives\Internal\Wire;

/**
 * 成功或失败（RFC-0001 Result / Result<T>）。
 *
 * 成功带值，失败带 Error，二者互斥；成功值不能为 null，「成功但没有值」用 `Result::success()`（值为 Unit）
 * 或 `Result<Maybe<T>>`。可恢复失败走 Result，不抛异常。
 *
 * 线上编码（RFC-0001「Result」，ADR-0233）：有值成功 `{"ok":true,"value":…}`，无值成功 `{"ok":true}`，
 * 失败 `{"ok":false,"error":{…}}`。还原时按结果的种类选入口：fromWire（有值）、unitFromWire（无值）、
 * maybeFromWire（值类型为 Maybe，`"value":null` 即无值）。
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
     * 从线上编码还原有值的结果；接受 `json_decode` 的对象（stdClass）或关联数组。
     *
     * 成功时 value 必填且不能是 null；成功值按 JSON 解码的形状交给 `$value` 变换成目标类型，不给时原样作为值。
     * 失败时 error 必填、不能带 value；成功不能带 error；未知成员忽略（RFC-0001「Result」）。
     *
     * @template TValue
     *
     * @param (\Closure(mixed): TValue)|null $value 把解码出的成功值变换成目标类型
     *
     * @return ($value is null ? self<mixed> : self<TValue>)
     *
     * @throws InvalidArgumentException 线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromWire(mixed $wire, ?\Closure $value = null): self
    {
        $members = self::parse($wire);
        if ($members instanceof Error) {
            return self::fail($members);
        }
        if (!array_key_exists('value', $members) || $members['value'] === null) {
            throw new InvalidArgumentException('A successful result on the wire needs a value that is not null.');
        }

        return self::ok($value === null ? $members['value'] : $value($members['value']));
    }

    /**
     * 从线上编码还原无值的结果：成功只写 `{"ok":true}`，带了 value（`{}` 与 null 也算）即不合法。
     *
     * @return self<Unit>
     *
     * @throws InvalidArgumentException 线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function unitFromWire(mixed $wire): self
    {
        $members = self::parse($wire);
        if ($members instanceof Error) {
            return self::fail($members);
        }
        if (array_key_exists('value', $members)) {
            throw new InvalidArgumentException(
                'A successful result without a value must not carry a value on the wire.',
            );
        }

        return self::success();
    }

    /**
     * 从线上编码还原值类型为 Maybe 的结果：成功时 value 必填，null 即无值。
     *
     * @template TValue
     *
     * @param (\Closure(mixed): TValue)|null $value 把解码出的非 null 值变换成目标类型
     *
     * @return ($value is null ? self<Maybe<mixed>> : self<Maybe<TValue>>)
     *
     * @throws InvalidArgumentException 线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function maybeFromWire(mixed $wire, ?\Closure $value = null): self
    {
        $members = self::parse($wire);
        if ($members instanceof Error) {
            return self::fail($members);
        }
        if (!array_key_exists('value', $members)) {
            throw new InvalidArgumentException(
                'A successful result of a maybe needs a value on the wire, null for none.',
            );
        }
        $decoded = $members['value'];

        return self::ok($decoded === null ? Maybe::none() : Maybe::some($value === null ? $decoded : $value($decoded)));
    }

    /**
     * 从线上编码的 JSON 文本还原有值的结果（按对象模式解码，对象是 stdClass）。
     *
     * @template TValue
     *
     * @param (\Closure(mixed): TValue)|null $value 把解码出的成功值变换成目标类型
     *
     * @return ($value is null ? self<mixed> : self<TValue>)
     *
     * @throws InvalidArgumentException 不是 JSON，或线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromJson(string $json, ?\Closure $value = null): self
    {
        return self::fromWire(Wire::decode($json, 'result'), $value);
    }

    /**
     * 从线上编码的 JSON 文本还原无值的结果。
     *
     * @return self<Unit>
     *
     * @throws InvalidArgumentException 不是 JSON，或线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function unitFromJson(string $json): self
    {
        return self::unitFromWire(Wire::decode($json, 'result'));
    }

    /**
     * 从线上编码的 JSON 文本还原值类型为 Maybe 的结果。
     *
     * @template TValue
     *
     * @param (\Closure(mixed): TValue)|null $value 把解码出的非 null 值变换成目标类型
     *
     * @return ($value is null ? self<Maybe<mixed>> : self<Maybe<TValue>>)
     *
     * @throws InvalidArgumentException 不是 JSON，或线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function maybeFromJson(string $json, ?\Closure $value = null): self
    {
        return self::maybeFromWire(Wire::decode($json, 'result'), $value);
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
     * 返回 `Ok(值)`、无值成功的 `Ok()` 或 `Fail(码: 消息)`，便于日志。
     */
    public function __toString(): string
    {
        if ($this->error !== null) {
            return 'Fail(' . $this->error . ')';
        }

        return $this->value === Unit::Value ? 'Ok()' : 'Ok(' . Values::text($this->value) . ')';
    }

    /**
     * 返回线上编码：有值成功 `{"ok":true,"value":…}`，无值成功只写 `{"ok":true}`，失败 `{"ok":false,"error":{…}}`。
     *
     * @return array{ok: true, value?: mixed}|array{ok: false, error: Error}
     */
    public function jsonSerialize(): array
    {
        if ($this->error !== null) {
            return ['ok' => false, 'error' => $this->error];
        }

        return $this->value === Unit::Value ? ['ok' => true] : ['ok' => true, 'value' => $this->value];
    }

    /**
     * 核对 ok 与 value、error 的搭配：失败时返回还原出的错误，成功时返回成员留给调用方核对 value。
     *
     * @return Error|array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function parse(mixed $wire): Error|array
    {
        $members = Wire::members($wire, 'A result on the wire must be a JSON object.');
        $ok = $members['ok'] ?? null;
        if (!is_bool($ok)) {
            throw new InvalidArgumentException("The member 'ok' of a result on the wire must be a boolean.");
        }
        if ($ok) {
            if (array_key_exists('error', $members)) {
                throw new InvalidArgumentException('A successful result on the wire must not carry an error.');
            }

            return $members;
        }
        if (array_key_exists('value', $members)) {
            throw new InvalidArgumentException('A failed result on the wire must not carry a value.');
        }
        if (($members['error'] ?? null) === null) {
            throw new InvalidArgumentException('A failed result on the wire needs an error.');
        }

        return Error::fromWire($members['error']);
    }
}
