<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin;

use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageTemplate;

/**
 * 插件运行时的消息键、内置的 en-US 模板与 10 种区域的消息目录（invocation.md §8，RFC-0010）。
 *
 * 键、参数、错误码与 en-US 模板以规范的向量 conformance/vectors/plugin/plugin-messages.json 为准，参数就是错误的 details 的
 * 成员。宿主自己有这些键的目录（目录名 plugins.protocol），握手不发送这份目录（handshake.md §3 第 6 条），它只供本地渲染。
 * 各区域的模板在 `Localization/<区域>.json`，en-US 与这里的模板逐字相同。
 */
final class PluginMessages
{
    /** 目录名。 */
    public const string CATALOG_NAME = 'plugins.protocol';

    /** 不在可服务状态时到达的调用与投递（PLUGINS_UNAVAILABLE_001）；参数 id、state。 */
    public const string UNAVAILABLE = 'plugins.process.unavailable';

    /** 回调抛出、配置节不是对象、对方写来的错误不合法、没有对应的 gRPC 状态（PLUGINS_UNEXPECTED_001）；参数 id、phase、reason。 */
    public const string FAILED = 'plugins.process.failed';

    /** 投递找不到（主题，方式，类型）匹配的订阅（KERNEL_NOTFOUND_001）；参数 id、topic、type、mode。 */
    public const string EVENT_UNSUBSCRIBED = 'plugins.event.unsubscribed';

    /** 回调连不上宿主或没有可用的回调端点（PLUGINS_UNAVAILABLE_001）；参数 id、reason。 */
    public const string HOST_UNAVAILABLE = 'plugins.host.unavailable';

    /** 回调所需的权限未在清单声明或未被授予（PLUGINS_DENIED_002）；参数 id、permission。 */
    public const string CALLBACK_DENIED = 'plugins.callback.denied';

    /** 回调带的租户不是宿主随调用交给插件的租户（PLUGINS_DENIED_002）；参数 id、tenant。 */
    public const string CALLBACK_TENANT_DENIED = 'plugins.callback.tenant_denied';

    /** 宿主拒绝了回调的会话令牌（PLUGINS_DENIED_002）；没有参数。 */
    public const string SESSION_INVALID = 'plugins.callback.session_invalid';

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
        self::EVENT_UNSUBSCRIBED => "Plugin '{id}' has no subscription for topic '{topic}', type '{type}' and mode "
            . "'{mode}'.",
        self::HOST_UNAVAILABLE => "Plugin '{id}' cannot reach the host: {reason}",
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
     * 返回 10 种区域的消息目录；应用可把它与自己的目录一起交给 MessageScope，在本地按区域渲染运行时的错误。
     *
     * @throws InvalidArgumentException 语言文件缺失或不合法（随包发布的文件，不该发生）
     */
    public static function catalog(): MessageCatalog
    {
        return self::$catalog ??= new MessageCatalog(self::templates());
    }
}
