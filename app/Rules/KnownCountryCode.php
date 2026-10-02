<?php

namespace App\Rules;

use App\Settings\CountryNames;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a country code: an uppercase ISO 3166-1 alpha-2 code from
 * CountryNames' list (plus XK), the same list the country Selects offer — so
 * nothing can be stored that could not also be picked again.
 *
 * Used for the `site.country` setting and, since stage 3.0b, for the delivery
 * country on every address and order (a pickup point included). The message is
 * a lang key of this project's own (settings.country.invalid by default, en +
 * bg), as KnownTimezoneIdentifier does for its setting; a caller whose users
 * need other wording passes its own key.
 *
 * normalize() is the one place a submitted value is trimmed and uppercased
 * before validation. The domain never normalizes; this is the boundary that
 * does. ASCII-only on purpose: mb_strtoupper("ß") is "SS", which is South
 * Sudan, and a letter that is not A-Z must fail rather than become one.
 */
final class KnownCountryCode implements ValidationRule
{
    public function __construct(
        private readonly string $messageKey = 'settings.country.invalid',
    ) {
    }

    /** Trim and uppercase a submitted code; anything that is not a string is returned untouched. */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value)
            ? strtr(trim($value), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CountryNames::isKnownCode($value)) {
            $fail(__($this->messageKey));
        }
    }
}
