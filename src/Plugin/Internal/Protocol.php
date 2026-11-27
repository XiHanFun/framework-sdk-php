<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

use XiHan\Framework\Invocation\Cancellation;
use XiHan\Framework\Invocation\Clock;
use XiHan\Framework\Invocation\Context;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\HealthItem;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Primitives\BaggageKeys;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Json;
use XiHan\Framework\Primitives\Internal\Whitespace;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\LifecycleState;
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
    /** 替换字符 U+FFFD 的 UTF-8 编码：写出错误文案时换掉不是合法 UTF-8 的部分（ADR-0339） */
    private const string REPLACEMENT = "\xEF\xBF\xBD";

    private function __construct()
    {
    }

    /**
     * 把错误写成协议的 Error（invocation.md §3「写出」）：code 与 message_key 原样；带消息键的错误 text 留空，由宿主按目录
     * 翻译成调用方的语言（RFC-0010），只有自由文本错误写 text；details 是参数与细节的 JSON 对象文本，没有时为空；inner 同样写出。
     * 细节写不出（不是合法的 UTF-8，或写出的文本没过共同检查：超过 64 层）时这个错误不带细节，其余字段照写，内部错误的细节
     * 各自核对（RFC-0001「写出与转交」，ADR-0320）。text 里不是合法 UTF-8 的部分按最大子部分换成 U+FFFD 写出，内部错误的
     * 同此（invocation.md §3「写出 Error 的文案」，ADR-0339，见 replaceInvalidUtf8()）：protobuf 的 setText 遇到它会抛出，
     * 整个错误写不出。
     */
    public static function toProtoError(Error $error): V1\Error
    {
        $encoded = new V1\Error();
        $encoded->setCode($error->code);
        if ($error->messageKey !== null) {
            $encoded->setMessageKey($error->messageKey);
        } elseif ($error->text !== null) {
            // 文案是自由文本，不拿来查找或比较：编不成合法 UTF-8 的部分换成 U+FFFD，不让整个回调、发布或应答写不出
            $encoded->setText(self::replaceInvalidUtf8($error->text));
        }
        if ($error->details !== []) {
            try {
                $encoded->setDetails(Json::encodeChecked(JsonObject::of($error->details)));
            } catch (\JsonException) {
                // 细节写不成合法的 JSON 文本（非法 UTF-8、超过 64 层）：宿主仍能按码与键处理，只是没有参数
            }
        }
        if ($error->inner !== null) {
            $encoded->setInner(self::toProtoError($error->inner));
        }

        return $encoded;
    }

    /**
     * 把文本里不是合法 UTF-8 的部分换成 U+FFFD（invocation.md §3「写出 Error 的文案」，ADR-0339），合法的部分原样。
     *
     * 按 Unicode 标准 §3.9 的「最大子部分」：从不能解码的位置起，能成为某个合法 UTF-8 序列开头的最长前缀是一段，不能的一个
     * 字节是一段，每一段换一个 U+FFFD。如 `F0 9F 98` 后面跟着 `62` 是一段、`ED A0 80`（编成 UTF-8 的代理项，`ED` 之后只能跟
     * `80`–`9F`）是三段、`C0 AF` 是两段，与 .NET 的 `Encoding.UTF8.GetString`、Python 的 `bytes.decode('utf-8', 'replace')` 相同。
     * SDK 不要求 ext-mbstring，所以不用 `mb_scrub`；`htmlspecialchars` 的 `ENT_SUBSTITUTE` 换法的粒度不同，也不用。
     */
    private static function replaceInvalidUtf8(string $text): string
    {
        // 合法的 UTF-8 原样交回：PCRE 的 UTF-8 校验同样拒绝过长编码、代理项与 U+10FFFF 以上的码位
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        $replaced = '';
        $length = strlen($text);
        $index = 0;
        while ($index < $length) {
            $lead = ord($text[$index]);
            if ($lead < 0x80) {
                $replaced .= $text[$index];
                $index++;
                continue;
            }
            // 序列的长度与第二个字节的范围按 Unicode 表 3-7：E0、ED、F0、F4 的第二个字节比 80–BF 窄
            [$size, $low, $high] = match (true) {
                $lead >= 0xC2 && $lead <= 0xDF => [2, 0x80, 0xBF],
                $lead === 0xE0 => [3, 0xA0, 0xBF],
                $lead === 0xED => [3, 0x80, 0x9F],
                $lead >= 0xE1 && $lead <= 0xEF => [3, 0x80, 0xBF],
                $lead === 0xF0 => [4, 0x90, 0xBF],
                $lead === 0xF4 => [4, 0x80, 0x8F],
                $lead >= 0xF1 && $lead <= 0xF3 => [4, 0x80, 0xBF],
                default => [1, 0, 0],
            };
            // 往后数还能接上的字节：第二个字节按上面的范围，其余的按 80–BF；接不上或到了末尾即停
            $taken = 1;
            while ($taken < $size && $index + $taken < $length) {
                $next = ord($text[$index + $taken]);
                $from = $taken === 1 ? $low : 0x80;
                $to = $taken === 1 ? $high : 0xBF;
                if ($next < $from || $next > $to) {
                    break;
                }
                $taken++;
            }
            if ($size > 1 && $taken === $size) {
                $replaced .= substr($text, $index, $size);
            } else {
                // 不能开头的字节，或开了头却没有接完的前缀：这一段换一个 U+FFFD
                $replaced .= self::REPLACEMENT;
            }
            $index += $taken;
        }

        return $replaced;
    }

    /**
     * 读入宿主写来的 Error（invocation.md §3「读入」，向量 error-reading.json）：code 不合文法时整个错误换成
     * PLUGINS_UNEXPECTED_001 + plugins.process.failed（`phase` 一律 invoke，不论是哪个 RPC，原来的 inner 丢掉）；message_key
     * 合文法的按键处理（不取 text），否则是自由文本错误，文案取 text，text 为空或只有空白时取 code；details 按 details() 读；
     * inner 同此。
     */
    public static function fromProtoError(V1\Error $error, string $pluginId): Error
    {
        $code = $error->getCode();
        if (!ErrorCode::isValid($code)) {
            return PluginErrors::failed($pluginId, 'invoke', sprintf("invalid error code '%s'", $code));
        }
        $inner = $error->getInner();
        $innerError = $inner === null ? null : self::fromProtoError($inner, $pluginId);
        $details = self::details($error->getDetails());
        $key = $error->getMessageKey();
        if ($key !== '' && MessageTemplate::isValidKey($key)) {
            return Error::of($code, $key, $details, $innerError);
        }
        $text = $error->getText();

        // Error 的文案不能只有空白（RFC-0001「空白字符」，ADR-0245）：只有空白的 text 与空串一样取 code
        return Error::literal($code, Whitespace::isBlank($text) ? $code : $text, $details, $innerError);
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
     * 把宿主写来的 Status 还原成结果；失败却没有错误时为 PLUGINS_UNEXPECTED_001（`phase` 为 $phase）。
     *
     * @return Result<Unit>
     */
    public static function fromStatus(?V1\Status $status, string $pluginId, string $phase): Result
    {
        if ($status !== null && $status->getOk()) {
            return Result::success();
        }
        $error = $status?->getError();

        return Result::fail($error === null
            ? PluginErrors::failed($pluginId, $phase, PluginErrors::REASON_NO_ERROR)
            : self::fromProtoError($error, $pluginId));
    }

    /**
     * 由调用的元数据还原上下文（invocation.md §2）：traceparent 按 RFC-0003 的文法取，不合文法即没有父 Span、开始新的追踪；
     * trace_state 不进上下文；Baggage 只留已登记的键（值不合约束的由上下文丢弃）；截止时间取 deadline（按严格文法读，
     * 读不出当作没有）与 gRPC 调用自身的截止时间中较早的一个。
     *
     * @param float|null $grpcTimeout grpc-timeout 给出的剩余秒数；没有为 null
     */
    public static function context(
        ?V1\InvocationMetadata $metadata,
        ?float $grpcTimeout,
        Cancellation $cancellation,
        Clock $clock,
    ): Context {
        $grpcDeadline = null;
        if ($grpcTimeout !== null) {
            $seconds = (int) floor($grpcTimeout);
            $interval = new \DateInterval('PT' . $seconds . 'S');
            $interval->f = $grpcTimeout - $seconds;
            $grpcDeadline = $clock->now()->add($interval);
        }
        $deadline = Deadlines::effective(
            $metadata === null ? null : Deadlines::parse($metadata->getDeadline()),
            $grpcDeadline,
        );
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
     * 把上下文写成回调的元数据（invocation.md §5）：当前 Span 的 traceparent、按 UTC 七位小数写的截止时间、已登记的
     * Baggage；trace_state 为空。
     */
    public static function metadata(Context $context): V1\InvocationMetadata
    {
        $metadata = new V1\InvocationMetadata();
        $metadata->setTraceParent($context->traceParent());
        $deadline = $context->deadline();
        if ($deadline !== null) {
            $metadata->setDeadline(Deadlines::format($deadline));
        }
        $metadata->setBaggage($context->withoutUnregisteredBaggage()->baggage());

        return $metadata;
    }

    /**
     * 把宿主写来的信封还原（invocation.md §4）：`time` 按 RFC-0003 规则 8 读成 UTC 时刻（比微秒更细的小数截断），`dataschema`
     * 原样，两者的空串都是没有；`time` 读不出时为 null。
     */
    public static function envelope(V1\Envelope $envelope): ?Envelope
    {
        $time = null;
        if ($envelope->getTime() !== '') {
            $time = Deadlines::parse($envelope->getTime());
            if ($time === null) {
                return null;
            }
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
            $time,
            $envelope->getSpecVersion(),
            $extensions,
            $envelope->getDataSchema(),
        );
    }

    /**
     * 把信封编码成协议消息；没有写的 specversion 与 datacontenttype 按 CloudEvents 的缺省补上，`time` 按 formatTime() 写，
     * `dataschema` 原样，没有的写空串。
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
            ->setTime($envelope->time === null ? '' : self::formatTime($envelope->time))
            ->setDataContentType($envelope->dataContentType === '' ? 'application/json' : $envelope->dataContentType)
            ->setDataSchema($envelope->dataSchema)
            ->setData($envelope->data)
            ->setExtensions(array_map(strval(...), $envelope->extensions));
    }

    /**
     * 把信封的 `time` 写成 RFC 3339（RFC-0009「信封」第 3 条）：换算成 UTC、以 `Z` 结尾，秒的小数去掉末尾的 0，全为 0 时不写
     * 小数点，最多六位（PHP 的时刻只到微秒），如 `2026-11-25T03:25:00.5Z`；六个 SDK 对同一时刻写出同一份文本（精度以内）。
     * 年份至少四位；不在本地核对（ADR-0306），9999 年之后照写五位以上的年份，公元前照写 `0000` 与带负号的年份（PHP 8.2 起
     * `Y` 的写法，与 Go 相同），由宿主拒绝那一项。
     */
    public static function formatTime(\DateTimeImmutable $time): string
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));
        $micros = (int) $utc->format('u');
        $fraction = $micros === 0 ? '' : '.' . rtrim(sprintf('%06d', $micros), '0');

        return $utc->format('Y-m-d\TH:i:s') . $fraction . 'Z';
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
     * 读 details（invocation.md §3）：空、只有空白、空对象、通不过共同 JSON 读取检查、不是对象，或任何一层有读成双精度会
     * 溢出（±∞）的数时没有细节；下溢的数读成 0，超出 64 位的整数读成双精度（值模型本来就这样读）。
     *
     * @return array<array-key, mixed>
     */
    private static function details(string $text): array
    {
        // 只有空白的文本在共同读取检查里是语法错误
        if ($text === '') {
            return [];
        }
        try {
            $decoded = Wire::decode($text, 'error details');
        } catch (InvalidArgumentException) {
            return [];
        }
        if (!$decoded instanceof JsonObject || !self::isFinite($decoded)) {
            return [];
        }

        // 空对象的成员表是空数组，与没有细节相同
        return $decoded->toArray();
    }

    /**
     * 判断值里（含各层的数组与对象）没有无穷：值模型把超出双精度的数读成 ±∞。
     */
    private static function isFinite(mixed $value): bool
    {
        if (is_float($value)) {
            return is_finite($value);
        }
        if ($value instanceof JsonObject || is_array($value)) {
            foreach ($value as $item) {
                if (!self::isFinite($item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
