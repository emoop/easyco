<?php

namespace App\Services;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * What a person typed into an amount box, as Money — never through a float. A comma or a dot
 * separates the decimals ("3,50" and "3.50" are the same), spaces (also the no-break space and
 * the thin space used as thousands separators) are ignored, and the currency's own decimal places
 * are the limit. Anything else — a sign, letters, two separators, too many decimals — is null:
 * the caller shows "enter an amount such as 12.50", it never guesses.
 */
final class MoneyInput
{
    public static function parse(?string $input, Currency|string $currency): ?Money
    {
        $currency = Currency::from($currency);
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $input);
        $text = str_replace(',', '.', (string) $text);

        if ($text === '' || ! preg_match('/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        $decimals = strlen(explode('.', $text)[1] ?? '');

        if ($decimals > $currency->decimalPlaces()) {
            return null;
        }

        try {
            return Money::fromDecimal($text, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Blank is nothing entered (zero); anything else must parse. Null means "typed, but not an amount". */
    public static function parseOrZero(?string $input, Currency|string $currency): ?Money
    {
        return trim((string) $input) === '' ? Money::zero($currency) : self::parse($input, $currency);
    }
}
