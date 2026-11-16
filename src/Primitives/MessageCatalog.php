<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives;

/**
 * 内存里的消息目录：区域 → 消息键 → 模板（RFC-0010「语言文件」）。
 *
 * 区域名比较不区分大小写；请求的区域没有该键时回退到 en-US。
 */
final readonly class MessageCatalog implements MessageResolver
{
    /** @var array<string, array<string, string>> 按小写区域名索引 */
    private array $templates;

    /**
     * 创建目录。
     *
     * 内容通常来自 `json_decode` 读出的语言文件，所以逐项核对类型。
     *
     * @param array<array-key, mixed> $templates 区域 → 消息键 → 模板，与 `Localization/<区域>.json` 的内容同形
     *
     * @throws InvalidArgumentException 区域名、消息键或模板不合法，或两个区域名只有大小写不同（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(array $templates)
    {
        $normalized = [];
        foreach ($templates as $locale => $entries) {
            $locale = (string) $locale;
            LocaleName::validate($locale);
            $lower = strtolower($locale);
            if (array_key_exists($lower, $normalized)) {
                throw new InvalidArgumentException(
                    sprintf("The message catalog has the locale '%s' more than once (case-insensitively).", $locale),
                );
            }
            if (!is_array($entries)) {
                throw new InvalidArgumentException(
                    sprintf("The templates of the locale '%s' must be an object of message keys.", $locale),
                );
            }
            $checked = [];
            foreach ($entries as $key => $template) {
                // 消息键至少两段、含点，不会被 PHP 当成整数键
                $key = MessageTemplate::validateKey((string) $key);
                if (!is_string($template)) {
                    throw new InvalidArgumentException(
                        sprintf("The template of '%s' in the locale '%s' must be a string.", $key, $locale),
                    );
                }
                $checked[$key] = $template;
            }
            $normalized[$lower] = $checked;
        }
        $this->templates = $normalized;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $key, string $locale, array $arguments): ?string
    {
        $lower = strtolower($locale);
        $template = $this->templates[$lower][$key] ?? null;
        if ($template === null && $lower !== strtolower(LocaleName::DEFAULT)) {
            $template = $this->templates[strtolower(LocaleName::DEFAULT)][$key] ?? null;
        }

        // 数字按请求的区域写小数点与分组符，回退到 en-US 的模板也一样
        return $template === null ? null : MessageTemplate::tryFormat($template, $arguments, $locale);
    }
}
