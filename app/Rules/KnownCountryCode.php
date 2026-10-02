<?php

namespace App\Rules;

use App\Settings\CountryNames;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `site.country` setting: an uppercase ISO 3166-1 alpha-2 code
 * from CountryNames' list, the same list the settings page's Select offers —
 * so nothing can be stored that the merchant could not also pick again. The
 * message is a lang key of this project's own (settings.country.invalid,
 * en + bg), as KnownTimezoneIdentifier does for its setting.
 */
final class KnownCountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CountryNames::isKnownCode($value)) {
            $fail(__('settings.country.invalid'));
        }
    }
}
