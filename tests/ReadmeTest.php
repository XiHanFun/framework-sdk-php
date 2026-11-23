<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Operation\Response;

/**
 * README 的快速开始：两份 README 同构，示例代码相同（只有注释随语言不同）；操作、管道与调用器的示例照原文能跑，
 * 结果就是注释里写的那一个。
 */
final class ReadmeTest extends TestCase
{
    public function testBothReadmesShowTheSameCode(): void
    {
        $english = self::examples('README.md');
        $chinese = self::examples('README_cn.md');
        self::assertNotEmpty($english);
        self::assertCount(count($english), $chinese);
        foreach ($english as $index => $code) {
            self::assertSame(self::tokens($code), self::tokens($chinese[$index]), sprintf('example %d differs', $index));
        }
    }

    public function testTheOperationExampleRuns(): void
    {
        foreach (['README.md', 'README_cn.md'] as $file) {
            $example = null;
            foreach (self::examples($file) as $code) {
                if (str_contains($code, 'new Invoker(')) {
                    $example = $code;
                }
            }
            self::assertNotNull($example, sprintf("'%s' must show an operation invoked through the pipeline.", $file));
            // 照 README 的原文执行：示例最后得到的 $response 就是调用的结果
            $response = eval($example . "\nreturn \$response;");
            self::assertInstanceOf(Response::class, $response, $file);
            self::assertTrue($response->outcome->isOk(), $file);
            self::assertSame('{"id":"o-c1","quantity":2}', $response->payload, $file);
            self::assertStringContainsString('{"id":"o-c1","quantity":2}', $example, 'the example states its result');
        }
    }

    /**
     * 取 README 里的 PHP 代码块，按出现的顺序。
     *
     * @return list<string>
     */
    private static function examples(string $file): array
    {
        $path = dirname(__DIR__) . '/' . $file;
        $text = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($text, sprintf("'%s' is missing.", $path));
        preg_match_all('/^```php\R(.*?)^```\h*$/ms', $text, $matches);

        return $matches[1];
    }

    /**
     * 把代码拆成记号，去掉注释与空白：两份 README 的代码按记号比较，注释可以随语言不同。
     *
     * @return list<string>
     */
    private static function tokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_string($token)) {
                $tokens[] = $token;
            } elseif (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token[1];
            }
        }

        return $tokens;
    }
}
