<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\InvalidArgumentException;

/**
 * CloudEvents 1.0 信封的平铺（RFC-0009「信封」，plugin.proto 的 Envelope）。
 *
 * 投递给插件的信封原样交给处理器；插件发布的信封经 Host::publish() 交给宿主的消息代理。
 *
 * `time` 是时刻（精确到微秒），没有时为 null。发布时换算成 UTC 写成 RFC 3339：以 `Z` 结尾，秒的小数去掉末尾的 0，全为 0 时
 * 不写小数点，最多六位，如 `2026-11-25T03:25:00.5Z`；SDK 不在本地核对（ADR-0306），0001–9999 年之外的时刻照写，由宿主以
 * `messaging.envelope.invalid_time` 拒绝那一项。收到的信封里是 UTC 的 DateTimeImmutable：宿主写来的文本按 RFC-0003 规则 8 读
 * （invocation.md §4），比微秒更细的小数截断、不进位。`dataSchema` 是 CloudEvents 的 `dataschema`（ADR-0305），收到与发布都
 * 原样，SDK 不核对、不改写；空串是没有。
 */
final readonly class Envelope
{
    /**
     * 承载租户的扩展属性名（RFC-0009「信封」，对应 Baggage 的 xihan.tenant）。
     *
     * Host::publish() 的信封带它时宿主逐项核对：只能是插件此刻正在处理的调用或投递的租户（invocation.md §5）。
     */
    public const string EXTENSION_TENANT = 'xihantenant';

    /** @var array<array-key, string> 扩展属性，如 xihantenant、traceparent；PHP 把纯数字的名字存成整数键 */
    public array $extensions;

    /** `time`；没有时为 null。构造时复制成 DateTimeImmutable，之后改动传入的 DateTime 不影响信封 */
    public ?\DateTimeImmutable $time;

    /**
     * @param string $topic 主题
     * @param string $id CloudEvents `id`
     * @param string $source `source`
     * @param string $type `type`
     * @param string $data `data` 的字节
     * @param string $dataContentType `datacontenttype`
     * @param string $subject `subject`；可空
     * @param \DateTimeInterface|null $time `time`；没有为 null，写出方式见类的说明
     * @param string $specVersion `specversion`
     * @param array<array-key, mixed> $extensions 扩展属性：名字 → 字符串值
     * @param string $dataSchema `dataschema`（ADR-0305）；可空，原样发布
     *
     * @throws InvalidArgumentException 扩展属性的值不是字符串（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(
        public string $topic,
        public string $id,
        public string $source,
        public string $type,
        public string $data = '',
        public string $dataContentType = 'application/json',
        public string $subject = '',
        ?\DateTimeInterface $time = null,
        public string $specVersion = '1.0',
        array $extensions = [],
        public string $dataSchema = '',
    ) {
        $checked = [];
        foreach ($extensions as $name => $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    sprintf("The extension '%s' of an envelope must be a string.", $name),
                );
            }
            $checked[$name] = $value;
        }
        $this->extensions = $checked;
        $this->time = $time === null ? null : \DateTimeImmutable::createFromInterface($time);
    }
}
