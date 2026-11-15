<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Internal\Values;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\Result;

/**
 * 一个操作的请求解码与响应编码（RFC-0011「XGI 消息」：JSON 是规范编码）。
 *
 * PHP 没有不经反射的类型化 JSON 解码，所以登记操作时显式给出：请求是 JSON 对象，先解码成关联数组，
 * 再交给解码函数变换成请求类型；响应经编码函数变换成可 json_encode 的值。请求与响应的类型名用于类型化调用的核对。
 *
 * @template TRequest
 * @template TResponse
 */
final readonly class Codec
{
    /** 可以作为类型名的内置类型 */
    private const array BUILTIN_TYPES = ['array', 'string', 'int', 'float', 'bool', 'mixed'];

    /** @var \Closure(array<string, mixed>): Result<TRequest> */
    private \Closure $decode;

    /** @var \Closure(TResponse): mixed */
    private \Closure $encode;

    /**
     * @param \Closure(array<string, mixed>): Result<TRequest> $decode
     * @param \Closure(TResponse): mixed $encode
     */
    private function __construct(
        public string $requestType,
        public string $responseType,
        \Closure $decode,
        \Closure $encode,
    ) {
        // 包一层带返回类型的闭包：解码函数返回的不是 Result 时在边界上以 TypeError 失败
        $this->decode = static function (array $json) use ($decode): Result {
            /** @var array<string, mixed> $json */
            return $decode($json);
        };
        $this->encode = $encode;
    }

    /**
     * 不做类型变换的编解码：请求是 JSON 对象解码成的关联数组（类型名 array），响应原样 json_encode（类型名 mixed）。
     *
     * @return self<array<string, mixed>, mixed>
     */
    public static function json(): self
    {
        return new self(
            'array',
            'mixed',
            static fn (array $json): Result => Result::ok($json),
            static fn (mixed $response): mixed => $response,
        );
    }

    /**
     * 类型化的编解码。
     *
     * 解码函数收到请求 JSON 对象的关联数组；形状不对时返回失败，通常用 OperationErrors::invalidPayload()。
     *
     * @template TReq of object
     * @template TRes = mixed
     *
     * @param class-string<TReq> $requestType 请求类名
     * @param \Closure(array<string, mixed>): Result<TReq> $decode 解码函数
     * @param string $responseType 响应类名，或 array、string、int、float、bool、mixed
     * @param (\Closure(TRes): mixed)|null $encode 编码函数；null 即原样 json_encode（对象取公开属性或 jsonSerialize）
     *
     * @return self<TReq, TRes>
     *
     * @throws InvalidArgumentException 类型名不存在（PRIMITIVES_VALIDATION_001）
     */
    public static function typed(
        string $requestType,
        \Closure $decode,
        string $responseType,
        ?\Closure $encode = null,
    ): self {
        if (!self::isType($requestType)) {
            throw new InvalidArgumentException(
                sprintf("The request type '%s' is not a class or interface.", $requestType),
            );
        }
        if (!in_array($responseType, self::BUILTIN_TYPES, true) && !self::isType($responseType)) {
            throw new InvalidArgumentException(sprintf(
                "The response type '%s' must be a class, an interface or one of %s.",
                $responseType,
                implode(', ', self::BUILTIN_TYPES),
            ));
        }
        $encode ??= static fn (mixed $response): mixed => $response;

        /** @var self<TReq, TRes> */
        return new self($requestType, $responseType, $decode, $encode);
    }

    /**
     * 解码 XGI 载荷：空白等价于 `{}`；不是 JSON、是 null 或不是 JSON 对象时返回 KERNEL_VALIDATION_001。
     *
     * @return Result<TRequest>
     */
    public function decode(string $id, string $payload): Result
    {
        // 只去掉 JSON 允许的空白
        $json = trim($payload, " \t\n\r");
        if ($json === '') {
            $json = '{}';
        }
        if ($json === 'null') {
            return Result::fail(OperationErrors::invalidPayload($id, 'the payload is null'));
        }
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return Result::fail(
                OperationErrors::invalidPayload($id, 'the payload is not valid JSON: ' . $exception->getMessage()),
            );
        }
        if (!$decoded instanceof \stdClass) {
            return Result::fail(OperationErrors::invalidPayload($id, '$: the payload must be a JSON object'));
        }
        // 先按对象解码区分 {} 与 []，确认是对象后再解成关联数组交给解码函数
        /** @var array<string, mixed> $object */
        $object = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return ($this->decode)($object);
    }

    /**
     * 编码响应；编码失败返回 KERNEL_UNEXPECTED_001。
     *
     * @param TResponse $response
     *
     * @return Result<string>
     */
    public function encode(string $id, mixed $response): Result
    {
        try {
            return Result::ok(json_encode(($this->encode)($response), Values::JSON_FLAGS));
        } catch (\JsonException $exception) {
            return Result::fail(Error::literal(
                OperationErrors::UNEXPECTED,
                "The response of '" . $id . "' could not be encoded: " . $exception->getMessage(),
            ));
        }
    }

    private static function isType(string $name): bool
    {
        return class_exists($name) || interface_exists($name);
    }

    /**
     * 判断一个请求对象是否是登记的请求类型（类型化调用的核对）。
     */
    public function acceptsRequest(mixed $request): bool
    {
        return match ($this->requestType) {
            'array' => is_array($request),
            'string' => is_string($request),
            'int' => is_int($request),
            'float' => is_float($request),
            'bool' => is_bool($request),
            'mixed' => true,
            default => $request instanceof $this->requestType,
        };
    }
}
