<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Endpoint;
use XiHan\Framework\Plugin\Internal\Versions;
use XiHan\Framework\Plugin\Network;
use XiHan\Framework\Primitives\InvalidArgumentException;
use XiHan\Framework\Tests\Vectors;

/**
 * 握手行的文法与协议版本的协商，回放 conformance/vectors/plugin/ 的向量（handshake.md §2、§3）。
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
        // 行尾的换行不是地址的一部分
        self::assertNull(Endpoint::tryParseHandshakeLine("xihan-plugin/1 unix /run/x.sock\n"));
    }

    public function testEndpointsAreLocalOnly(): void
    {
        self::assertSame('unix /tmp/p.sock', (string) Endpoint::tryParse('unix /tmp/p.sock'));
        self::assertSame('tcp://127.0.0.1:5000', (new Endpoint(Network::Tcp, '127.0.0.1:5000'))->socketUri());
        self::assertSame('unix:///tmp/p.sock', (new Endpoint(Network::Unix, '/tmp/p.sock'))->socketUri());
        self::assertNull((new Endpoint(Network::NamedPipe, '\\\\.\\pipe\\x'))->socketUri());
        self::assertNull(Endpoint::tryParse('tcp 10.0.0.1:5000'));
        $this->expectException(InvalidArgumentException::class);
        new Endpoint(Network::Tcp, 'localhost:5000');
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
        self::assertSame([1], Versions::parse('1'));
        self::assertSame([1, 2], Versions::parse('1, 2'));
        self::assertNull(Versions::parse(''));
        self::assertNull(Versions::parse('1,x'));
        self::assertNull(Versions::parse('01'));
        self::assertNull(Versions::parse('4294967296'));
    }
}
