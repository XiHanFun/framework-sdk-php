<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use XiHan\Framework\Invocation\CancellationSource;
use XiHan\Framework\Plugin\Internal\Deadlines;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Tests\Invocation\ManualClock;
use XiHan\Framework\Tests\Vectors;

/**
 * InvocationMetadata.deadline 的读法、生效的截止时间与写法，回放 conformance/vectors/plugin/deadlines.json
 * （invocation.md §2）。PHP 的时刻精确到微秒：向量里更细的小数截断后比较。
 */
final class DeadlineVectorsTest extends TestCase
{
    public function testDeadlinesAreReadStrictly(): void
    {
        $vectors = Vectors::object('plugin/deadlines');
        self::assertIsArray($vectors->valid);
        foreach ($vectors->valid as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->text);
            self::assertIsString($case->utc);
            $parsed = Deadlines::parse($case->text);
            self::assertNotNull($parsed, $case->text);
            self::assertSame(self::micros($case->utc), self::utc($parsed), $case->text);
        }
        self::assertIsArray($vectors->invalid);
        foreach ($vectors->invalid as $text) {
            self::assertIsString($text);
            self::assertNull(Deadlines::parse($text), sprintf("'%s' must be rejected", $text));
        }
        self::assertNull(Deadlines::parse(''));
    }

    public function testTheEarlierDeadlineTakesEffect(): void
    {
        $vectors = Vectors::object('plugin/deadlines');
        self::assertIsArray($vectors->effective);
        foreach ($vectors->effective as $index => $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->metadata);
            $grpc = is_string($case->grpc) ? Deadlines::parse($case->grpc) : null;
            $effective = Deadlines::effective(Deadlines::parse($case->metadata), $grpc);
            if ($case->expected === null) {
                self::assertNull($effective, (string) $index);
            } else {
                self::assertIsString($case->expected);
                self::assertNotNull($effective, (string) $index);
                self::assertSame(self::micros($case->expected), self::utc($effective), (string) $index);
            }
        }
    }

    public function testDeadlinesAreWrittenInTheRoundTripFormat(): void
    {
        $vectors = Vectors::object('plugin/deadlines');
        self::assertIsArray($vectors->written);
        foreach ($vectors->written as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->utc);
            self::assertIsString($case->text);
            $deadline = Deadlines::parse($case->utc);
            self::assertNotNull($deadline, $case->utc);
            // 第七位小数比微秒更细：截断成 0 后比较
            self::assertSame(substr($case->text, 0, 26) . '0' . substr($case->text, 27), Deadlines::format($deadline));
        }
    }

    public function testContextsTakeTheEarlierOfMetadataAndGrpcDeadlines(): void
    {
        $clock = new ManualClock('2026-11-18T03:25:00.000000Z');
        $metadata = (new V1\InvocationMetadata())->setDeadline('2026-11-18T03:25:10Z');
        $token = (new CancellationSource())->cancellation();
        // grpc-timeout 更早
        $context = Protocol::context($metadata, 2.5, $token, $clock);
        self::assertSame('2026-11-18T03:25:02.500000Z', self::utc($context->deadline() ?? self::fail()));
        // 元数据更早
        $context = Protocol::context($metadata, 60.0, $token, $clock);
        self::assertSame('2026-11-18T03:25:10.000000Z', self::utc($context->deadline() ?? self::fail()));
        // 读不出的 deadline 当作没有
        $context = Protocol::context((new V1\InvocationMetadata())->setDeadline('2026-11-18 03:25:00Z'), 1.0, $token, $clock);
        self::assertSame('2026-11-18T03:25:01.000000Z', self::utc($context->deadline() ?? self::fail()));
        self::assertNull(Protocol::context((new V1\InvocationMetadata())->setDeadline('bogus'), null, $token, $clock)->deadline());
    }

    /**
     * 把向量里纳秒精度的 UTC 时刻截断到微秒：`2026-11-18T03:25:00.123456700Z` → `2026-11-18T03:25:00.123456Z`。
     */
    private static function micros(string $utc): string
    {
        return substr($utc, 0, 26) . 'Z';
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
