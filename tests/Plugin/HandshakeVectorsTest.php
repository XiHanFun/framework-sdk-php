<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Internal\HostAddresses;
use XiHan\Framework\Plugin\Internal\Versions;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Plugin\Runtime;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Tests\Vectors;

/**
 * 握手行与端点的文法、宿主回调端点列表的解析与选择、协议版本的协商，回放 conformance/vectors/plugin/ 的
 * handshake-lines、host-addresses 与 protocol-versions（handshake.md §2、§3、§4）。
 */
final class HandshakeVectorsTest extends TestCase
{
    public function testHandshakeLinesFollowTheVectors(): void
    {
        $vectors = Vectors::object('plugin/handshake-lines');
        self::assertIsArray($vectors->valid);
        foreach ($vectors->valid as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->line);
            $endpoint = Endpoint::tryParseHandshakeLine($case->line);
            self::assertNotNull($endpoint, $case->line);
            self::assertSame($case->network, $endpoint->network->value, $case->line);
            self::assertSame($case->address, $endpoint->address, $case->line);
            self::assertSame($case->line, $endpoint->handshakeLine());
        }
        self::assertIsArray($vectors->invalid);
        foreach ($vectors->invalid as $line) {
            self::assertIsString($line);
            self::assertNull(Endpoint::tryParseHandshakeLine($line), sprintf("'%s' must be rejected", $line));
        }
        // 行尾不是地址的一部分
        self::assertNull(Endpoint::tryParseHandshakeLine("xihan-plugin/1 unix /run/x.sock\n"));
        // 不合法的 UTF-8 不能写进握手行
        self::assertNull(Endpoint::tryParseHandshakeLine("xihan-plugin/1 unix /run/\xFF.sock"));
    }

    public function testEndpointsAreLocalOnly(): void
    {
        self::assertSame('unix /tmp/p.sock', (string) Endpoint::tryParse('unix /tmp/p.sock'));
        self::assertSame('tcp://127.0.0.1:5000', (new Endpoint(Network::Tcp, '127.0.0.1:5000'))->socketUri());
        self::assertSame('unix:///tmp/p.sock', (new Endpoint(Network::Unix, '/tmp/p.sock'))->socketUri());
        self::assertNull((new Endpoint(Network::NamedPipe, '\\\\.\\pipe\\x'))->socketUri());
        self::assertTrue(Endpoint::isLoopback('127.255.0.9:65535'));
        self::assertFalse(Endpoint::isLoopback('127.0.0.1:0'));
        foreach ([[Network::Tcp, 'localhost:5000'], [Network::Unix, '/tmp/a b.sock'], [Network::Unix, '']] as [$network, $address]) {
            try {
                new Endpoint($network, $address);
                self::fail(sprintf("'%s' must be rejected", $address));
            } catch (InvalidArgumentException) {
                // 预期之内
            }
        }
    }

    public function testHostAddressesFollowTheVectors(): void
    {
        $vectors = Vectors::object('plugin/host-addresses');
        self::assertIsArray($vectors->valid);
        foreach ($vectors->valid as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->value);
            self::assertIsArray($case->entries);
            $parsed = HostAddresses::parse($case->value);
            self::assertNotNull($parsed, $case->value);
            $entries = array_map(
                static fn (Endpoint $endpoint): array => ['network' => $endpoint->network->value, 'address' => $endpoint->address],
                $parsed,
            );
            self::assertSame(json_decode((string) json_encode($case->entries), true), $entries, $case->value);
        }
        self::assertIsArray($vectors->invalid);
        foreach ($vectors->invalid as $value) {
            self::assertIsString($value);
            self::assertNull(HostAddresses::parse($value), sprintf("'%s' must be rejected", $value));
        }
        self::assertIsArray($vectors->selection);
        foreach ($vectors->selection as $index => $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsArray($case->supported);
            $supported = array_values(array_map(static fn (mixed $network): Network => Network::from(is_string($network) ? $network : ''), $case->supported));
            $selected = HostAddresses::select(is_string($case->value) ? $case->value : null, $supported);
            if ($case->selected === null) {
                self::assertNull($selected, (string) $index);
            } else {
                self::assertInstanceOf(\stdClass::class, $case->selected);
                self::assertNotNull($selected, (string) $index);
                self::assertSame($case->selected->network, $selected->network->value, (string) $index);
                self::assertSame($case->selected->address, $selected->address, (string) $index);
            }
        }
    }

    public function testThisSdkConnectsToUnixAndTcpButNotNamedPipes(): void
    {
        $supported = HostAddresses::supported();
        self::assertContains(Network::Tcp, $supported);
        self::assertNotContains(Network::NamedPipe, $supported);
        // .NET 宿主在 Windows 上给出命名管道加回环 TCP：取回环 TCP
        $selected = HostAddresses::select('npipe \\\\.\\pipe\\xihan-host;tcp 127.0.0.1:50123', $supported);
        self::assertSame('tcp 127.0.0.1:50123', (string) $selected);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(Network::Unix, $supported[0]);
        }
    }

    public function testProtocolVersionsAreNegotiatedPerTheVectors(): void
    {
        $vectors = Vectors::object('plugin/protocol-versions');
        self::assertIsArray($vectors->cases);
        foreach ($vectors->cases as $index => $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsArray($case->host);
            self::assertIsArray($case->plugin);
            $plugin = array_values(array_filter($case->plugin, is_int(...)));
            $selected = Versions::negotiate($case->host, $plugin);
            if (isset($case->selected)) {
                self::assertSame($case->selected, $selected, (string) $index);
            } else {
                self::assertNull($selected, (string) $index);
                self::assertSame('PLUGINS_VALIDATION_003', $case->error);
            }
        }
    }

    public function testTheHostVersionListIsParsed(): void
    {
        // 本 SDK 实现的协议版本是公开常量，协商用的列表取自它
        self::assertSame(1, Runtime::PROTOCOL_VERSION);
        self::assertSame([Runtime::PROTOCOL_VERSION], Versions::SUPPORTED);
        self::assertSame(Runtime::PROTOCOL_VERSION, Versions::negotiate([1, 2]));
        self::assertSame([1], Versions::parse('1'));
        self::assertSame([1, 2], Versions::parse('1, 2'));
        self::assertNull(Versions::parse(''));
        self::assertNull(Versions::parse('1,x'));
        self::assertNull(Versions::parse('01'));
        self::assertNull(Versions::parse('4294967296'));
    }
}
