<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageTemplate;

/**
 * 插件的一份消息目录：目录名 + 区域 → 消息键 → 模板（RFC-0010）。
 *
 * 握手时交给宿主（handshake.md §3 第 6 条），宿主用它翻译插件返回的错误；目录名与宿主已有的重复时宿主的优先。
 * 至少要有 en-US。
 */
final readonly class Catalog
{
    /** @var array<string, array<string, string>> */
    public array $locales;

    /**
     * @param string $name 目录名，通常取清单 ID 的领域段
     * @param array<array-key, mixed> $locales 区域 → 消息键 → 模板
     *
     * @throws InvalidArgumentException 目录名为空、区域名或消息键不合法、模板不是字符串，或没有 en-US（PRIMITIVES_VALIDATION_001）
     */
    public function __construct(public string $name, array $locales)
    {
        if ($name === '') {
            throw new InvalidArgumentException('A message catalog needs a name.');
        }
        $checked = [];
        foreach ($locales as $locale => $templates) {
            $locale = LocaleName::validate((string) $locale);
            if (!is_array($templates)) {
                throw new InvalidArgumentException(
                    sprintf("The templates of the locale '%s' in the catalog '%s' must be an array.", $locale, $name),
                );
            }
            $entries = [];
            foreach ($templates as $key => $template) {
                $key = MessageTemplate::validateKey((string) $key);
                if (!is_string($template)) {
                    throw new InvalidArgumentException(
                        sprintf("The template of '%s' in the catalog '%s' must be a string.", $key, $name),
                    );
                }
                $entries[$key] = $template;
            }
            ksort($entries, SORT_STRING);
            $checked[$locale] = $entries;
        }
        if (!isset($checked[LocaleName::DEFAULT])) {
            throw new InvalidArgumentException(
                sprintf("The message catalog '%s' must have %s templates.", $name, LocaleName::DEFAULT),
            );
        }
        ksort($checked, SORT_STRING);
        $this->locales = $checked;
    }

    /**
     * 从目录读语言文件：每种区域一个 `<区域>.json`，内容是消息键 → 模板的 JSON 对象；别的文件忽略。
     *
     * @throws InvalidArgumentException 目录读不出、文件不合法或没有 en-US.json（PRIMITIVES_VALIDATION_001）
     */
    public static function fromDirectory(string $name, string $directory): self
    {
        $files = is_dir($directory) ? glob(rtrim($directory, '/\\') . '/*.json') : false;
        if ($files === false) {
            throw new InvalidArgumentException(sprintf("The catalog directory '%s' cannot be read.", $directory));
        }
        $locales = [];
        foreach ($files as $file) {
            $text = file_get_contents($file);
            if ($text === false) {
                throw new InvalidArgumentException(sprintf("The language file '%s' cannot be read.", $file));
            }
            // 语言文件按 JSON 文本的共同检查读：重复的键与 BOM 都不合法
            $locales[basename($file, '.json')] = Wire::members(
                Wire::decode($text, 'language file'),
                sprintf("The language file '%s' must be a JSON object.", $file),
            );
        }

        return new self($name, $locales);
    }

    /**
     * 转成可交给 MessageScope 的目录，让 Error::message() 按区域解析插件自己的消息键。
     */
    public function toMessageCatalog(): MessageCatalog
    {
        return new MessageCatalog($this->locales);
    }
}
