<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Host;
use XiHan\Framework\Plugin\Runtime;

/**
 * 源码里的属性标注：会话令牌的参数不进堆栈，重写父类或实现接口的方法都标 #[\Override]。
 */
final class AttributesTest extends TestCase
{
    public function testSessionTokensAreSensitiveParameters(): void
    {
        // 会话令牌是插件与宿主之间的凭据（handshake.md §4）：异常的堆栈与 debug_backtrace 里只留下占位对象
        foreach ([Host::class, Runtime::class] as $class) {
            $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
            $session = null;
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === 'session') {
                    $session = $parameter;
                }
            }
            self::assertNotNull($session, sprintf("The constructor of '%s' takes the session token.", $class));
            self::assertCount(1, $session->getAttributes(\SensitiveParameter::class), $class);
        }
    }

    public function testOverridingMethodsCarryTheOverrideAttribute(): void
    {
        $checked = 0;
        $unmarked = [];
        foreach (self::types() as $type) {
            $class = new \ReflectionClass($type);
            foreach ($class->getMethods() as $method) {
                // 只看这个类型自己写的方法；构造函数不参与签名兼容，不算重写
                $own = $method->getDeclaringClass()->getName() === $class->getName();
                if (!$own || !$method->isUserDefined() || $method->isConstructor() || !$method->hasPrototype()) {
                    continue;
                }
                $checked++;
                if ($method->getAttributes(\Override::class) === []) {
                    $unmarked[] = $type . '::' . $method->getName();
                }
            }
        }
        self::assertGreaterThan(0, $checked);
        self::assertSame([], $unmarked, 'methods that override or implement without #[\Override]: ' . implode(', ', $unmarked));
    }

    /**
     * 列出 src/ 下的全部类型（类、接口与枚举）；protoc 生成的协议类不手改，不在其中。
     *
     * @return list<class-string>
     */
    private static function types(): array
    {
        $root = dirname(__DIR__) . '/src';
        $types = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'Plugin/Protocol/')) {
                continue;
            }
            // PSR-4：XiHan\Framework\ → src/，文件名等于类型名
            $type = 'XiHan\\Framework\\' . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            if (!class_exists($type) && !interface_exists($type)) {
                self::fail(sprintf("'%s' does not declare '%s'.", $relative, $type));
            }
            $types[] = $type;
        }
        sort($types, SORT_STRING);
        self::assertNotEmpty($types);

        return $types;
    }
}
