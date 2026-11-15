<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 可能没有的值（RFC-0001 Maybe<T>）。
 *
 * 与 null 的编码等价，但类型层面不可为空：有值时值不能是 null。线上编码：有值为值本身，无值为 `null`。
 *
 * @template-covariant T
 */
final readonly class Maybe implements \JsonSerializable
{
    private function __construct(private bool $has, private mixed $value)
    {
    }

    /**
     * 创建有值的 Maybe。
     *
     * @template TValue
     *
     * @param TValue $value 不能为 null
     *
     * @return self<TValue>
     *
     * @throws InvalidArgumentException 值为 null（PRIMITIVES_VALIDATION_001）
     */
    public static function some(mixed $value): self
    {
        if ($value === null) {
            throw new InvalidArgumentException('A maybe with a value cannot hold null; use Maybe::none().');
        }

        return new self(true, $value);
    }

    /**
     * 创建无值的 Maybe。
     *
     * @return self<never>
     */
    public static function none(): self
    {
        return new self(false, null);
    }

    /**
     * 把可空值转成 Maybe：null 为无值；也是线上编码的还原。
     *
     * @template TValue
     *
     * @param TValue|null $value
     *
     * @return self<TValue>
     */
    public static function from(mixed $value): self
    {
        return $value === null ? self::none() : new self(true, $value);
    }

    /**
     * 判断是否有值。
     */
    public function hasValue(): bool
    {
        return $this->has;
    }

    /**
     * 返回值，无值时返回备选值。
     *
     * @template TFallback
     *
     * @param TFallback $fallback
     *
     * @return T|TFallback
     */
    public function valueOr(mixed $fallback): mixed
    {
        if (!$this->has) {
            return $fallback;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * 返回值。
     *
     * @return T
     *
     * @throws FailureException 无值（PRIMITIVES_VALIDATION_001）
     */
    public function valueOrThrow(): mixed
    {
        if (!$this->has) {
            throw new FailureException(Error::literal(ErrorCode::INVALID_ARGUMENT, 'The maybe has no value.'));
        }

        /** @var T */
        return $this->value;
    }

    /**
     * 按有值或无值分别求值。
     *
     * @template TOut
     *
     * @param \Closure(T): TOut $onSome
     * @param \Closure(): TOut $onNone
     *
     * @return TOut
     */
    public function match(\Closure $onSome, \Closure $onNone): mixed
    {
        if (!$this->has) {
            return $onNone();
        }

        /** @var T $value */
        $value = $this->value;

        return $onSome($value);
    }

    /**
     * 变换有值的 Maybe；无值原样传递，变换结果为 null 时得到无值。
     *
     * @template TOut
     *
     * @param \Closure(T): (TOut|null) $map
     *
     * @return self<TOut>
     */
    public function map(\Closure $map): self
    {
        if (!$this->has) {
            return self::none();
        }

        /** @var T $value */
        $value = $this->value;

        return self::from($map($value));
    }

    /**
     * 把值交给返回 Maybe 的函数；无值原样传递。
     *
     * @template TOut
     *
     * @param \Closure(T): self<TOut> $bind
     *
     * @return self<TOut>
     */
    public function bind(\Closure $bind): self
    {
        if (!$this->has) {
            return self::none();
        }

        /** @var T $value */
        $value = $this->value;

        return $bind($value);
    }

    /**
     * 把无值转成给定错误的失败。
     *
     * @return Result<T>
     */
    public function toResult(Error $error): Result
    {
        if (!$this->has) {
            return Result::fail($error);
        }

        /** @var T $value */
        $value = $this->value;

        return Result::ok($value);
    }

    /**
     * 返回线上编码：值本身或 null。
     */
    public function jsonSerialize(): mixed
    {
        return $this->value;
    }
}
