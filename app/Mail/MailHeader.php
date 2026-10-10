<?php

namespace App\Mail;

/**
 * The header-injection guard (mail-design.md §5.4, §10): the ONE place a value that may have been typed by a
 * person becomes a header value (subject, display name, reply-to name) or a recipient address.
 *
 * Two independent barriers exist: this class, and Symfony's header API, which also refuses a newline.
 * No user value is ever placed in a raw header.
 */
final class MailHeader
{
    /** Control characters (C0, DEL, C1) and Unicode line/paragraph separators. */
    private const CONTROL = '/[\x00-\x1F\x7F-\x9F\x{2028}\x{2029}]/u';

    /** Bidirectional controls, a zero-width no-break space (BOM) and the Arabic letter mark: they can reorder or hide text. */
    private const BIDI = '/[\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * A header-safe single line: CR, LF, NUL, tabs and every other control or bidi character removed,
     * whitespace collapsed, trimmed, cut to $max characters. Invalid UTF-8 is scrubbed first.
     */
    public static function clean(string $value, int $max = 180): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace(self::CONTROL, ' ', $value);
        $value = (string) preg_replace(self::BIDI, '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, max(0, $max), 'UTF-8');
    }

    /**
     * True when the value carries no control and no bidi character at all (CR, LF, NUL, tab, C1, line separators,
     * bidi overrides, zero-width marks) and is valid UTF-8. For FORM VALIDATION: clean() silently rewrites, this
     * says "refuse it" so a merchant sees the error instead of a quietly altered sender name.
     */
    public static function isPlain(string $value): bool
    {
        return preg_match(self::CONTROL, $value) === 0 && preg_match(self::BIDI, $value) === 0;
    }

    /**
     * A single valid address (no display name, no list, no group) of at most 254 characters, or null.
     * Stricter than filter_var alone: whitespace, control characters, commas, semicolons, angle brackets,
     * quotes and non-ASCII are refused outright (those are how a second recipient or a header is smuggled in).
     */
    public static function address(string $value): ?string
    {
        // Only plain spaces are trimmed: a NUL, tab or newline at the edge is refused below, not silently dropped.
        $value = trim($value, ' ');

        if ($value === '' || strlen($value) > 254) {
            return null;
        }

        if (preg_match('/[^\x21-\x7E]|[,;<>()"\'\\\\\[\]:]/', $value) === 1) {
            return null;
        }

        if (substr_count($value, '@') !== 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $value;
    }
}
