<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;

/**
 * The ONE place that turns what a merchant types into the stored integer hundredths and back (MEAS-2).
 *
 * Input: "95", "95.5", "95,5" (a comma is a decimal mark), at most 2 decimals, > 0, <= 999 999.99. Empty / whitespace = "no value" (null). Everything else throws: "abc", "-1", "0", "1.234", "1 000", "1e3".
 * No thousands separator is accepted (a dot and a comma would be ambiguous). An int is read as whole units.
 *
 * Output: trimmed zeros and the locale's decimal mark (bg comma, anything else a dot): 9000 -> "90", 9550 -> "95,5", 9525 -> "95,25". No thousands separator.
 */
final class MeasurementNumber
{
    public const MIN_HUNDREDTHS = 1;

    public const MAX_HUNDREDTHS = 99_999_999;

    /**
     * @throws InvalidMeasurementValueException
     */
    public static function parse(string|int|float|null $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_float($raw)) {
            // A float cannot be trusted for 2 decimals: round-trip it through a string with a fixed precision, then judge the text like any other input.
            $raw = rtrim(rtrim(number_format($raw, 4, '.', ''), '0'), '.');
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
            throw InvalidMeasurementValueException::notANumber($text);
        }

        $hundredths = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return self::assertInRange($hundredths);
    }

    /**
     * A value that is already hundredths (read back from storage or handed to a repository) must still be a legal number.
     *
     * @throws InvalidMeasurementValueException
     */
    public static function assertInRange(int $hundredths): int
    {
        if ($hundredths < self::MIN_HUNDREDTHS || $hundredths > self::MAX_HUNDREDTHS) {
            throw InvalidMeasurementValueException::outOfRange($hundredths);
        }

        return $hundredths;
    }

    public static function format(int $hundredths, string $locale = 'bg'): string
    {
        $whole = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        $mark = str_starts_with(strtolower($locale), 'bg') ? ',' : '.';

        return $whole.$mark.rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }
}
