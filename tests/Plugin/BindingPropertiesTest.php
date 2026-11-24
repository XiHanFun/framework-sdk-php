<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Configuration;
use XiHan\Framework\Plugin\Hooks;
use XiHan\Framework\Plugin\Options;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Plugin\ResolvedBinding;
use XiHan\Framework\Primitives\Error;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Primitives\JsonObject;
use XiHan\Framework\Primitives\Result;
use XiHan\Framework\Primitives\Unit;
use XiHan\Framework\Tests\Vectors;

/**
 * 绑定连同属性交给作者（lifecycle.md §2、§7.2，ADR-0285）：回放 conformance/vectors/plugin/binding-properties.json 的
 * reading，并核对绑定类型的调试输出与运行时自己的输出不带主值与属性值。
 *
 * 同一文件的 json（Wasm 载体的 proto3 JSON 文本）、names、limits 与 delivery 是宿主一侧的，SDK 不回放。
 */
final class BindingPropertiesTest extends TestCase
{
    private const string MANIFEST = <<<'JSON'
        {
          "id": "xihan.tests.bindings",
          "version": "1.0.0",
          "primitives": "1",
          "stability": "experimental",
          "kind": "plugin",
          "isolation": "out-of-process",
          "entry": "bindings"
        }
        JSON;

    /** 测试用的主值：够特别，在输出里搜得出来 */
    private const string VALUE = 'postgres://main-value-0f3a/app';

    /** 测试用的属性值 */
    private const string PASSWORD = 'property-value-7c1e';

    /** 测试用的另一个属性值 */
    private const string TOKEN = 'property-value-9b2d';

    public function testTheAuthorReceivesTheBindingsTheHostWrote(): void
    {
        // reading：hex 是宿主写出的 ConfigureRequest 字节，原样发给运行时，由它用生成的协议代码解码后交给配置回调；
        // 作者拿到的绑定逐项等于期望（名字、类型、主值、属性，含先后；没有属性时是空表）
        $vectors = Vectors::object('plugin/binding-properties');
        self::assertIsArray($vectors->reading);
        self::assertNotSame([], $vectors->reading, 'the vectors must have reading cases');
        foreach ($vectors->reading as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->name);
            self::assertIsString($case->hex);
            self::assertIsArray($case->bindings);
            $bytes = hex2bin($case->hex);
            self::assertIsString($bytes, $case->name);
            // 生成的消息类型读得出宿主写的字节
            (new V1\ConfigureRequest())->mergeFromString($bytes);

            $received = null;
            $harness = new Harness();
            $harness->serve(self::options($harness, static function (Configuration $configuration) use (&$received): Result {
                $received = $configuration->bindings;

                return Result::success();
            }));
            try {
                $harness->handshake();
                $response = new V1\ConfigureResponse();
                $response->mergeFromString($harness->client()->unary('/xihan.plugin.v1.Plugin/Configure', $bytes));
                self::assertTrue($response->getStatus()?->getOk(), $case->name);
            } finally {
                $harness->close();
            }

            self::assertIsArray($received, $case->name);
            self::assertCount(count($case->bindings), $received, $case->name);
            foreach ($case->bindings as $index => $expected) {
                self::assertInstanceOf(\stdClass::class, $expected);
                self::assertInstanceOf(\stdClass::class, $expected->properties);
                $binding = $received[$index] ?? null;
                self::assertInstanceOf(ResolvedBinding::class, $binding);
                $which = sprintf('%s: binding %d', $case->name, $index);
                self::assertSame($expected->name, $binding->name, $which);
                self::assertSame($expected->type, $binding->type, $which);
                self::assertSame($expected->value, $binding->value, $which);
                // 属性名按字符串比较：PHP 的数组把纯数字的名字存成整数键，先后没有含义
                $wanted = self::pairs(get_object_vars($expected->properties));
                self::assertSame($wanted, self::pairs($binding->properties), $which);
                self::assertSame(array_column($wanted, 0), self::sorted($binding->propertyNames()), $which);
                foreach ($wanted as [$name, $value]) {
                    self::assertTrue($binding->hasProperty($name), $which . ' ' . $name);
                    self::assertSame($value, $binding->property($name), $which . ' ' . $name);
                }
            }
        }
    }

    public function testPropertyNamesAreKeptAsTheyCame(): void
    {
        // 走运行时的配置路径：只差大小写的是两项，纯数字的、带点的名字照旧，值为空的属性在表里；主值为空不是错误
        $properties = ['accessKey' => 'a', 'AccessKey' => 'b', '0' => 'zero', '8080' => 'port', '007' => 'padded', 'tls.crt' => ''];
        $received = self::configure([
            (new V1\Binding())->setName('db')->setType('connection:relational')->setProperties($properties),
            (new V1\Binding())->setName('cache')->setType('cache:distributed')->setValue('redis.internal:6379'),
        ])['bindings'];
        self::assertCount(2, $received);
        [$binding, $plain] = $received;

        self::assertSame('', $binding->value, 'an empty value is not an error');
        self::assertSame(self::pairs($properties), self::pairs($binding->properties));
        // assertSame 连类型一起比：纯数字的属性名也是字符串
        self::assertSame(['0', '007', '8080', 'AccessKey', 'accessKey', 'tls.crt'], self::sorted($binding->propertyNames()));
        self::assertSame('a', $binding->property('accessKey'));
        self::assertSame('b', $binding->property('AccessKey'));
        self::assertNull($binding->property('accesskey'), 'names are case-sensitive');
        self::assertSame('zero', $binding->property('0'));
        self::assertSame('port', $binding->property('8080'));
        self::assertSame('padded', $binding->property('007'));
        self::assertNull($binding->property('7'), "'007' and '7' are different names");
        self::assertSame('', $binding->property('tls.crt'), 'a property with an empty value stays in the table');
        self::assertTrue($binding->hasProperty('tls.crt'));
        self::assertTrue($binding->hasProperty('8080'));
        self::assertFalse($binding->hasProperty('missing'));
        self::assertNull($binding->property('missing'));

        // 没有属性：空表，不是 null
        self::assertSame([], $plain->properties);
        self::assertSame([], $plain->propertyNames());
        self::assertSame('redis.internal:6379', $plain->value);
    }

    public function testBindingsCanStillBeCreatedWithoutProperties(): void
    {
        // 原来三个参数的构造照旧可用
        $plain = new ResolvedBinding('cache', 'cache:distributed', 'redis.internal:6379');
        self::assertSame([], $plain->properties);
        self::assertEquals(new ResolvedBinding('cache', 'cache:distributed', 'redis.internal:6379', []), $plain);

        // 交出的是拷贝：之后改传入的数组不影响绑定，绑定的属性改不动
        $source = ['password' => self::PASSWORD];
        $binding = new ResolvedBinding('db', 'connection:relational', '', $source);
        $source['password'] = 'changed';
        $source['late'] = 'x';
        self::assertSame(['password' => self::PASSWORD], $binding->properties);
        $copy = $binding->properties;
        $copy['password'] = 'changed';
        self::assertSame(self::PASSWORD, $binding->property('password'));
        self::assertTrue((new \ReflectionProperty(ResolvedBinding::class, 'properties'))->isReadOnly());

        // 属性值必须是字符串；消息只带绑定名与属性名
        try {
            new ResolvedBinding('db', 'connection:relational', self::VALUE, ['port' => 5432, 'password' => self::PASSWORD]);
            self::fail('a property value that is not a string must be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("The property 'port' of the binding 'db' must be a string.", $exception->getMessage());
            // 主值与属性是敏感参数：堆栈里是 SensitiveParameterValue，不是原值
            self::assertNoSecrets($exception->getTraceAsString(), 'the stack trace');
            $arguments = $exception->getTrace()[0]['args'] ?? [];
            self::assertCount(4, $arguments);
            self::assertInstanceOf(\SensitiveParameterValue::class, $arguments[2]);
            self::assertInstanceOf(\SensitiveParameterValue::class, $arguments[3]);
        }
    }

    public function testDebugOutputCarriesNamesButNoValues(): void
    {
        $binding = new ResolvedBinding(
            'db',
            'connection:relational',
            self::VALUE,
            ['password' => self::PASSWORD, 'Password' => self::TOKEN, '8080' => self::TOKEN, 'empty' => ''],
        );
        // 只带绑定名、类型与按升序排列的属性名（lifecycle.md §7.2）
        self::assertSame(
            ['name' => 'db', 'type' => 'connection:relational', 'properties' => ['8080', 'Password', 'empty', 'password']],
            $binding->__debugInfo(),
        );
        $configuration = new Configuration(JsonObject::of(), [], [$binding]);
        foreach (['binding' => $binding, 'list' => [$binding], 'configuration' => $configuration] as $what => $value) {
            ob_start();
            var_dump($value);
            $dumped = (string) ob_get_clean();
            foreach (['var_dump' => $dumped, 'print_r' => print_r($value, true)] as $function => $text) {
                self::assertNoSecrets($text, sprintf('%s of the %s', $function, $what));
                foreach (['db', 'connection:relational', 'Password', 'password', 'empty', '8080'] as $part) {
                    self::assertStringContainsString($part, $text, sprintf('%s of the %s', $function, $what));
                }
            }
        }
        self::assertSame(
            ['name' => 'cache', 'type' => 'cache:distributed', 'properties' => []],
            (new ResolvedBinding('cache', 'cache:distributed', self::VALUE))->__debugInfo(),
        );
    }

    public function testTheRuntimeKeepsBindingValuesOutOfItsLogAndTheStatus(): void
    {
        // 配置回调成功、返回失败、抛出三种情况：运行时写的日志与回给宿主的 Status 都不带主值与属性值，作者照常拿到它们
        $outcomes = [
            'succeeds' => static fn (): Result => Result::success(),
            'fails' => static fn (): Result => Result::fail(Error::validation('TESTS_VALIDATION_001', 'tests.connection.failed')),
            'throws' => static fn (): Result => throw new \RuntimeException('The connection could not be opened.'),
        ];
        foreach ($outcomes as $name => $outcome) {
            $configured = self::configure([
                (new V1\Binding())
                    ->setName('db')
                    ->setType('connection:relational')
                    ->setValue(self::VALUE)
                    ->setProperties(['password' => self::PASSWORD, 'Password' => self::TOKEN]),
            ], $outcome);
            self::assertSame($name === 'succeeds', $configured['response']->getStatus()?->getOk(), $name);
            self::assertCount(1, $configured['bindings'], $name);
            $binding = $configured['bindings'][0];
            self::assertSame(self::VALUE, $binding->value, $name);
            self::assertSame(self::PASSWORD, $binding->property('password'), $name);
            self::assertSame(self::TOKEN, $binding->property('Password'), $name);
            self::assertNoSecrets($configured['log'], 'the runtime log when the configure hook ' . $name);
            self::assertNoSecrets(
                $configured['response']->serializeToJsonString(),
                'the status when the configure hook ' . $name,
            );
        }
    }

    /**
     * 起一个插件、握手并以给定的绑定配置，返回配置回调拿到的绑定、应答与运行时写的日志。
     *
     * @param list<V1\Binding> $bindings 下发的绑定
     * @param (\Closure(): Result<Unit>)|null $outcome 配置回调的结局；不给即成功
     *
     * @return array{bindings: list<ResolvedBinding>, response: V1\ConfigureResponse, log: string}
     */
    private static function configure(array $bindings, ?\Closure $outcome = null): array
    {
        $received = [];
        $harness = new Harness();
        $harness->serve(self::options(
            $harness,
            static function (Configuration $configuration) use (&$received, $outcome): Result {
                $received = $configuration->bindings;

                return $outcome === null ? Result::success() : $outcome();
            },
        ));
        try {
            $harness->handshake();
            $response = $harness->unary(
                'Configure',
                (new V1\ConfigureRequest())->setConfiguration('{}')->setBindings($bindings),
                new V1\ConfigureResponse(),
            );
        } finally {
            $harness->close();
        }
        rewind($harness->stderr);

        return ['bindings' => $received, 'response' => $response, 'log' => (string) stream_get_contents($harness->stderr)];
    }

    /**
     * 组装测试插件：没有操作与订阅，配置回调是给定的函数。
     *
     * @param \Closure(Configuration): Result<Unit> $configure
     */
    private static function options(Harness $harness, \Closure $configure): Options
    {
        return new Options(
            manifest: self::MANIFEST,
            hooks: new Hooks(configure: $configure),
            stdin: $harness->stdin,
            stdout: $harness->stdout,
            stderr: $harness->stderr,
            environment: Harness::environment(),
        );
    }

    /**
     * 把属性表写成按名字升序的（名字，值）对，名字一律是字符串。
     *
     * @param array<array-key, mixed> $properties
     *
     * @return list<array{string, string}>
     */
    private static function pairs(array $properties): array
    {
        $pairs = [];
        foreach ($properties as $name => $value) {
            self::assertIsString($value, sprintf("the value of the property '%s' must be a string", $name));
            $pairs[] = [(string) $name, $value];
        }
        usort($pairs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $pairs;
    }

    /**
     * 按字节序排好的名字。
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names, SORT_STRING);

        return $names;
    }

    private static function assertNoSecrets(string $text, string $what): void
    {
        foreach ([self::VALUE, self::PASSWORD, self::TOKEN] as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $text,
                $what . ' must not carry the value or a property value of a binding',
            );
        }
    }
}
