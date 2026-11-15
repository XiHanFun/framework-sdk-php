<?php

declare(strict_types=1);

namespace XiHan\Framework\Primitives\Internal;

/**
 * 数字的文本形式与可移植的格式说明符（RFC-0010「模板」，ADR-0233）。
 *
 * 定点格式（F、N）按浮点数的精确十进制展开舍入，正好一半时取偶，与 .NET 一致；PHP 的 number_format 与 round
 * 先按十进制近似再一半进位，所以不用它们，自己展开 double 的二进制值。
 *
 * @internal 不属于公开 API，随时可能改变。
 */
final class Numbers
{
    /** 大整数的一位（十进制 9 位） */
    private const int BASE = 1_000_000_000;

    /** 乘 5 的幂时每次乘的因子 5^12：一位乘它加进位不超出 64 位整数 */
    private const int FIVE_CHUNK = 244_140_625;

    /** 乘 2 的幂时每次最多乘 2^30 */
    private const int TWO_CHUNK_BITS = 30;

    private function __construct()
    {
    }

    /**
     * 浮点数的文本形式：1e-4 ≤ |x| < 1e15 或 x = 0 时写能往返的最短十进制、不用指数，整数值不带小数点；
     * 范围之外按 .NET 的往返形式写指数（规范不作规定）。
     */
    public static function plain(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '∞' : '-∞';
        }
        $sign = $value < 0 || ($value === 0.0 && fdiv(1.0, $value) < 0) ? '-' : '';
        $magnitude = abs($value);
        if ($magnitude === 0.0) {
            return $sign . '0';
        }
        [$digits, $exponent] = self::shortest($magnitude);
        if ($magnitude < 1e-4 || $magnitude >= 1e15) {
            $mantissa = strlen($digits) > 1 ? $digits[0] . '.' . substr($digits, 1) : $digits;

            $power = str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);

            return $sign . $mantissa . 'E' . ($exponent < 0 ? '-' : '+') . $power;
        }
        // 最短数字串是 0.d1d2… × 10^(exponent+1)，按小数点的位置展开成定点写法
        $point = $exponent + 1;
        if ($point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= strlen($digits)) {
            return $sign . $digits . str_repeat('0', $point - strlen($digits));
        }

        return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
    }

    /**
     * 定点小数（F）或带千分位的定点小数（N），不变文化：分组符 `,`、小数点 `.`，负号在前。
     */
    public static function fixed(int|float $value, int $decimals, bool $grouped): string
    {
        if (is_int($value)) {
            $negative = $value < 0;
            [$integer, $fraction] = [ltrim((string) $value, '-'), ''];
        } else {
            $negative = $value < 0 || ($value === 0.0 && fdiv(1.0, $value) < 0);
            [$integer, $fraction] = self::exact(abs($value));
        }
        [$integer, $fraction] = self::roundHalfEven($integer, $fraction, $decimals);
        if ($grouped) {
            $integer = self::group($integer);
        }

        return ($negative ? '-' : '') . $integer . ($decimals > 0 ? '.' . $fraction : '');
    }

    /**
     * 整数（D）：至少 `$digits` 位，不足补零，负号在前。
     */
    public static function integer(int $value, int $digits): string
    {
        $text = ltrim((string) $value, '-');

        return ($value < 0 ? '-' : '') . str_pad($text, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * 非负整数的十六进制（X / x）：至少 `$digits` 位，不足补零。
     */
    public static function hex(int $value, int $digits, bool $uppercase): string
    {
        $text = str_pad(dechex($value), $digits, '0', STR_PAD_LEFT);

        return $uppercase ? strtoupper($text) : $text;
    }

    /**
     * 能往返的最短有效数字与十进制指数：value = d1.d2d3… × 10^exponent。
     *
     * @return array{string, int}
     */
    private static function shortest(float $magnitude): array
    {
        // 从 1 位有效数字起试，第一个能原样读回的就是最短的；17 位一定能往返
        for ($precision = 0; $precision < 17; $precision++) {
            $candidate = sprintf('%.' . $precision . 'e', $magnitude);
            if ((float) $candidate === $magnitude) {
                break;
            }
        }
        if (preg_match('/^(\d)(?:\.(\d+))?e([+-]\d+)$/D', $candidate, $match) !== 1) {
            throw new \LogicException(sprintf("The notation '%s' is not in the expected shape.", $candidate));
        }
        $digits = rtrim($match[1] . $match[2], '0');

        return [$digits === '' ? '0' : $digits, (int) $match[3]];
    }

    /**
     * 非负有限浮点数的精确十进制展开：整数部分与小数部分的数字串，小数部分不带末尾的零。
     *
     * double 是 m × 2^e：e ≥ 0 时是整数 m × 2^e；e < 0 时等于 m × 5^(-e) / 10^(-e)，小数位数正好是 -e。
     *
     * @return array{string, string}
     */
    private static function exact(float $magnitude): array
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', pack('E', $magnitude));
        $bits = $unpacked[1];
        $biased = ($bits >> 52) & 0x7FF;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($biased === 0) {
            $exponent = -1074;
        } else {
            $mantissa |= 1 << 52;
            $exponent = $biased - 1075;
        }
        if ($mantissa === 0) {
            return ['0', ''];
        }
        // 尾数最多 53 位二进制（约 9e15），三位十进制 9 位一组足够放下
        $limbs = [
            $mantissa % self::BASE,
            intdiv($mantissa, self::BASE) % self::BASE,
            intdiv($mantissa, self::BASE * self::BASE),
        ];
        if ($exponent >= 0) {
            for ($left = $exponent; $left > 0; $left -= self::TWO_CHUNK_BITS) {
                $limbs = self::multiply($limbs, 1 << min($left, self::TWO_CHUNK_BITS));
            }

            return [self::digits($limbs), ''];
        }
        $places = -$exponent;
        for ($left = $places; $left > 0; $left -= 12) {
            $limbs = self::multiply($limbs, $left >= 12 ? self::FIVE_CHUNK : 5 ** $left);
        }
        $digits = str_pad(self::digits($limbs), $places + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$places);

        return [$integer, rtrim(substr($digits, -$places), '0')];
    }

    /**
     * 按小数位数舍入：舍去部分大于一半进位、小于一半舍去，正好一半时让保留的末位成为偶数。
     *
     * @return array{string, string}
     */
    private static function roundHalfEven(string $integer, string $fraction, int $decimals): array
    {
        if (strlen($fraction) <= $decimals) {
            return [$integer, str_pad($fraction, $decimals, '0')];
        }
        $kept = substr($fraction, 0, $decimals);
        $first = $fraction[$decimals];
        // 小数部分已去掉末尾的零：首位是 5 且其后没有数字，才是正好一半
        $exactlyHalf = $first === '5' && strlen($fraction) === $decimals + 1;
        $last = $decimals > 0 ? $kept[$decimals - 1] : $integer[strlen($integer) - 1];
        $up = $first > '5' || ($first === '5' && (!$exactlyHalf || ((int) $last) % 2 === 1));
        if (!$up) {
            return [$integer, $kept];
        }
        $incremented = self::increment($integer . $kept);
        $split = strlen($incremented) - $decimals;

        return [substr($incremented, 0, $split), substr($incremented, $split)];
    }

    /**
     * 十进制数字串加一。
     */
    private static function increment(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $zeros = str_repeat('0', strlen($digits) - $index - 1);

                return substr($digits, 0, $index) . ((int) $digits[$index] + 1) . $zeros;
            }
        }

        return '1' . str_repeat('0', strlen($digits));
    }

    /**
     * 整数部分每三位插一个分组符。
     */
    private static function group(string $integer): string
    {
        $head = strlen($integer) % 3;
        $groups = $head > 0 ? [substr($integer, 0, $head)] : [];

        return implode(',', [...$groups, ...str_split(substr($integer, $head), 3)]);
    }

    /**
     * @param list<int> $limbs 小端的十进制 9 位一组
     *
     * @return list<int>
     */
    private static function multiply(array $limbs, int $factor): array
    {
        $carry = 0;
        foreach ($limbs as $index => $limb) {
            $product = $limb * $factor + $carry;
            $limbs[$index] = $product % self::BASE;
            $carry = intdiv($product, self::BASE);
        }
        while ($carry > 0) {
            $limbs[] = $carry % self::BASE;
            $carry = intdiv($carry, self::BASE);
        }

        return $limbs;
    }

    /**
     * @param list<int> $limbs
     */
    private static function digits(array $limbs): string
    {
        while (count($limbs) > 1 && $limbs[count($limbs) - 1] === 0) {
            array_pop($limbs);
        }
        $text = (string) $limbs[count($limbs) - 1];
        for ($index = count($limbs) - 2; $index >= 0; $index--) {
            $text .= str_pad((string) $limbs[$index], 9, '0', STR_PAD_LEFT);
        }

        return $text;
    }
}
