<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

use XiHan\Framework\Primitives\Internal\Values;

/**
 * 可恢复失败的描述（RFC-0001 Error）。
 *
 * 错误码面向程序，消息键与参数面向人；不可变，`with*` 返回新值。用消息键创建的错误由接收方按区域翻译，
 * `literal()` 创建的错误带已解析的文案、没有消息键（线上还原与异常转换用）。
 *
 * 线上编码：`{"code":…,"message":…,"messageKey":…,"details":{…},"inner":{…}}`，没有的字段省略；
 * `message` 是发送端已解析的文案，`messageKey` 让接收端可再翻译。
 */
final readonly class Error implements \JsonSerializable, \Stringable
{
    /**
     * @param string $code 错误码 `<领域大写>_<KIND>_<NNN>`
     * @param string|null $messageKey 消息键；literal() 创建的为 null
     * @param string|null $text 已解析的文案；未解析为 null
     * @param array<array-key, mixed> $details 模板参数，也是结构化细节；只含 JSON 可表示的值，没有时为空数组
     * @param Error|null $inner 内部错误
     */
    private function __construct(
        public string $code,
        public ?string $messageKey,
        public ?string $text,
        public array $details,
        public ?Error $inner,
    ) {
    }

    /**
     * 用错误码与消息键创建错误。
     *
     * @param array<array-key, mixed> $details 模板参数，也是结构化细节；只允许 JSON 可表示的值
     *
     * @throws InvalidArgumentException 码或键不合法，或细节不是 JSON 可表示的值（PRIMITIVES_VALIDATION_001）
     */
    public static function of(string $code, string $messageKey, array $details = [], ?Error $inner = null): self
    {
        ErrorCode::validate($code);
        MessageTemplate::validateKey($messageKey);

        return new self($code, $messageKey, null, self::validDetails($details), $inner);
    }

    /**
     * 用已解析的文案创建错误：线上还原与异常转换用，没有消息键。
     *
     * @param array<array-key, mixed> $details 结构化细节；只允许 JSON 可表示的值
     *
     * @throws InvalidArgumentException 码不合法、文案为空或细节不是 JSON 可表示的值（PRIMITIVES_VALIDATION_001）
     */
    public static function literal(string $code, string $text, array $details = [], ?Error $inner = null): self
    {
        ErrorCode::validate($code);
        if ($text === '') {
            throw new InvalidArgumentException('A literal error needs a text.');
        }

        return new self($code, null, $text, self::validDetails($details), $inner);
    }

    /**
     * 创建 VALIDATION 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 VALIDATION，或码、键、细节不合法
     */
    public static function validation(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_VALIDATION, $code, $messageKey, $details, null);
    }

    /**
     * 创建 NOTFOUND 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 NOTFOUND，或码、键、细节不合法
     */
    public static function notFound(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_NOT_FOUND, $code, $messageKey, $details, null);
    }

    /**
     * 创建 CONFLICT 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 CONFLICT，或码、键、细节不合法
     */
    public static function conflict(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_CONFLICT, $code, $messageKey, $details, null);
    }

    /**
     * 创建 DENIED 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 DENIED，或码、键、细节不合法
     */
    public static function denied(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_DENIED, $code, $messageKey, $details, null);
    }

    /**
     * 创建 UNAVAILABLE 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 UNAVAILABLE，或码、键、细节不合法
     */
    public static function unavailable(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_UNAVAILABLE, $code, $messageKey, $details, null);
    }

    /**
     * 创建 TIMEOUT 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 TIMEOUT，或码、键、细节不合法
     */
    public static function timeout(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_TIMEOUT, $code, $messageKey, $details, null);
    }

    /**
     * 创建 CANCELLED 类错误。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 CANCELLED，或码、键、细节不合法
     */
    public static function cancelled(string $code, string $messageKey, array $details = []): self
    {
        return self::ofKind(ErrorCode::KIND_CANCELLED, $code, $messageKey, $details, null);
    }

    /**
     * 创建 UNEXPECTED 类错误，可带内部错误；参数顺序与 of() 一致，先细节、后内部错误（RFC-0001，ADR-0137）。
     *
     * @param array<array-key, mixed> $details
     *
     * @throws InvalidArgumentException 码的 KIND 段不是 UNEXPECTED，或码、键、细节不合法
     */
    public static function unexpected(
        string $code,
        string $messageKey,
        array $details = [],
        ?Error $inner = null,
    ): self {
        return self::ofKind(ErrorCode::KIND_UNEXPECTED, $code, $messageKey, $details, $inner);
    }

    /**
     * 从线上编码还原（已用 `json_decode(..., true)` 解码成关联数组）。
     *
     * 码不合法、消息键不合法或字段类型不对时整体失败；未知字段忽略。
     *
     * @param array<array-key, mixed> $wire
     *
     * @throws InvalidArgumentException 线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromArray(array $wire): self
    {
        $code = $wire['code'] ?? null;
        if (!is_string($code) || !ErrorCode::isValid($code)) {
            throw new InvalidArgumentException(sprintf(
                "The error code '%s' must match <DOMAIN>_<KIND>_<NNN>.",
                is_string($code) ? $code : get_debug_type($code),
            ));
        }
        $message = $wire['message'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new InvalidArgumentException("The field 'message' of an error on the wire must be a string.");
        }
        $messageKey = $wire['messageKey'] ?? null;
        if ($messageKey !== null && (!is_string($messageKey) || !MessageTemplate::isValidKey($messageKey))) {
            throw new InvalidArgumentException(
                "The field 'messageKey' of an error on the wire must be a message key <domain>.<segment>[.<segment>].",
            );
        }
        $details = $wire['details'] ?? [];
        if (!is_array($details) || !Values::isJson($details)) {
            throw new InvalidArgumentException("The field 'details' of an error on the wire must be a JSON object.");
        }
        $inner = $wire['inner'] ?? null;
        if ($inner !== null && !is_array($inner)) {
            throw new InvalidArgumentException("The field 'inner' of an error on the wire must be a JSON object.");
        }
        $text = $message === '' ? null : $message;
        if ($text === null && $messageKey === null) {
            throw new InvalidArgumentException("An error on the wire needs a 'message' or a 'messageKey'.");
        }

        return new self($code, $messageKey, $text, $details, $inner === null ? null : self::fromArray($inner));
    }

    /**
     * 从线上编码的 JSON 文本还原。
     *
     * @throws InvalidArgumentException 不是 JSON 对象，或线上编码不合法（PRIMITIVES_VALIDATION_001）
     */
    public static function fromJson(string $json): self
    {
        try {
            $wire = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The error on the wire is not valid JSON.', previous: $exception);
        }
        if (!is_array($wire)) {
            throw new InvalidArgumentException('The error on the wire must be a JSON object.');
        }

        return self::fromArray($wire);
    }

    /**
     * 返回错误码的 KIND 段。
     */
    public function kind(): string
    {
        return explode('_', $this->code)[1];
    }

    /**
     * 返回错误码的领域段。
     */
    public function domain(): string
    {
        return explode('_', $this->code)[0];
    }

    /**
     * 返回面向人的消息。
     *
     * 依次取已解析的文案、按进程默认区域解析消息键（MessageScope），最后是「键 (参数)」的描述；
     * 消息只给人看，不参与程序判断。
     */
    public function message(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }
        if ($this->messageKey === null) {
            return $this->code;
        }

        return MessageScope::resolve($this->messageKey, $this->details)
            ?? MessageTemplate::describe($this->messageKey, $this->details);
    }

    /**
     * 换错误码，保留消息键、文案、参数与内部错误。
     *
     * @throws InvalidArgumentException 码不合法（PRIMITIVES_VALIDATION_001）
     */
    public function withCode(string $code): self
    {
        return new self(ErrorCode::validate($code), $this->messageKey, $this->text, $this->details, $this->inner);
    }

    /**
     * 填入已解析的文案。
     *
     * @throws InvalidArgumentException 文案为空（PRIMITIVES_VALIDATION_001）
     */
    public function withText(string $text): self
    {
        if ($text === '') {
            throw new InvalidArgumentException('The text of an error must not be empty.');
        }

        return new self($this->code, $this->messageKey, $text, $this->details, $this->inner);
    }

    /**
     * 返回「码: 消息」，便于日志。
     */
    public function __toString(): string
    {
        return $this->code . ': ' . $this->message();
    }

    /**
     * 返回线上编码；细节总是编码成 JSON 对象（位置参数的键是 "0"、"1"…）。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $wire = ['code' => $this->code, 'message' => $this->message()];
        if ($this->messageKey !== null) {
            $wire['messageKey'] = $this->messageKey;
        }
        if ($this->details !== []) {
            $wire['details'] = (object) $this->details;
        }
        if ($this->inner !== null) {
            $wire['inner'] = $this->inner;
        }

        return $wire;
    }

    /**
     * 创建错误并核对 KIND 段与工厂一致。
     *
     * @param array<array-key, mixed> $details
     */
    private static function ofKind(string $kind, string $code, string $messageKey, array $details, ?Error $inner): self
    {
        $error = self::of($code, $messageKey, $details, $inner);
        if ($error->kind() !== $kind) {
            throw new InvalidArgumentException(sprintf("The error code '%s' must have the kind %s.", $code, $kind));
        }

        return $error;
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<array-key, mixed>
     */
    private static function validDetails(array $details): array
    {
        if (!Values::isJson($details)) {
            throw new InvalidArgumentException('The details of an error must only contain JSON-representable values.');
        }

        return $details;
    }
}
