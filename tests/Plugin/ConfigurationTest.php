<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;

/**
 * 配置节的类型化读取（lifecycle.md §7.2）：成员名按 ASCII 不区分大小写匹配，字符串叶子读成数字时按 JSON 数字的文法，
 * 读成布尔时只认 true、false（ASCII 不区分大小写）。配置节取 .NET 宿主写出的样子：叶子都是字符串，连续整数子项是数组。
 */
final class ConfigurationTest extends TestCase
{
    public function testMembersAreMatchedCaseInsensitively(): void
    {
        $configuration = self::configuration('{"Retry":{"Count":"3","Delay":"0.25","Enabled":"TRUE"},"Hosts":["a","b"],"Name":"echo","name":"later","Empty":null}');
        self::assertSame('3', $configuration->string('retry:count'));
        self::assertSame(3, $configuration->int('RETRY:COUNT'));
        self::assertSame(0.25, $configuration->float('Retry:Delay'));
        self::assertTrue($configuration->bool('retry:enabled'));
        self::assertSame('b', $configuration->string('hosts:1'));
        // 逐字相同的成员优先，其次取文本里靠前的那个
        self::assertSame('later', $configuration->string('name'));
        self::assertSame('echo', $configuration->string('NAME'));
        self::assertNull($configuration->string('Empty'));
        self::assertNull($configuration->int('Retry:Missing'));
        self::assertNull($configuration->value('Hosts:2'));
        self::assertNull($configuration->value('Hosts:01'));
        self::assertInstanceOf(JsonObject::class, $configuration->value('retry'));
    }

    public function testStringLeavesAreReadAsNumbersAndBooleans(): void
    {
        $configuration = self::configuration('{"a":"-12","b":"1e3","c":"1.5","d":"01","e":" 1","f":"0x10","g":"false","h":"yes","i":7,"j":true,"k":2.0,"l":"9223372036854775808"}');
        self::assertSame(-12, $configuration->int('a'));
        self::assertSame(1000.0, $configuration->float('b'));
        self::assertSame(1.5, $configuration->float('c'));
        self::assertSame(7, $configuration->int('i'));
        self::assertSame(7.0, $configuration->float('i'));
        self::assertFalse($configuration->bool('g'));
        self::assertTrue($configuration->bool('j'));
        // 数字与布尔叶子可以读成字符串
        self::assertSame('7', $configuration->string('i'));
        self::assertSame('true', $configuration->string('j'));
        self::assertSame('2.0', $configuration->string('k'));
        // 不合 JSON 数字文法、带小数或指数的整数、越界的整数、yes 这类写法都读不成
        foreach ([['int', 'b'], ['int', 'c'], ['int', 'd'], ['int', 'e'], ['float', 'f'], ['bool', 'h'], ['int', 'l'], ['string', 'missing:deeper']] as [$type, $key]) {
            if ($key === 'missing:deeper') {
                self::assertNull($configuration->string($key));
                continue;
            }
            try {
                $configuration->{$type}($key);
                self::fail(sprintf("'%s' must not be read as %s", $key, $type));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString("'" . $key . "'", $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        self::configuration('{"o":{"x":"1"}}')->string('o');
    }

    private static function configuration(string $json): Configuration
    {
        $section = Wire::decode($json, 'configuration');
        self::assertInstanceOf(JsonObject::class, $section);

        return new Configuration($section);
    }
}
