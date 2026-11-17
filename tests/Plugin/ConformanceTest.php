<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * 用规范仓库的黑盒运行器（spec/conformance 的 xihan-conformance，版本随子模块锁定）对示例插件 echo 跑全部用例
 * （invocation.md §7）：把 echo 放进临时目录，按当前平台生成启动脚本，断言 14 个用例都通过、没有跳过。
 *
 * 运行器是 Go 程序，以 `go run` 从子模块运行；没有 Go 时跳过。宿主只继承 handshake.md §1 列出的环境变量，所以启动脚本
 * 写死 PHP 与入口脚本的绝对路径。
 */
final class ConformanceTest extends TestCase
{
    /** 运行器的全部用例，按执行顺序 */
    private const array CASES = [
        'handshake', 'refusal', 'session', 'lifecycle', 'order', 'unknown-operation', 'declared-operations',
        'unavailable', 'event', 'unsubscribed', 'stream', 'configuration', 'stop-early', 'stdin',
    ];

    public function testEchoPassesTheBlackBoxRunner(): void
    {
        $go = self::findGo();
        if ($go === null) {
            self::markTestSkipped('Go is not on PATH; the black-box runner (spec/conformance) needs it.');
        }
        $root = dirname(__DIR__, 2);
        $directory = self::pluginDirectory($root);
        try {
            [$exitCode, $output, $errors] = self::execute([
                $go, 'run', './cmd/xihan-conformance', 'plugin',
                '--dir', $directory,
                '--json',
                '--quiet',
                '--config', '{"greeting":"hello"}',
                '--sample', 'echoes.create={"text":"hi"}',
                '--exclude', 'echoes.crash',
            ], $root . '/spec/conformance');
        } finally {
            self::remove($directory);
        }
        $results = json_decode($output, true);
        self::assertIsArray($results, sprintf("The runner printed no JSON (exit %d):\n%s\n%s", $exitCode, $output, $errors));
        $cases = [];
        foreach ($results as $result) {
            self::assertIsArray($result);
            $name = $result['case'] ?? null;
            self::assertIsString($name);
            $cases[] = $name;
            self::assertTrue($result['passed'] ?? false, sprintf('%s: %s', $name, self::text($result['message'] ?? '')));
            self::assertFalse($result['skipped'] ?? true, sprintf('%s was skipped: %s', $name, self::text($result['message'] ?? '')));
        }
        self::assertSame(self::CASES, $cases);
        self::assertSame(0, $exitCode, $errors);
    }

    /**
     * 在 PATH 里找 go；找不到为 null。
     */
    private static function findGo(): ?string
    {
        $names = PHP_OS_FAMILY === 'Windows' ? ['go.exe'] : ['go'];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach ($names as $name) {
                $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
                if ($directory !== '' && is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * 建插件目录：清单（entry 按平台写）与启动脚本（Unix 上是带 shebang 的 sh，Windows 上是 .cmd）。
     */
    private static function pluginDirectory(string $root): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xihan-echo-php-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            self::fail('Cannot create the plugin directory.');
        }
        $windows = PHP_OS_FAMILY === 'Windows';
        $entry = $windows ? 'echo.cmd' : 'echo';
        $manifest = (string) file_get_contents($root . '/examples/echo/xihan.manifest.json');
        $manifest = str_replace('"entry": "echo"', '"entry": "' . $entry . '"', $manifest);
        file_put_contents($directory . '/xihan.manifest.json', $manifest);
        $script = $root . '/examples/echo/bin/echo.php';
        if ($windows) {
            file_put_contents($directory . '/echo.cmd', sprintf("@echo off\r\n\"%s\" \"%s\" %%*\r\n", PHP_BINARY, $script));
        } else {
            $quote = static fn (string $text): string => "'" . str_replace("'", "'\\''", $text) . "'";
            file_put_contents($directory . '/echo', sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", $quote(PHP_BINARY), $quote($script)));
            chmod($directory . '/echo', 0755);
        }

        return $directory;
    }

    /**
     * 运行命令，返回退出码、stdout 与 stderr；stderr 写进临时文件，两个管道不会互相等。
     *
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private static function execute(array $command, string $directory): array
    {
        $errors = tmpfile();
        if ($errors === false) {
            self::fail('Cannot create a temporary file.');
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors], $pipes, $directory);
        if (!is_resource($process)) {
            self::fail('Cannot start ' . $command[0] . '.');
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($errors);
        $text = (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $output, $text];
    }

    /**
     * 删除插件目录。
     */
    private static function remove(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
