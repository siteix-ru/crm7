<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Integer-scaled decimal arithmetic for stock quantities (5 fractional digits).
 * Avoids PHP floats entirely so that no precision is lost. The integer part is
 * limited to 13 digits (well above the stock cap of 1e9) so the scaled value
 * always fits in a signed 64-bit int.
 */
final class Decimal
{
    public const SCALE = 5;

    /** @return string canonical value with exactly 5 decimal places */
    public static function parse(mixed $raw): string
    {
        if (is_int($raw)) {
            $text = (string) $raw;
        } elseif (is_string($raw)) {
            $text = trim($raw);
        } else {
            throw new \InvalidArgumentException('invalid decimal');
        }

        if ($text === '' || preg_match('/^-?[0-9]+(?:\.[0-9]{1,5})?$/', $text) !== 1) {
            throw new \InvalidArgumentException('invalid decimal');
        }

        $negative = $text[0] === '-';
        if ($negative) {
            $text = substr($text, 1);
        }

        $parts = explode('.', $text, 2);
        $fraction = isset($parts[1]) ? str_pad($parts[1], self::SCALE, '0') : str_repeat('0', self::SCALE);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') {
            $integer = '0';
        }
        if (strlen($integer) > 13) {
            throw new \InvalidArgumentException('decimal out of range');
        }

        $units = (int) $integer * (10 ** self::SCALE) + (int) $fraction;

        return self::fromUnits($negative ? -$units : $units);
    }

    public static function fromUnits(int $units): string
    {
        $negative = $units < 0;
        $absolute = abs($units);
        $integer = intdiv($absolute, 10 ** self::SCALE);
        $fraction = $absolute % (10 ** self::SCALE);

        return ($negative ? '-' : '') . $integer . '.' . str_pad((string) $fraction, self::SCALE, '0', STR_PAD_LEFT);
    }

    public static function units(string $canonical): int
    {
        $negative = $canonical !== '' && $canonical[0] === '-';
        $text = $negative ? substr($canonical, 1) : $canonical;
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $value = (int) $integer * (10 ** self::SCALE) + (int) str_pad($fraction, self::SCALE, '0');

        return $negative ? -$value : $value;
    }

    public static function add(string $a, string $b): string
    {
        return self::fromUnits(self::units($a) + self::units($b));
    }

    public static function sub(string $a, string $b): string
    {
        return self::fromUnits(self::units($a) - self::units($b));
    }

    public static function compare(string $a, string $b): int
    {
        return self::units($a) <=> self::units($b);
    }
}
