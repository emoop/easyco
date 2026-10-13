<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The multi-line sibling of PlainText (checkout RN-1, checkout-region-and-note-design.md section 3), for the customer's order note:
 * the SAME posture — nothing is rewritten, special characters are escaped where they are RENDERED — but a note may have line breaks.
 * Rejects a string that carries:
 *
 *  - a C0/C1 CONTROL character OTHER THAN a line break: below U+0020, or U+007F-U+009F, except \n (U+000A) and \r (U+000D) —
 *    TAB is refused (it has no place in a note and would break a table cell it is printed into);
 *  - a BIDIRECTIONAL OVERRIDE or ISOLATE character (U+202A-U+202E, U+2066-U+2069);
 *  - invalid UTF-8 (preg_match() returns false and the value is refused, so a malformed body is a 422 field error, not a 500 from MySQL).
 *
 * A CRLF pair is kept as typed. Matched on code points, never bytes (`/u`): 0x91 inside the UTF-8 letter U+0411 is not C1.
 * PlainText itself is unchanged: every single-line field it guards still refuses a newline.
 */
final class PlainMultilineText implements ValidationRule
{
    public function __construct(
        private readonly string $messageKey = 'validation.plain_multiline_text',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a string at all: the `string` rule owns that failure, not this one.
        if (! is_string($value)) {
            return;
        }

        // !== 0 on purpose: 1 = a refused character, false = not valid UTF-8; both fail.
        if (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) !== 0) {
            $fail(__($this->messageKey));
        }
    }
}
