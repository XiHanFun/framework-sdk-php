<?php

declare(strict_types=1);

namespace XiHan\Framework\Operation;

use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\JsonNode;
use XiHan\Framework\Primitives\Internal\JsonReader;
use XiHan\Framework\Primitives\Internal\JsonText;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\JsonPath;
use XiHan\Framework\Primitives\KernelErrors;
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
     * 不做类型变换的编解码：请求是按值模型读出的 JSON 对象（JsonObject），响应原样编码（类型名 mixed）。
     *
     * 值模型（ADR-0236）：对象是 JsonObject（成员名原样，`{}` 与 `[]` 不混淆），数组是列表，64 位范围内的整数写法是 int，
     * 其余数字是 float（`1e400` 是无穷，原样返回时写不出；要保留原文时用 typed() 与 Shape::any()）。
     *
     * @return self<JsonObject, mixed>
     */
    public static function json(): self
    {
        return new self(
            JsonObject::class,
            'mixed',
            null,
            static function (mixed $json): JsonObject {
                /** @var JsonObject $json */
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
        // 不经 stdClass 读：以 U+0000 开头的成员名照常核对，任意 JSON 按原文保留
        $root = JsonReader::parse($payload);
        if ($root->kind !== JsonNode::OBJECT) {
            return Result::fail(OperationErrors::invalidPayload($id, JsonPath::ROOT));
        }
        if ($this->shape === null) {
            // 无类型的路径按值模型读：对象是 JsonObject（{} 与 [] 不混淆），64 位范围内的整数精确，1e400 是无穷
            return Result::ok(($this->create)(JsonReader::toValue($root)));
        }
        $path = $this->shape->read($root, JsonPath::ROOT, $payload, $value);
        if ($path !== null) {
            return Result::fail(OperationErrors::invalidPayload($id, $path));
        }

        return Result::ok(($this->create)($value));
    }

    /**
     * 编码响应（RFC-0011 规则 10「编码」）；写不出时返回 KERNEL_UNEXPECTED_001 + kernel.invocation.unhandled_exception，
     * 编码函数抛出的是框架异常时它的错误放进 inner。
     *
     * 整数值的浮点带小数点，RawJson 原样放回；非有限的浮点写不出，不写成 null 或字符串。写出的整段文本还要过 RFC-0001
     * 「JSON 文本」的共同检查（JsonText::check）：嵌套超过 64 层（RawJson 原样嵌进去的文本也算，从响应的根算起）或有重复
     * 的成员名同样写不出，错误不带 inner（ADR-0319）。
     *
     * @param TResponse $response
     *
     * @return Result<string>
     */
    public function encode(string $id, mixed $response): Result
    {
        try {
            $text = Json::encode(($this->encode)($response), false);
        } catch (\Throwable $exception) {
            return Result::fail(KernelErrors::unhandledException($exception));
        }
        // json_encode 在 512 层以内照写，RawJson 的原文又是事后换回去的，整段的层数只有写完才知道，所以写完之后核对整段
        // 文本；没有异常可带，错误不带 inner
        if (JsonText::check($text) !== null) {
            return Result::fail(KernelErrors::unhandledException());
        }

        return Result::ok($text);
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
