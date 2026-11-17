<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Invocation\Cancellation;
use XiHan\Framework\Invocation\Clock;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\PluginMessages;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Primitives\MessageScope;
use XiHan\Framework\Primitives\MessageTemplate;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;

/**
 * 协议消息与 SDK 类型的转换：错误与状态（invocation.md §3，RFC-0001）、上下文（invocation.md §2，RFC-0003）、信封与健康。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Protocol
{
    /** RFC 3339 的时刻：日期、`T`、时间、可选的小数秒与时区 */
    private const string RFC3339 = '/^([0-9]{4}-[0-9]{2}-[0-9]{2})[Tt]([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]+))?'
        . '([Zz]|[+-][0-9]{2}:[0-9]{2})$/D';

    private function __construct()
    {
    }

    /**
     * 把错误编码成协议的 Error：details 是按值模型写出的 JSON 对象文本，text 是能解析出的文案，inner 递归。
     */
    public static function toProtoError(Error $error): V1\Error
    {
        $encoded = new V1\Error();
        $encoded->setCode($error->code);
        if ($error->messageKey !== null) {
            $encoded->setMessageKey($error->messageKey);
        }
        $text = self::text($error);
        if ($text !== null) {
            $encoded->setText($text);
        }
        if ($error->details !== []) {
            try {
                $encoded->setDetails(Json::encode(JsonObject::of($error->details)));
            } catch (\JsonException) {
                // 细节写不成 JSON（非法 UTF-8）：宿主仍能按码与键处理，只是没有参数
            }
        }
        if ($error->inner !== null) {
            $encoded->setInner(self::toProtoError($error->inner));
        }

        return $encoded;
    }

    /**
     * 把协议的 Error 还原成原语层错误：details 按值模型读，不是 JSON 对象时没有细节；码不合法时换成 PLUGINS_UNEXPECTED_001，
     * 原码放进细节 `code`；键不合法时只保留文案。
     */
    public static function fromProtoError(V1\Error $error): Error
    {
        $details = [];
        $text = $error->getDetails();
        if ($text !== '') {
            try {
                $decoded = Wire::decode($text, 'error details');
                if ($decoded instanceof JsonObject) {
                    $details = $decoded->toArray();
                }
            } catch (InvalidArgumentException) {
                $details = [];
            }
        }
        $code = $error->getCode();
        if (!ErrorCode::isValid($code)) {
            $details['code'] = $code;
            $code = PluginErrors::UNEXPECTED;
        }
        $inner = $error->getInner();
        $innerError = $inner === null ? null : self::fromProtoError($inner);
        $key = $error->getMessageKey();
        $message = $error->getText();
        if ($key !== '' && MessageTemplate::isValidKey($key)) {
            $decodedError = Error::of($code, $key, $details, $innerError);

            return $message === '' ? $decodedError : $decodedError->withText($message);
        }

        return Error::literal($code, $message === '' ? $code : $message, $details, $innerError);
    }

    /**
     * 把结果编码成协议的 Status。
     *
     * @param Result<mixed> $result
     */
    public static function toStatus(Result $result): V1\Status
    {
        $status = new V1\Status();
        $error = $result->error();
        if ($error === null) {
            return $status->setOk(true);
        }

        return $status->setOk(false)->setError(self::toProtoError($error));
    }

    /**
     * 把协议的 Status 还原成结果；失败却没有错误时为 PLUGINS_UNEXPECTED_001。
     *
     * @return Result<Unit>
     */
    public static function fromStatus(?V1\Status $status, string $pluginId): Result
    {
        if ($status !== null && $status->getOk()) {
            return Result::success();
        }
        $error = $status?->getError();

        return Result::fail($error === null
            ? PluginErrors::failed($pluginId, 'callback', 'The status carries no error.')
            : self::fromProtoError($error));
    }

    /**
     * 由调用的元数据构造上下文（invocation.md §2）：截止时间取 deadline，没有时取 gRPC 的 grpc-timeout；追踪取 traceparent
     * （不合法的由上下文换成新的追踪）；Baggage 只收已登记的键，值不合约束的由上下文丢弃。
     */
    public static function context(
        ?V1\InvocationMetadata $metadata,
        ?float $grpcTimeout,
        Cancellation $cancellation,
        Clock $clock,
    ): Context {
        $deadline = $metadata === null ? null : self::parseDeadline($metadata->getDeadline());
        if ($deadline === null && $grpcTimeout !== null) {
            $seconds = (int) floor($grpcTimeout);
            $interval = new \DateInterval('PT' . $seconds . 'S');
            $interval->f = $grpcTimeout - $seconds;
            $deadline = $clock->now()->add($interval);
        }
        $baggage = [];
        foreach ($metadata?->getBaggage() ?? [] as $key => $value) {
            if (is_string($key) && is_string($value) && BaggageKeys::isRegistered($key)) {
                $baggage[$key] = $value;
            }
        }
        $traceParent = $metadata?->getTraceParent() ?? '';

        return Context::create(
            cancellation: $cancellation,
            deadline: $deadline,
            traceParent: $traceParent === '' ? null : $traceParent,
            baggage: $baggage,
            clock: $clock,
        );
    }

    /**
     * 把上下文写成调用的元数据：追踪、截止时间与已登记的 Baggage（RFC-0003 规则 3）。
     */
    public static function metadata(Context $context): V1\InvocationMetadata
    {
        $metadata = new V1\InvocationMetadata();
        $metadata->setTraceParent($context->traceParent());
        $deadline = $context->deadline();
        if ($deadline !== null) {
            $metadata->setDeadline(self::formatDeadline($deadline));
        }
        $metadata->setBaggage($context->withoutUnregisteredBaggage()->baggage());

        return $metadata;
    }

    /**
     * 解析 RFC 3339 的时刻；小数秒超过微秒的部分截掉，写法不合法时为 null。
     */
    public static function parseDeadline(string $text): ?\DateTimeImmutable
    {
        if (preg_match(self::RFC3339, $text, $match) !== 1) {
            return null;
        }
        $fraction = substr(str_pad($match[3], 6, '0'), 0, 6);
        $zone = strtoupper($match[4]) === 'Z' ? '+00:00' : $match[4];
        $text = $match[1] . 'T' . $match[2] . '.' . $fraction . $zone;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        // createFromFormat 把 13 月、25 时这类越界的值进位成别的时刻，只记一条警告：越界即不合法
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * 把时刻写成 RFC 3339 的 UTC 写法，带微秒。
     */
    public static function formatDeadline(\DateTimeImmutable $deadline): string
    {
        return $deadline->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * 把协议的信封还原。
     */
    public static function envelope(?V1\Envelope $envelope): Envelope
    {
        if ($envelope === null) {
            return new Envelope('', '', '', '');
        }
        $extensions = [];
        foreach ($envelope->getExtensions() as $name => $value) {
            if ((is_string($name) || is_int($name)) && is_string($value)) {
                $extensions[$name] = $value;
            }
        }

        return new Envelope(
            $envelope->getTopic(),
            $envelope->getId(),
            $envelope->getSource(),
            $envelope->getType(),
            $envelope->getData(),
            $envelope->getDataContentType(),
            $envelope->getSubject(),
            $envelope->getTime(),
            $envelope->getSpecVersion(),
            $extensions,
        );
    }

    /**
     * 把信封编码成协议消息；没有写的 specversion 与 datacontenttype 按 CloudEvents 的缺省补上。
     */
    public static function toProtoEnvelope(Envelope $envelope): V1\Envelope
    {
        return (new V1\Envelope())
            ->setTopic($envelope->topic)
            ->setId($envelope->id)
            ->setSource($envelope->source)
            ->setType($envelope->type)
            ->setSpecVersion($envelope->specVersion === '' ? '1.0' : $envelope->specVersion)
            ->setSubject($envelope->subject)
            ->setTime($envelope->time)
            ->setDataContentType($envelope->dataContentType === '' ? 'application/json' : $envelope->dataContentType)
            ->setData($envelope->data)
            ->setExtensions(array_map(strval(...), $envelope->extensions));
    }

    /**
     * 把健康编码成协议消息：状态是运行时裁定后的状态，不限于插件可自报的那几个。
     *
     * @param list<HealthItem> $items
     */
    public static function toProtoHealth(LifecycleState $state, array $items, ?Error $error): V1\HealthResponse
    {
        $response = new V1\HealthResponse();
        $response->setState($state->number());
        $encoded = [];
        foreach ($items as $item) {
            $encoded[] = (new V1\HealthItem())
                ->setName($item->name)
                ->setStatus($item->status->value)
                ->setDescription($item->description);
        }
        $response->setItems($encoded);
        if ($error !== null) {
            $response->setError(self::toProtoError($error));
        }

        return $response;
    }

    /**
     * 返回错误的文案：已有的文案，或按进程默认区域解析消息键（应用的目录、内核与插件运行时自带的模板）；解析不出为 null。
     */
    private static function text(Error $error): ?string
    {
        if ($error->text !== null) {
            return $error->text;
        }
        if ($error->messageKey === null) {
            return null;
        }

        return MessageScope::resolve($error->messageKey, $error->details)
            ?? PluginMessages::catalog()->resolve($error->messageKey, MessageScope::defaultLocale(), $error->details);
    }
}
