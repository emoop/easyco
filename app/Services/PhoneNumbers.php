<?php

namespace App\Services;

use EasyCo\Order\IdentityKeys;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * THE ONE PLACE a phone number is read (stage PH-1): validated with Google's data (giggsey/libphonenumber-for-php-lite) by the rules of the
 * country the number is parsed against, stored as E.164 (`+359888123456`) and shown in a form fit for the reader.
 *
 *  - parse(): `isValidNumber`, never `isPossibleNumber` (a number with a digit missing is often "possible" and never valid). A number written with a
 *    leading `+` or `00` is read as international and may belong to ANY country; any other is read as a number OF the given country. Letters
 *    (vanity numbers) and extensions are refused: the stored value is the bare number a courier dials.
 *  - formatForDisplay(): the national form for a number of the store's country, the international form otherwise. A Bulgarian mobile is written the
 *    way Bulgarians write it, `0888 123 456` (the library's own national grouping is `088 812 3456`); every other national format is Google's.
 *  - identityKey(): the phone key of cod-protection-design.md 2: the E.164 string (exact) when the number is valid, else the old last-9-digits fallback.
 */
final class PhoneNumbers
{
    /** The longest raw input accepted: the same guard the interim `max:32` gave. */
    public const MAX_RAW_LENGTH = 32;

    public static function parse(string $raw, ?string $country): ?PhoneParse
    {
        $raw = trim($raw);

        // Digits and the usual separators only: libphonenumber would read "0888 CALL NOW" as a vanity number.
        if ($raw === '' || strlen($raw) > self::MAX_RAW_LENGTH || preg_match('/^[0-9+\-\s().\/]+$/', $raw) !== 1) {
            return null;
        }

        // `00` is the international prefix of most countries: it is read as `+` whatever the delivery country is.
        if (str_starts_with($raw, '00')) {
            $raw = '+'.substr($raw, 2);
        }

        $country = $country === null ? null : strtoupper($country);
        $util = PhoneNumberUtil::getInstance();
        $international = str_starts_with($raw, '+');

        if (! $international && ($country === null || ! in_array($country, $util->getSupportedRegions(), true))) {
            return null;
        }

        try {
            $number = $util->parse($raw, $international ? null : $country);
        } catch (NumberParseException) {
            return null;
        }

        if ($number->hasExtension() || ! $util->isValidNumber($number)) {
            return null;
        }

        $type = $util->getNumberType($number);

        return new PhoneParse(
            e164: $util->format($number, PhoneNumberFormat::E164),
            region: (string) $util->getRegionCodeForNumber($number),
            type: $type->name,
            isFixedLine: $type === PhoneNumberType::FIXED_LINE,
        );
    }

    /** What is stored: the E.164 form, or null when the number is not valid for that country. */
    public static function e164(string $raw, ?string $country): ?string
    {
        return self::parse($raw, $country)?->e164;
    }

    public static function formatForDisplay(string $e164, ?string $storeCountry): string
    {
        $parsed = self::parse($e164, null);

        // Not a number we can read (an old row left untouched): shown as it was typed.
        if ($parsed === null) {
            return $e164;
        }

        $util = PhoneNumberUtil::getInstance();
        $number = $util->parse($parsed->e164, null);

        if ($storeCountry === null || $parsed->region !== strtoupper($storeCountry)) {
            return $util->format($number, PhoneNumberFormat::INTERNATIONAL);
        }

        // A Bulgarian mobile: 0888 123 456 (the library groups it 088 812 3456).
        if ($parsed->region === 'BG' && $parsed->type === 'MOBILE' && preg_match('/^\+359(\d{3})(\d{3})(\d{3})$/', $parsed->e164, $m) === 1) {
            return '0'.$m[1].' '.$m[2].' '.$m[3];
        }

        return $util->format($number, PhoneNumberFormat::NATIONAL);
    }

    /** The phone key: the exact E.164 string for a valid number, else the old last-9-digits fallback (IdentityKeys). */
    public static function identityKey(?string $raw, ?string $country): ?string
    {
        if ($raw !== null && ($parsed = self::parse($raw, $country)) !== null) {
            return $parsed->e164;
        }

        return IdentityKeys::phone($raw);
    }
}
