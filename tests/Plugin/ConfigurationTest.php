<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Operation\Shape;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Primitives\ErrorCode;
use XiHan\Framework\Primitives\Internal\Wire;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\RawJson;

/**
 * 配置节的类型化读取（lifecycle.md §7.2）：成员名按 ASCII 不区分大小写匹配，字符串叶子读成数字时按 JSON 数字的文法，
 * 读成布尔时只认 true、false（ASCII 不区分大小写）。配置节取 .NET 宿主写出的样子：叶子都是字符串，连续整数子项是数组。
 * 按键路径逐个读（value()、string()、int()、float()、bool()）与按形状整节读（decode()）是同一套规则。
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

    public function testFloatsAreWrittenAsTheShortestRoundTripText(): void
    {
        $configuration = self::configuration('{"a":0.1,"b":2.0,"c":1e25,"d":-0.0,"e":1e400,"f":-1e400}');
        // 写法不随进程的 serialize_precision 变化：17 位有效数字时 0.1 会写成 0.10000000000000001
        $precision = ini_set('serialize_precision', '17');
        try {
            self::assertSame('0.1', $configuration->string('a'));
            self::assertSame('2.0', $configuration->string('b'));
            self::assertSame('1.0e+25', $configuration->string('c'));
            self::assertSame('-0.0', $configuration->string('d'));
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
        self::assertSame('0.1', $configuration->string('a'));
        // 超出双精度的数按值模型读成无穷，写成与线上编码相同的文本
        self::assertSame('Infinity', $configuration->string('e'));
        self::assertSame('-Infinity', $configuration->string('f'));
    }

    public function testDecodeReadsTheSectionByShape(): void
    {
        // .NET 宿主写出的样子：叶子都是字符串，成员名的大小写随配置源
        $configuration = self::configuration(
            '{"Greeting":"hi","RETRY":{"Count":"3","delay":"0.25","Enabled":"True","Limit":null},'
            . '"Hosts":["a","b"],"Weights":{"a":"1","B":"2.5"},"Ports":["80","443"],"Extra":"ignored","Raw":{"x":"1"}}',
        );
        $shape = Shape::object([
            'greeting' => Shape::string()->required(),
            'retry' => Shape::object([
                'count' => Shape::int32(),
                'delay' => Shape::float(),
                'enabled' => Shape::bool(),
                'limit' => Shape::int64()->nullable(),
            ]),
            'hosts' => Shape::listOf(Shape::string()),
            'weights' => Shape::mapOf(Shape::float()),
            'ports' => Shape::listOf(Shape::int32()),
            'raw' => Shape::any(),
            'missing' => Shape::string(),
        ]);
        $decoded = $configuration->decode($shape);
        // 键是形状里声明的成员名，按配置节的文本顺序；没有声明的成员忽略，没有出现的成员没有键
        self::assertSame(['greeting', 'retry', 'hosts', 'weights', 'ports', 'raw'], array_keys($decoded));
        self::assertSame('hi', $decoded['greeting']);
        self::assertSame(['count' => 3, 'delay' => 0.25, 'enabled' => true, 'limit' => null], $decoded['retry']);
        self::assertSame(['a', 'b'], $decoded['hosts']);
        // 字典的键不是成员名：原样保留
        self::assertSame(['a' => 1.0, 'B' => 2.5], $decoded['weights']);
        self::assertSame([80, 443], $decoded['ports']);
        $raw = $decoded['raw'];
        self::assertInstanceOf(RawJson::class, $raw);
        self::assertSame('{"x":"1"}', $raw->json);

        // JSON 的数字与布尔照常读；字符串写的数字按 JSON 数字的文法，布尔只认 true 与 false
        $typed = self::configuration('{"count":3,"delay":2,"enabled":false,"limit":"-9223372036854775808"}')->decode(
            Shape::object([
                'count' => Shape::int32(),
                'delay' => Shape::float(),
                'enabled' => Shape::bool(),
                'limit' => Shape::int64(),
            ]),
        );
        self::assertSame(['count' => 3, 'delay' => 2.0, 'enabled' => false, 'limit' => PHP_INT_MIN], $typed);
        self::assertSame(
            ['a' => 1000.0, 'b' => INF, 'c' => -0.5],
            self::configuration('{"a":"1e3","b":"1e400","c":"-0.5"}')->decode(Shape::mapOf(Shape::float())),
        );
        self::assertSame([], self::configuration('{}')->decode(Shape::object(['name' => Shape::string()])));
    }

    public function testDecodeMatchesMemberNamesLikeTheKeyedReads(): void
    {
        $shape = Shape::object(['name' => Shape::string()]);
        // 逐字相同的成员优先，其次取文本里靠前的那个，与 value() 一致；只折叠 ASCII 字母
        self::assertSame(['name' => 'exact'], self::configuration('{"Name":"first","name":"exact","NAME":"last"}')->decode($shape));
        self::assertSame(['name' => 'first'], self::configuration('{"NAME":"first","Name":"second"}')->decode($shape));
        self::assertSame([], self::configuration('{"ɴame":"x","ｎame":"y"}')->decode($shape));
        $kelvin = Shape::object(['k' => Shape::string()]);
        self::assertSame([], self::configuration("{\"\u{212A}\":\"kelvin sign\"}")->decode($kelvin));
        self::assertSame(['k' => 'ascii'], self::configuration('{"K":"ascii"}')->decode($kelvin));
    }

    public function testDecodeReportsWhereTheSectionDoesNotMatch(): void
    {
        $shape = Shape::object([
            'retry' => Shape::object(['count' => Shape::int32(), 'enabled' => Shape::bool(), 'delay' => Shape::float()]),
            'name' => Shape::string(),
            'ports' => Shape::listOf(Shape::int64()),
            'greeting' => Shape::string(),
        ]);
        // 路径里的成员名照配置节里的写法；带小数或指数的整数、越界的整数、不合 JSON 数字文法的文本、yes 这类写法都读不成
        $cases = [
            '{"Retry":{"Count":"1.5"}}' => '$.Retry.Count',
            '{"Retry":{"Count":"1e2"}}' => '$.Retry.Count',
            '{"Retry":{"Count":"2147483648"}}' => '$.Retry.Count',
            '{"Retry":{"Count":" 1"}}' => '$.Retry.Count',
            '{"Retry":{"Count":"01"}}' => '$.Retry.Count',
            '{"Retry":{"Count":""}}' => '$.Retry.Count',
            '{"Retry":{"Count":null}}' => '$.Retry.Count',
            '{"Retry":{"Enabled":"yes"}}' => '$.Retry.Enabled',
            '{"Retry":{"Enabled":"1"}}' => '$.Retry.Enabled',
            '{"Retry":{"Delay":"0x10"}}' => '$.Retry.Delay',
            '{"Retry":{"Delay":"NaN"}}' => '$.Retry.Delay',
            '{"Retry":"3"}' => '$.Retry',
            '{"name":5}' => '$.name',
            '{"Ports":["1","x"]}' => '$.Ports[1]',
            '{"Ports":["9223372036854775808"]}' => '$.Ports[0]',
            '{"Greeting":"hi","PORTS":"80"}' => '$.PORTS',
        ];
        foreach ($cases as $json => $path) {
            self::assertSame($path, self::mismatch(self::configuration($json), $shape), $json);
        }
        // 必填成员缺少时报所在对象的路径；值为 null 也算出现
        $required = Shape::object(['greeting' => Shape::string()->required()]);
        self::assertSame('$', self::mismatch(self::configuration('{"other":"x"}'), $required));
        self::assertSame(['greeting' => null], self::configuration('{"GREETING":null}')->decode($required));

        // 形状的根必须是对象或字典：这是编程错误，不是配置节的问题
        try {
            self::configuration('{}')->decode(Shape::listOf(Shape::string()));
            self::fail('a shape that is not an object or a map must be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The shape of a configuration section must be an object or a map.', $exception->getMessage());
        }
    }

    /**
     * 按形状读配置节，返回不合处的路径。
     */
    private static function mismatch(Configuration $configuration, Shape $shape): string
    {
        try {
            $configuration->decode($shape);
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $exception->errorCode());
            $prefix = "The configuration section does not match the shape at '";
            self::assertStringStartsWith($prefix, $exception->getMessage());
            self::assertStringEndsWith("'.", $exception->getMessage());

            return substr($exception->getMessage(), strlen($prefix), -2);
        }
        self::fail('the section must not match the shape');
    }

    private static function configuration(string $json): Configuration
    {
        $section = Wire::decode($json, 'configuration');
        self::assertInstanceOf(JsonObject::class, $section);

        return new Configuration($section);
    }
}
