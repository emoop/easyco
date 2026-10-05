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
    /**
     * Input longer than this is refused BEFORE any regex or string work runs on it: a form field is
     * attacker-controllable text, and no real amount (9 integer digits, a separator, a few decimals,
     * some spaces) needs more.
     */
    public const MAX_LENGTH = 32;

    /**
     * At most this many integer digits. 999 999 999 in minor units is under 10^11, so every sum the
     * refund dialogs add up (lines, shipping, deduction, caps) stays far below PHP_INT_MAX — the
     * limit lives here, in the admin layer, and Money itself is untouched.
     */
    public const MAX_INTEGER_DIGITS = 9;

    public static function parse(?string $input, Currency|string $currency): ?Money
    {
        $currency = Currency::from($currency);

        if (strlen((string) $input) > self::MAX_LENGTH) {
            return null;
        }

        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $input);
        $text = str_replace(',', '.', (string) $text);

        if ($text === '' || ! preg_match('/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        [$integerPart, $fraction] = array_pad(explode('.', $text), 2, '');

        if (strlen($integerPart) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $decimals = strlen($fraction);

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
