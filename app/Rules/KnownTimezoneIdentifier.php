<?php

namespace App\Rules;

use Closure;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `site.timezone` setting's value — the one place that decides
 * what a merchant may store under that key. A new app/Rules/ folder: this
 * codebase had no custom validation rule before now, and Laravel's own
 * conventional location is the honest one for a framework validation rule
 * (site-settings-design.md §4 is the reason the rule exists at all — "whichever
 * feature defines a given key owns the meaning AND VALIDATION of that key's
 * value", so the storage layer stays deliberately dumb and the check lives
 * with the feature).
 *
 * TWO CHECKS, BOTH USING PHP'S OWN DATA RATHER THAN ANY LIST WRITTEN BY
 * HAND, and neither is redundant:
 *
 * 1. `new DateTimeZone($value)` — PHP's own parser, which throws
 *    DateInvalidTimeZoneException (a \Exception subclass) on anything it
 *    cannot read. This is the check that guards the promise this whole
 *    setting makes: whatever is stored here is handed to Carbon's
 *    setTimezone() on every rendered date in the panel, so a value PHP
 *    cannot parse would throw in front of the merchant at RENDER time —
 *    long after the save reported success.
 * 2. membership in `DateTimeZone::listIdentifiers()` — the same real,
 *    complete list the settings page's own Select offers, so nothing can be
 *    stored that the merchant could not also pick again from the UI. PHP
 *    accepts a few strings that are valid arguments but are not identifiers
 *    at all ('+02:00', 'CET'); check 1 alone would let those through, and
 *    they are exactly what a UTC offset must never be here — an offset is
 *    silently wrong for half the year in any DST zone, which is this
 *    setting's own reason for storing an IANA identifier instead of a
 *    number.
 *
 * The message is a lang key of this project's own (settings.timezone.invalid,
 * en + bg), not Laravel's built-in `timezone` rule's framework string: that
 * rule would do check 2 only, and its message has no Bulgarian translation
 * shipped in this project, so a Bulgarian-locale merchant would see English.
 */
final class KnownTimezoneIdentifier implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('settings.timezone.invalid'));

            return;
        }

        try {
            new DateTimeZone($value);
        } catch (Exception) {
            $fail(__('settings.timezone.invalid'));

            return;
        }

        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $fail(__('settings.timezone.invalid'));
        }
    }
}
