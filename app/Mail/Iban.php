<?php

namespace App\Mail;

/**
 * IBAN normalisation and the ISO 13616 format + mod-97 check, with no library and no bcmath
 * (mail-design.md §6.1.1). It proves the number is well-formed, not that the account exists.
 */
final class Iban
{
    /** Spaces and hyphens removed, upper-cased. */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $value));
    }

    /** Two letters, two digits, 11-30 letters/digits (15-34 in all), and a remainder of 1 modulo 97. */
    public static function isValid(string $value): bool
    {
        $iban = self::normalize($value);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $char) {
            // A letter is two digits (A=10 ... Z=35), a digit is itself; the remainder is kept digit by digit.
            $digits = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    /** Shown to a customer in groups of four. */
    public static function format(string $value): string
    {
        return trim(chunk_split(self::normalize($value), 4, ' '));
    }
}
