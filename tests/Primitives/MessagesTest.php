<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Primitives;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\LocaleName;
use XiHan\Framework\Primitives\MessageCatalog;
use XiHan\Framework\Primitives\MessageScope;
use XiHan\Framework\Primitives\MessageTemplate;

/**
 * 消息键文法、模板格式化、描述与消息目录。
 */
final class MessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        MessageScope::setDefault(null, LocaleName::DEFAULT);
    }

    public function testMessageKeyGrammar(): void
    {
        foreach (['kernel.invocation.cancelled', 'tenant_members.list.description', 'a.b', 'a1.b_2'] as $key) {
            self::assertTrue(MessageTemplate::isValidKey($key), $key);
        }
        $invalid = ['', 'kernel', 'Kernel.x', 'kernel.X', 'kernel..x', '1kernel.x', 'kernel.1x', 'kernel.x.', "a.b\n"];
        foreach ($invalid as $key) {
            self::assertFalse(MessageTemplate::isValidKey($key), $key);
        }
        self::assertSame('tenant_members', MessageTemplate::domainOf('tenant_members.list.description'));
        self::assertNull(MessageTemplate::domainOf('bad'));
        self::assertFalse(MessageTemplate::isValidKey('a.' . str_repeat('b', 127)), 'keys are at most 128 characters');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The message key 'bad' must match <domain>.<segment>[.<segment>] with at most 128 characters.",
        );
        MessageTemplate::validateKey('bad');
    }

    public function testFormat(): void
    {
        self::assertSame('a of 007', MessageTemplate::format('{0} of {count:D3}', ['a', 'count' => 7]));
        self::assertSame('a of 007', MessageTemplate::format('{0} of {count:D3}', ['0' => 'a', 'count' => 7]));
        self::assertSame('x and y', MessageTemplate::format('{0} and {1}', MessageTemplate::positional('x', 'y')));
        self::assertSame('{missing}', MessageTemplate::format('{missing}', []), 'missing placeholders stay');
        self::assertSame('{x} is 1', MessageTemplate::format('{{x}} is {x}', ['x' => 1]), 'doubled braces escape');
        self::assertSame('1', MessageTemplate::format('{v:%v}', ['v' => 1]), 'an unknown format prints plainly');
        self::assertSame('2.00 N2', MessageTemplate::format('{amount:N2} N2', ['amount' => 2]));
        self::assertSame('true false  1.5', MessageTemplate::format('{a} {b} {c} {d}', [
            'a' => true, 'b' => false, 'c' => null, 'd' => 1.5,
        ]));
        self::assertSame('{_x}', MessageTemplate::format('{_x}', ['_x' => 1]), 'names start with a letter');
        self::assertNull(MessageTemplate::tryFormat('{a} {b}', ['a' => 1]));
        self::assertSame('1 2', MessageTemplate::tryFormat('{a} {b}', ['a' => 1, 'b' => 2]));
    }

    public function testPlaceholdersAndDescriptions(): void
    {
        self::assertSame(['id', '0', 'count'], MessageTemplate::placeholdersOf('{id} {0} {count:D2} {id} {{escaped}} {"a":1}'));
        self::assertSame('kernel.feature.start_failed', MessageTemplate::describe('kernel.feature.start_failed', []));
        self::assertSame('kernel.feature.start_failed', MessageTemplate::describe('kernel.feature.start_failed', null));
        self::assertSame(
            'kernel.feature.start_failed (reason=y, id=x)',
            MessageTemplate::describe('kernel.feature.start_failed', ['reason' => 'y', 'id' => 'x']),
            'arguments keep the order they were given in',
        );
        self::assertSame('a.b (10=z, 0=x)', MessageTemplate::describe('a.b', [10 => 'z', 0 => 'x']));
        self::assertSame('a.b (list=[1,2], none=)', MessageTemplate::describe('a.b', ['list' => [1, 2], 'none' => null]));
    }

    public function testCatalogResolvesWithFallbackToEnglish(): void
    {
        $catalog = new MessageCatalog([
            'en-US' => ['orders.missing' => 'Order {id} was not found.', 'orders.english' => 'Only English.'],
            'zh-CN' => ['orders.missing' => '订单 {id} 不存在。'],
        ]);
        self::assertSame('订单 1 不存在。', $catalog->resolve('orders.missing', 'zh-CN', ['id' => 1]));
        self::assertSame('订单 1 不存在。', $catalog->resolve('orders.missing', 'ZH-cn', ['id' => 1]), 'case-insensitive');
        self::assertSame('Only English.', $catalog->resolve('orders.english', 'zh-CN', []));
        self::assertSame('Order 1 was not found.', $catalog->resolve('orders.missing', 'ja-JP', ['id' => 1]));
        self::assertNull($catalog->resolve('orders.unknown', 'en-US', []));
        self::assertNull($catalog->resolve('orders.missing', 'en-US', []), 'a missing argument fails the resolution');

        MessageScope::setDefault($catalog, 'zh-CN');
        self::assertSame('zh-CN', MessageScope::defaultLocale());
        self::assertSame('订单 2 不存在。', MessageScope::resolve('orders.missing', ['id' => 2]));
    }

    public function testCatalogAndScopeRejectInvalidInput(): void
    {
        $cases = [
            'bad locale' => static fn (): mixed => new MessageCatalog(['english' => ['a.b' => 'x']]),
            'duplicate locale' => static fn (): mixed => new MessageCatalog(['en-US' => [], 'en-us' => []]),
            'bad key' => static fn (): mixed => new MessageCatalog(['en-US' => ['Bad' => 'x']]),
            'bad template' => static fn (): mixed => new MessageCatalog(['en-US' => ['a.b' => 1]]),
            'bad entries' => static fn (): mixed => new MessageCatalog(['en-US' => 'x']),
            'bad default locale' => static function (): mixed {
                MessageScope::setDefault(null, 'en_US');

                return null;
            },
        ];
        foreach ($cases as $name => $create) {
            try {
                $create();
                self::fail(sprintf('%s must be rejected', $name));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertTrue(LocaleName::isValid('zh-Hant-TW'));
        self::assertFalse(LocaleName::isValid('en-US-' . str_repeat('a', 30)));
    }
}
