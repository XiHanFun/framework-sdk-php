<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\JsonText;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\Result;

/**
 * 一个操作的请求解码与响应编码（RFC-0011「XGI 消息」：JSON 是规范编码）。
 *
 * PHP 没有不经反射的类型化 JSON 解码，所以登记操作时显式给出：请求的形状（Shape）与创建函数，响应的编码函数。
 * 解码按 RFC-0011 规则 2：先过 JSON 文本的共同检查，再按形状以文本顺序核对，第一处问题的路径就是 `reason`；
 * 核对过的值交给创建函数变换成请求类型。请求与响应的类型名用于类型化调用的核对。
 *
 * @template TRequest
 * @template TResponse
 */
final readonly class Codec
{
    /** 可以作为类型名的内置类型 */
    private const array BUILTIN_TYPES = ['array', 'string', 'int', 'float', 'bool', 'mixed'];

    /** @var \Closure(mixed): TRequest */
    private \Closure $create;

    /** @var \Closure(TResponse): mixed */
    private \Closure $encode;

    /**
     * @param Shape|null $shape 请求的形状；null 即不核对形状，请求是 JSON 对象解码成的关联数组
     * @param \Closure(mixed): TRequest $create
     * @param \Closure(TResponse): mixed $encode
     */
    private function __construct(
        public string $requestType,
        public string $responseType,
        private ?Shape $shape,
        \Closure $create,
        \Closure $encode,
    ) {
        $this->create = $create;
        $this->encode = $encode;
    }

    /**
     * 不做类型变换的编解码：请求是 JSON 对象解码成的关联数组（类型名 array），响应原样编码（类型名 mixed）。
     *
     * 关联数组里嵌套的 `{}` 与 `[]` 都是空数组；要区分时用 typed() 与 Shape::any()。
     *
     * @return self<array<string, mixed>, mixed>
     */
    public static function json(): self
    {
        return new self(
            'array',
            'mixed',
            null,
            static function (mixed $json): array {
                /** @var array<string, mixed> $json */
                return $json;
            },
            static fn (mixed $response): mixed => $response,
        );
    }

    /**
     * 类型化的编解码。
     *
     * 载荷先按 `$shape` 核对（规则见 Shape），合形状后把核对过的值交给创建函数；创建函数抛出的异常按调用期的异常翻译。
     *
     * @template TReq of object
     * @template TRes = mixed
     *
     * @param class-string<TReq> $requestType 请求类名
     * @param Shape $shape 请求的形状：Shape::object() 或 Shape::mapOf()
     * @param \Closure(mixed): TReq $create 创建函数：收到核对过的值（对象与字典是以成员名为键的数组）
     * @param string $responseType 响应类名，或 array、string、int、float、bool、mixed
     * @param (\Closure(TRes): mixed)|null $encode 编码函数；null 即原样编码（对象取公开属性或 jsonSerialize）
     *
     * @return self<TReq, TRes>
     *
     * @throws InvalidArgumentException 类型名不存在，或形状的根不是对象或字典（PRIMITIVES_VALIDATION_001）
     */
    public static function typed(
        string $requestType,
        Shape $shape,
        \Closure $create,
        string $responseType,
        ?\Closure $encode = null,
    ): self {
        if (!self::isType($requestType)) {
            throw new InvalidArgumentException(
                sprintf("The request type '%s' is not a class or interface.", $requestType),
            );
        }
        if (!$shape->isObjectLike()) {
            throw new InvalidArgumentException('The shape of a request must be an object or a map.');
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
        return new self($requestType, $responseType, $shape, $create, $encode);
    }

    /**
     * 解码 XGI 载荷（RFC-0011 规则 2）；不合法时返回 KERNEL_VALIDATION_001，`reason` 是出错位置的 JSON 路径。
     *
     * 空载荷等价于 `{}`。先过 JSON 文本的共同检查：语法错误写 `$`，任何一层的重复成员写第一处重复的路径；载荷是 null 或
     * 不是对象写 `$`；再按形状以文本顺序报第一处问题。
     *
     * @return Result<TRequest>
     */
    public function decode(string $id, string $payload): Result
    {
        if ($payload === '') {
            $payload = '{}';
        }
        $problem = JsonText::check($payload);
        if ($problem !== null) {
            return Result::fail(OperationErrors::invalidPayload($id, $problem->path));
        }
        try {
            $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // 检查已通过：只剩 stdClass 表示不了的成员名（以 U+0000 开头）
            return Result::fail(OperationErrors::invalidPayload($id, JsonPath::ROOT));
        }
        if (!$decoded instanceof \stdClass) {
            return Result::fail(OperationErrors::invalidPayload($id, JsonPath::ROOT));
        }
        if ($this->shape === null) {
            /** @var array<string, mixed> $object */
            $object = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            return Result::ok(($this->create)($object));
        }
        $path = $this->shape->read($decoded, JsonPath::ROOT, $value);
        if ($path !== null) {
            return Result::fail(OperationErrors::invalidPayload($id, $path));
        }

        return Result::ok(($this->create)($value));
    }

    /**
     * 编码响应；编码失败返回 KERNEL_UNEXPECTED_001。
     *
     * 数字按值模型写出（整数值的浮点带小数点，非有限的浮点写成字符串，RFC-0001「Result」）。
     *
     * @param TResponse $response
     *
     * @return Result<string>
     */
    public function encode(string $id, mixed $response): Result
    {
        try {
            return Result::ok(Json::encode(($this->encode)($response)));
        } catch (\JsonException $exception) {
            return Result::fail(Error::literal(
                OperationErrors::UNEXPECTED,
                "The response of '" . $id . "' could not be encoded: " . $exception->getMessage(),
            ));
        }
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

    private static function isType(string $name): bool
    {
        return class_exists($name) || interface_exists($name);
    }
}
