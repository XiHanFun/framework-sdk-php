<?php

declare(strict_types=1);

namespace XiHan\Framework\Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XiHan\Framework\Plugin\Envelope;
use XiHan\Framework\Plugin\Internal\Protocol;
use XiHan\Framework\Plugin\Protocol\V1;
use XiHan\Framework\Tests\Vectors;

/**
 * 信封的 `time` 与 `dataschema`（invocation.md §4，RFC-0009「信封」第 3 条，ADR-0305、ADR-0306）：写出的文本、读入、往返与
 * 没有时间；回放 conformance/vectors/plugin/envelope-times.json（host 档：宿主读插件写来的 `time` 与 SDK 读宿主写来的是
 * 同一条文法）。PHP 的时刻精确到微秒。
 */
final class EnvelopeTest extends TestCase
{
    /**
     * 六个 SDK 共用的写出用例：作者给的时刻（按 PHP 的解析器读）与 SDK 写出的 `time`。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function writtenTimes(): iterable
    {
        yield 'milliseconds' => ['2026-11-25T03:25:00.123Z', '2026-11-25T03:25:00.123Z'];
        yield 'half a second' => ['2026-11-25T03:25:00.500Z', '2026-11-25T03:25:00.5Z'];
        yield 'trailing zero' => ['2026-11-25T03:25:00.120Z', '2026-11-25T03:25:00.12Z'];
        yield 'whole second' => ['2026-11-25T03:25:00Z', '2026-11-25T03:25:00Z'];
        yield 'east of utc' => ['2026-11-25T11:25:00.25+08:00', '2026-11-25T03:25:00.25Z'];
        yield 'west of utc' => ['2026-11-24T22:25:00-05:00', '2026-11-25T03:25:00Z'];
        yield 'previous year' => ['2026-01-01T00:30:00+01:00', '2025-12-31T23:30:00Z'];
        yield 'first year' => ['0001-01-01T00:00:00Z', '0001-01-01T00:00:00Z'];
        // 微秒是 PHP 的精度
        yield 'one microsecond' => ['2026-11-25T03:25:00.000001Z', '2026-11-25T03:25:00.000001Z'];
        yield 'microseconds' => ['2026-11-25T03:25:00.123456Z', '2026-11-25T03:25:00.123456Z'];
        yield 'last microsecond' => ['9999-12-31T23:59:59.999999Z', '9999-12-31T23:59:59.999999Z'];
    }

    /**
     * 六个 SDK 共用的读入用例：宿主写来的 `time` 与作者收到的 UTC 时刻（比微秒更细的小数截断），null 为没有时间。
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function readTimes(): iterable
    {
        yield 'offset' => ['2026-11-25T11:25:00+08:00', '2026-11-25T03:25:00.000000Z'];
        yield 'negative offset' => ['2026-11-24T22:25:00.25-05:00', '2026-11-25T03:25:00.250000Z'];
        yield 'lower case' => ['2026-11-25t03:25:00z', '2026-11-25T03:25:00.000000Z'];
        yield 'seven digits' => ['2026-11-25T03:25:00.1234567+00:00', '2026-11-25T03:25:00.123456Z'];
        yield 'nine digits' => ['2026-11-25T03:25:00.123456789Z', '2026-11-25T03:25:00.123456Z'];
        yield 'last instant' => ['9999-12-31T23:59:59.999999999Z', '9999-12-31T23:59:59.999999Z'];
        yield 'no time' => ['', null];
    }

    #[DataProvider('writtenTimes')]
    public function testTimesAreWrittenInUtcWithTheTrailingZerosOfTheFractionDropped(string $time, string $text): void
    {
        $given = new \DateTimeImmutable($time);
        self::assertSame($text, Protocol::formatTime($given));
        self::assertSame($text, Protocol::toProtoEnvelope(self::envelope($given))->getTime());
        // 往返：写出的文本读回同一个时刻
        self::assertSame(self::utc($given), self::deliveredTime($text));
    }

    #[DataProvider('readTimes')]
    public function testTimesFromTheHostAreReadInUtcAndTruncatedToMicroseconds(string $text, ?string $utc): void
    {
        self::assertSame($utc, self::deliveredTime($text));
    }

    public function testTimesOutsideTheGrammarAreNotRead(): void
    {
        // 闰秒、没有偏移、空格代替 T、十位小数、只有空白
        $invalid = [
            '2026-11-25T03:25:60Z',
            '2026-11-25T03:25:00',
            '2026-11-25 03:25:00Z',
            '2026-11-25T03:25:00.1234567890Z',
            ' ',
        ];
        foreach ($invalid as $text) {
            self::assertNull(self::delivered($text), $text);
        }
    }

    public function testEnvelopesWithoutATimeWriteAnEmptyText(): void
    {
        $envelope = new Envelope('orders', 'e1', 'urn:test', 'orders.created');
        self::assertNull($envelope->time);
        self::assertSame('', Protocol::toProtoEnvelope($envelope)->getTime());
    }

    public function testTimesOutsideTheFourDigitYearsAreWrittenForTheHostToReject(): void
    {
        // 不在本地核对（ADR-0306）：照写，宿主以 messaging.envelope.invalid_time 拒绝那一项
        $outside = [
            [new \DateTimeImmutable('0001-01-01T00:30:00+01:00'), '0000-12-31T23:30:00Z'],
            [new \DateTimeImmutable('9999-12-31T23:30:00-01:00'), '10000-01-01T00:30:00Z'],
            [(new \DateTimeImmutable('@0'))->setDate(-1, 6, 1), '-0001-06-01T00:00:00Z'],
        ];
        foreach ($outside as [$time, $text]) {
            self::assertSame($text, Protocol::toProtoEnvelope(self::envelope($time))->getTime());
            self::assertNull(self::delivered($text), $text);
        }
    }

    public function testTheTimeIsCopiedSoLaterChangesDoNotReachTheEnvelope(): void
    {
        $mutable = new \DateTime('2026-11-25T11:25:00+08:00');
        $envelope = new Envelope('orders', 'e1', 'urn:test', 'orders.created', time: $mutable);
        $mutable->modify('+1 day');
        self::assertInstanceOf(\DateTimeImmutable::class, $envelope->time);
        self::assertSame('2026-11-25T03:25:00Z', Protocol::toProtoEnvelope($envelope)->getTime());
    }

    public function testEnvelopeTimesFollowTheVector(): void
    {
        $vectors = Vectors::object('plugin/envelope-times');
        self::assertIsArray($vectors->valid);
        foreach ($vectors->valid as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->text);
            $expected = is_string($case->utc) ? substr($case->utc, 0, 26) . 'Z' : null;
            self::assertSame($expected, self::deliveredTime($case->text), $case->text);
        }
        self::assertIsArray($vectors->invalid);
        foreach ($vectors->invalid as $text) {
            self::assertIsString($text);
            self::assertNull(self::delivered($text), sprintf("'%s' must be rejected", $text));
        }
        // written 是宿主写给插件的：SDK 读出 instant 那个时刻
        self::assertIsArray($vectors->written);
        foreach ($vectors->written as $case) {
            self::assertInstanceOf(\stdClass::class, $case);
            self::assertIsString($case->text);
            $expected = null;
            if (is_string($case->instant)) {
                // 比微秒更细的小数截断之后交给 PHP 的解析器
                $instant = preg_replace('/(\.[0-9]{6})[0-9]+/', '$1', $case->instant);
                self::assertIsString($instant);
                $expected = self::utc(new \DateTimeImmutable($instant));
            }
            self::assertSame($expected, self::deliveredTime($case->text), $case->text);
        }
    }

    public function testTheDataSchemaIsCarriedAsIs(): void
    {
        $schema = 'https://example.com/schemas/order.json';
        $envelope = new Envelope('orders', 'e1', 'urn:test', 'orders.created', dataSchema: $schema);
        self::assertSame($schema, Protocol::toProtoEnvelope($envelope)->getDataSchema());
        self::assertSame('', Protocol::toProtoEnvelope(new Envelope('orders', 'e1', 'urn:test', 'x'))->getDataSchema());
        // 不裁剪、不核对：只有空白的值由宿主拒绝那一项
        $spaced = new Envelope('orders', 'e1', 'urn:test', 'x', dataSchema: ' urn:x ');
        self::assertSame(' urn:x ', Protocol::toProtoEnvelope($spaced)->getDataSchema());
        // 收到的原样交出，空串是没有
        foreach ([$schema, '', ' '] as $text) {
            $delivered = Protocol::envelope(self::proto('', $text));
            self::assertNotNull($delivered);
            self::assertSame($text, $delivered->dataSchema, $text);
        }
    }

    private static function envelope(\DateTimeImmutable $time): Envelope
    {
        return new Envelope('orders', 'e1', 'urn:test', 'orders.created', time: $time);
    }

    /**
     * 宿主写来的信封：只设 `time` 与 `dataschema`。
     */
    private static function proto(string $time, string $dataSchema = ''): V1\Envelope
    {
        return (new V1\Envelope())->setTopic('orders')->setId('e1')->setSource('urn:test')->setType('orders.created')
            ->setTime($time)->setDataSchema($dataSchema);
    }

    /**
     * 宿主写来的 `time` 经 SDK 读出的信封；读不出为 null。
     */
    private static function delivered(string $time): ?Envelope
    {
        return Protocol::envelope(self::proto($time));
    }

    /**
     * 宿主写来的 `time` 经 SDK 读出的 UTC 时刻，写成微秒精度的文本；没有时间为 null。
     */
    private static function deliveredTime(string $text): ?string
    {
        $envelope = self::delivered($text);
        self::assertNotNull($envelope, sprintf("'%s' should be read", $text));
        if ($envelope->time === null) {
            return null;
        }
        self::assertSame(0, $envelope->time->getOffset(), $text);

        return self::utc($envelope->time);
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
