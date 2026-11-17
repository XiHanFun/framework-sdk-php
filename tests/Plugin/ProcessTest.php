<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Runtime;

/**
 * 以子进程运行示例插件 echo，核对进程层面的行为：拒绝启动时以非零退出码在握手之前退出、stderr 有 error 日志
 * （handshake.md §1），收到 SIGTERM（需要 pcntl）时收尾并以 0 退出（lifecycle.md §7.7）。
 */
final class ProcessTest extends TestCase
{
    public function testARefusedStartExitsBeforeTheHandshakeLine(): void
    {
        [$process, $pipes] = self::launch([Runtime::ENV_PROTOCOL_VERSIONS => '1']);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(Runtime::EXIT_REFUSED, proc_close($process));
        self::assertSame('', $stdout);
        $entry = json_decode(trim($stderr), true);
        self::assertIsArray($entry, $stderr);
        self::assertSame('error', $entry['level']);
        self::assertSame('The plugin refused to start.', $entry['message']);
        self::assertSame('xihan.samples.echo-php', $entry['plugin']);
    }

    public function testSigtermStopsThePluginWithExitCodeZero(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !extension_loaded('pcntl')) {
            self::markTestSkipped('SIGTERM is handled only where the pcntl extension is available.');
        }
        [$process, $pipes] = self::launch([
            Runtime::ENV_PROTOCOL_VERSIONS => '1',
            Runtime::ENV_PRIMITIVES => '1',
            Runtime::ENV_SESSION => Harness::SESSION,
        ]);
        try {
            $line = (string) fgets($pipes[1]);
            self::assertNotNull(Endpoint::tryParseHandshakeLine(rtrim($line, "\n")), $line);
            self::assertTrue(proc_terminate($process, 15));
            $began = microtime(true);
            $code = null;
            while (microtime(true) - $began < 5.0) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $code = $status['exitcode'];
                    break;
                }
                usleep(20_000);
            }
            self::assertSame(0, $code, (string) stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }

    /**
     * 以给定的环境变量起 echo；工作目录是示例目录，清单从那里读。
     *
     * @param array<string, string> $environment
     *
     * @return array{resource, array<int, resource>}
     */
    private static function launch(array $environment): array
    {
        $root = dirname(__DIR__, 2);
        $inherited = [];
        foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $inherited[$name] = $value;
            }
        }
        $process = proc_open(
            [PHP_BINARY, $root . '/examples/echo/bin/echo.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root . '/examples/echo',
            [...$inherited, ...$environment],
        );
        if (!is_resource($process)) {
            self::fail('Cannot start the echo plugin.');
        }

        return [$process, $pipes];
    }
}
