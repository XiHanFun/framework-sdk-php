<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\PluginMessages;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\LifecycleState;

/**
 * 插件运行时的消息目录：10 种区域的语言文件齐全，键与占位符一致，en-US 与内置模板逐字相同（RFC-0010）。
 */
final class PluginMessagesTest extends TestCase
{
    public function testEveryLocaleHasTheSameKeysAndPlaceholders(): void
    {
        $english = PluginMessages::englishTemplates();
        $templates = PluginMessages::templates();
        self::assertSame(PluginMessages::LOCALES, array_keys($templates));
        self::assertEquals($english, $templates['en-US']);
        foreach ($templates as $locale => $entries) {
            self::assertSame(array_keys($english), array_keys(self::sorted($english, $entries)), $locale);
            foreach ($entries as $key => $template) {
                self::assertSame(self::placeholders($english[$key]), self::placeholders($template), $locale . ' ' . $key);
                // 不引入不可见字符：U+2028、U+2029、U+202F、U+00A0、U+FEFF
                self::assertSame(0, preg_match('/[\x{2028}\x{2029}\x{202F}\x{00A0}\x{FEFF}]/u', $template), $locale . ' ' . $key);
            }
        }
    }

    public function testTheCatalogResolvesByLocale(): void
    {
        $catalog = PluginMessages::catalog();
        self::assertSame(
            "插件 'p' 不可用（状态：Draining）。",
            $catalog->resolve(PluginMessages::UNAVAILABLE, 'zh-CN', ['id' => 'p', 'state' => 'Draining']),
        );
        self::assertSame(
            "Plugin 'p' failed during start: boom",
            PluginMessages::english(PluginMessages::FAILED, ['id' => 'p', 'phase' => 'start', 'reason' => 'boom']),
        );
        self::assertNull(PluginMessages::english('plugins.unknown.key', []));
    }

    public function testErrorsCarryTheCodesOfRfc0017(): void
    {
        $unavailable = PluginErrors::unavailable('xihan.samples.p', LifecycleState::Draining);
        self::assertSame('PLUGINS_UNAVAILABLE_001', $unavailable->code);
        self::assertSame(['id' => 'xihan.samples.p', 'state' => 'Draining'], $unavailable->details);
        self::assertSame('PLUGINS_DENIED_002', PluginErrors::sessionInvalid()->code);
        self::assertSame('PLUGINS_DENIED_002', PluginErrors::callbackDenied('p', 'capability:kernel.pipeline')->code);
        self::assertSame('PLUGINS_DENIED_002', PluginErrors::tenantDenied('p', 't1')->code);
        self::assertSame('PLUGINS_UNEXPECTED_001', PluginErrors::failed('p', 'start', 'boom')->code);
        $missing = PluginErrors::noSubscription('echo.events', 'x', '');
        self::assertSame('KERNEL_NOTFOUND_001', $missing->code);
        self::assertSame('competing', $missing->details['mode']);
        foreach ([PluginErrors::UNAVAILABLE, PluginErrors::CALLBACK_DENIED, PluginErrors::UNEXPECTED] as $code) {
            self::assertTrue(ErrorCode::isValid($code));
        }
    }

    /**
     * 返回模板里的占位符名，按名字排序。
     *
     * @return list<string>
     */
    private static function placeholders(string $template): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)(?::[^}]*)?\}/', $template, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * 按 en-US 的键顺序排列。
     *
     * @param array<string, string> $english
     * @param array<string, string> $entries
     *
     * @return array<string, string>
     */
    private static function sorted(array $english, array $entries): array
    {
        $sorted = [];
        foreach (array_keys($english) as $key) {
            if (isset($entries[$key])) {
                $sorted[$key] = $entries[$key];
            }
        }

        return count($sorted) === count($entries) ? $sorted : $entries;
    }
}
