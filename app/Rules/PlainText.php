<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a string that carries a character no plain, single-line human value
 * should ever contain:
 *
 *  - a C0/C1 CONTROL character (below U+0020, or U+007F-U+009F — newline and
 *    tab included, since every field this rule guards is single-line);
 *  - a BIDIRECTIONAL OVERRIDE or ISOLATE character (U+202A-U+202E,
 *    U+2066-U+2069), which let a stored value silently reorder itself on
 *    screen around the characters that follow it.
 *
 * NOTHING ELSE IS REFUSED, AND NOTHING IS REWRITTEN: Cyrillic, accents,
 * apostrophes, quotes, `<`, `&` and emoji are all legitimate values and pass
 * through byte for byte. Special characters are escaped where they are
 * RENDERED, never stripped or mangled on the way in — so the text a merchant
 * reads back on the order page is exactly the text the customer typed. An
 * over-1-line value is refused, not trimmed.
 *
 * MATCHED ON CODE POINTS, NEVER BYTES (`/u`): 0x91 inside the UTF-8 Cyrillic
 * letter U+0411 "Б" is a continuation byte, not C1, and a byte-wise test
 * would refuse perfectly ordinary Bulgarian text.
 *
 * An input that is not valid UTF-8 at all is refused as well: preg_match()
 * returns false on it, and this rule treats "cannot even be examined as text"
 * the same as "contains a refused character". Nothing in this schema could
 * store such bytes anyway, so refusing here keeps a malformed body a 422
 * field error instead of a 500 from MySQL.
 *
 * The message is a lang key of this project's own (validation.plain_text by
 * default, en + bg), the same shape KnownCountryCode uses for its key.
 */
final class PlainText implements ValidationRule
{
    public function __construct(
        private readonly string $messageKey = 'validation.plain_text',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a string at all: the `string` rule owns that failure, not this one.
        if (! is_string($value)) {
            return;
        }

        // !== 0 deliberately: preg_match() returns 1 for a match, 0 for none,
        // and false when the subject is not valid UTF-8 — the last two cases
        // are "not a match" and "not examinable", and both must fail.
        if (preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) !== 0) {
            $fail(__($this->messageKey));
        }
    }
}
