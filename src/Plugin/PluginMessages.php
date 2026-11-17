<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageTemplate;

/**
 * 插件运行时的消息键、内置的 en-US 模板与 10 种区域的消息目录（RFC-0010）。
 *
 * RFC-0017 只列错误码；键、参数与模板与参考实现的插件协议语言文件相同（目录名 plugins.protocol），宿主收到插件的错误时
 * 用自己的同名目录翻译，所以握手不发送这份目录。plugins.event.no_subscription 是本 SDK 的键（投递没有匹配的订阅，
 * invocation.md §4 没有给出码与键）。各区域的模板在 `Localization/<区域>.json`，en-US 与这里的模板逐字相同。
 */
final class PluginMessages
{
    /** 目录名。 */
    public const string CATALOG_NAME = 'plugins.protocol';

    /** 插件未就绪或正在停止（PLUGINS_UNAVAILABLE_001）；参数 id、state。 */
    public const string UNAVAILABLE = 'plugins.process.unavailable';

    /** 某一步失败（PLUGINS_UNEXPECTED_001，回调时宿主不可达为 PLUGINS_UNAVAILABLE_001）；参数 id、phase、reason。 */
    public const string FAILED = 'plugins.process.failed';

    /** 回调所需的权限未在清单声明或未被授予（PLUGINS_DENIED_002）；参数 id、permission。 */
    public const string CALLBACK_DENIED = 'plugins.callback.denied';

    /** 回调或发布带的租户不是宿主随调用交给插件的租户（PLUGINS_DENIED_002）；参数 id、tenant。 */
    public const string CALLBACK_TENANT_DENIED = 'plugins.callback.tenant_denied';

    /** 回调的会话令牌缺失或无效（PLUGINS_DENIED_002）；没有参数。 */
    public const string SESSION_INVALID = 'plugins.callback.session_invalid';

    /** 投递的消息没有以这种方式订阅、类型也匹配的处理器（KERNEL_NOTFOUND_001）；参数 topic、type、mode。 */
    public const string NO_SUBSCRIPTION = 'plugins.event.no_subscription';

    /** 内置的区域，en-US 为默认。 */
    public const array LOCALES = [
        'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'de-DE', 'es-ES', 'fr-FR', 'pt-BR', 'ru-RU',
    ];

    /** en-US 模板 */
    private const array ENGLISH = [
        self::CALLBACK_DENIED => "Plugin '{id}' may not use permission '{permission}': it must be declared in the "
            . 'manifest and granted by the host.',
        self::SESSION_INVALID => 'The plugin session token is missing or invalid.',
        self::CALLBACK_TENANT_DENIED => "Plugin '{id}' may not act in tenant '{tenant}': a callback can only carry "
            . 'the tenant of a call the host has handed to the plugin.',
        self::NO_SUBSCRIPTION => "The plugin has no subscription for topic '{topic}' and type '{type}' delivered as "
            . "'{mode}'.",
        self::FAILED => "Plugin '{id}' failed during {phase}: {reason}",
        self::UNAVAILABLE => "Plugin '{id}' is not available (state: {state}).",
    ];

    private static ?MessageCatalog $catalog = null;

    private function __construct()
    {
    }

    /**
     * 返回全部 en-US 模板：消息键 → 模板。
     *
     * @return array<string, string>
     */
    public static function englishTemplates(): array
    {
        return self::ENGLISH;
    }

    /**
     * 用 en-US 模板严格格式化；不是本目录的键或参数缺值时为 null。
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function english(string $key, array $arguments): ?string
    {
        $template = self::ENGLISH[$key] ?? null;

        return $template === null ? null : MessageTemplate::tryFormat($template, $arguments);
    }

    /**
     * 读出 10 种区域的模板：区域 → 消息键 → 模板。
     *
     * @return array<string, array<string, string>>
     *
     * @throws InvalidArgumentException 语言文件缺失或不合法（随包发布的文件，不该发生）
     */
    public static function templates(): array
    {
        $templates = [];
        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . '/Localization/' . $locale . '.json';
            $text = is_file($path) ? file_get_contents($path) : false;
            if ($text === false) {
                throw new InvalidArgumentException(sprintf("The language file '%s' is missing.", $path));
            }
            $entries = [];
            $members = Wire::members(Wire::decode($text, 'language file'), 'A language file must be an object.');
            foreach ($members as $key => $template) {
                if (!is_string($template)) {
                    throw new InvalidArgumentException(
                        sprintf("The template of '%s' in '%s' must be a string.", $key, $path),
                    );
                }
                $entries[(string) $key] = $template;
            }
            $templates[LocaleName::validate($locale)] = $entries;
        }

        return $templates;
    }

    /**
     * 返回 10 种区域的消息目录；应用可把它与自己的目录一起交给 MessageScope。
     *
     * @throws InvalidArgumentException 语言文件缺失或不合法（随包发布的文件，不该发生）
     */
    public static function catalog(): MessageCatalog
    {
        return self::$catalog ??= new MessageCatalog(self::templates());
    }
}
