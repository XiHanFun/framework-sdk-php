<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\PluginErrors;
use XiHan\Framework\Plugin\PluginMessages;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\LifecycleState;
use XiHan\Framework\Tests\Vectors;

/**
 * 插件运行时的消息键（invocation.md §8）：回放 conformance/vectors/plugin/plugin-messages.json（码、键、参数、en-US 模板与
 * 固定的 reason），10 种区域的语言文件齐全，键与占位符一致，en-US 与内置模板逐字相同（RFC-0010）。
 */
final class PluginMessagesTest extends TestCase
{
    public function testKeysArgumentsCodesAndTemplatesFollowTheVector(): void
    {
        $vector = Vectors::object('plugin/plugin-messages');
        self::assertIsArray($vector->messages);
        $english = PluginMessages::englishTemplates();
        $errors = self::errors();
        $keys = [];
        foreach ($vector->messages as $message) {
            self::assertInstanceOf(\stdClass::class, $message);
            self::assertIsString($message->key);
            self::assertIsArray($message->arguments);
            $keys[] = $message->key;
            self::assertSame($message->template, $english[$message->key] ?? null, $message->key);
            // 错误的 details 就是模板的参数，不另加成员
            $error = $errors[$message->key] ?? self::fail('no factory for ' . $message->key);
            self::assertSame($message->code, $error->code, $message->key);
            self::assertSame($message->key, $error->messageKey);
            self::assertSame($message->arguments, array_keys($error->details), $message->key);
        }
        // 不在规范里的键一个也没有
        $sorted = $keys;
        sort($sorted);
        $own = array_keys($english);
        sort($own);
        self::assertSame($sorted, $own);
    }

    public function testFixedReasonsFollowTheVector(): void
    {
        $vector = Vectors::object('plugin/plugin-messages');
        self::assertIsArray($vector->reasons);
        $reasons = [];
        foreach ($vector->reasons as $reason) {
            self::assertInstanceOf(\stdClass::class, $reason);
            self::assertIsString($reason->reason);
            $reasons[] = $reason->reason;
        }
        foreach ([PluginErrors::REASON_NOT_AN_OBJECT, PluginErrors::REASON_NO_ENDPOINT, PluginErrors::REASON_NO_ERROR] as $reason) {
            self::assertContains($reason, $reasons);
        }
        self::assertContains("invalid error code '{code}'", $reasons);
        self::assertContains('the host returned {results} results for {envelopes} envelopes', $reasons);
    }

    public function testEveryLocaleHasTheSameKeysAndPlaceholders(): void
    {
        $english = PluginMessages::englishTemplates();
        $templates = PluginMessages::templates();
        self::assertSame(PluginMessages::LOCALES, array_keys($templates));
        self::assertEquals($english, $templates['en-US']);
        $keys = array_keys($english);
        sort($keys);
        foreach ($templates as $locale => $entries) {
            $own = array_keys($entries);
            sort($own);
            self::assertSame($keys, $own, $locale);
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
            "插件 'p' 没有主题 'a.b'、类型 't'、方式 'competing' 的订阅。",
            $catalog->resolve(PluginMessages::EVENT_UNSUBSCRIBED, 'zh-CN', ['id' => 'p', 'topic' => 'a.b', 'type' => 't', 'mode' => 'competing']),
        );
        self::assertSame(
            "Plugin 'p' cannot reach the host: no supported callback endpoint",
            PluginMessages::english(PluginMessages::HOST_UNAVAILABLE, ['id' => 'p', 'reason' => PluginErrors::REASON_NO_ENDPOINT]),
        );
        self::assertNull(PluginMessages::english('plugins.unknown.key', []));
    }

    public function testEmptyModesAreWrittenAsCompeting(): void
    {
        self::assertSame('competing', PluginErrors::unsubscribed('p', 'a.b', 't', '')->details['mode']);
        self::assertSame('broadcast', PluginErrors::unsubscribed('p', 'a.b', 't', 'broadcast')->details['mode']);
        foreach ([PluginErrors::UNAVAILABLE, PluginErrors::CALLBACK_DENIED, PluginErrors::UNEXPECTED, PluginErrors::UNSUBSCRIBED] as $code) {
            self::assertTrue(ErrorCode::isValid($code));
        }
    }

    /**
     * 每个键由运行时的哪个工厂方法产生。
     *
     * @return array<string, Error>
     */
    private static function errors(): array
    {
        return [
            PluginMessages::UNAVAILABLE => PluginErrors::unavailable('p', LifecycleState::Configured),
            PluginMessages::FAILED => PluginErrors::failed('p', 'configure', PluginErrors::REASON_NOT_AN_OBJECT),
            PluginMessages::EVENT_UNSUBSCRIBED => PluginErrors::unsubscribed('p', 'a.b', 't', ''),
            PluginMessages::HOST_UNAVAILABLE => PluginErrors::hostUnavailable('p', PluginErrors::REASON_NO_ENDPOINT),
            PluginMessages::CALLBACK_DENIED => PluginErrors::callbackDenied('p', 'capability:kernel.pipeline'),
            PluginMessages::CALLBACK_TENANT_DENIED => PluginErrors::tenantDenied('p', 't1'),
            PluginMessages::SESSION_INVALID => PluginErrors::sessionInvalid(),
        ];
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
}
