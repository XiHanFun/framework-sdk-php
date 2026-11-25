<?php

declare(strict_types=1);

namespace XiHan\Framework\Plugin\Internal;

/**
 * InvocationMetadata.deadline 的读写与生效的截止时间（invocation.md §2，向量 conformance/vectors/plugin/deadlines.json）。
 *
 * parse() 也是宿主写来的 Envelope.time 的读法（RFC-0003 规则 8，invocation.md §4），两处共用一条文法。
 *
 * 读法严格：`^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,9})?([Zz]|[+-][0-9]{2}:[0-9]{2})\z`，
 * 年 0001–9999、月日按公历、时分秒不越界（不认闰秒）、偏移的时 00–23 与分 00–59，换算成 UTC 之后（不计小数）在
 * 0001-01-01T00:00:00 到 9999-12-31T23:59:59 之间；PHP 的时刻精确到微秒，更细的小数截断、不进位。写法是 UTC、七位小数、
 * `+00:00`（.NET 的 O 格式）。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Deadlines
{
    /** 读法的文法；D 修饰符让 $ 只匹配串尾 */
    private const string PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt]([0-9]{2}):([0-9]{2}):([0-9]{2})'
        . '(?:\.([0-9]{1,9}))?(?:[Zz]|([+-])([0-9]{2}):([0-9]{2}))$/D';

    /** 0001-01-01T00:00:00Z 的 Unix 时间戳 */
    private const int MIN_TIMESTAMP = -62135596800;

    /** 9999-12-31T23:59:59Z 的 Unix 时间戳 */
    private const int MAX_TIMESTAMP = 253402300799;

    private function __construct()
    {
    }

    /**
     * 按严格文法读；读不出（含空串）为 null，调用方当作没有截止时间。
     */
    public static function parse(string $text): ?\DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $text, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = array_map(
            intval(...),
            [$match[1], $match[2], $match[3], $match[4], $match[5], $match[6]],
        );
        // DateTime 会把越界的日期进位成别的日子，所以每一段先按公历核对
        if (
            $year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)
            || $hour > 23 || $minute > 59 || $second > 59
        ) {
            return null;
        }
        $offset = 0;
        if ($match[8] !== null) {
            $offsetHours = (int) $match[9];
            $offsetMinutes = (int) $match[10];
            if ($offsetHours > 23 || $offsetMinutes > 59) {
                return null;
            }
            $offset = ($match[8] === '-' ? -1 : 1) * ($offsetHours * 3600 + $offsetMinutes * 60);
        }
        $utc = new \DateTimeZone('UTC');
        $local = (new \DateTimeImmutable('@0'))->setTimezone($utc)->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second);
        // 范围按换算成 UTC 之后、不计小数的时刻判定，所以 9999-12-31T23:59:59.999999999Z 仍然合法
        $timestamp = $local->getTimestamp() - $offset;
        if ($timestamp < self::MIN_TIMESTAMP || $timestamp > self::MAX_TIMESTAMP) {
            return null;
        }
        $instant = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($utc);
        // 比微秒更细的小数截断、不进位
        $micros = $match[7] === null ? 0 : (int) substr(str_pad($match[7], 6, '0'), 0, 6);

        return $instant->setTime(
            (int) $instant->format('H'),
            (int) $instant->format('i'),
            (int) $instant->format('s'),
            $micros,
        );
    }

    /**
     * 写成 UTC、七位小数、`+00:00`：PHP 的时刻只到微秒，第七位总是 0。
     */
    public static function format(\DateTimeImmutable $deadline): string
    {
        return $deadline->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u') . '0+00:00';
    }

    /**
     * 生效的截止时间：元数据的 deadline（读不出当作没有）与 gRPC 调用自身的截止时间取较早的一个；两者都没有时为 null。
     */
    public static function effective(?\DateTimeImmutable $metadata, ?\DateTimeImmutable $grpc): ?\DateTimeImmutable
    {
        if ($metadata === null || $grpc === null) {
            return $metadata ?? $grpc;
        }

        return $grpc < $metadata ? $grpc : $metadata;
    }

    /**
     * 返回公历某年某月的天数：四年一闰、百年不闰、四百年再闰。
     */
    private static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }
}
