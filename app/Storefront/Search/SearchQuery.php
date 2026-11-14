<?php

namespace App\Storefront\Search;

/**
 * What a visitor typed, normalised (SRCH-1 A): trimmed, whitespace collapsed, at most 100 characters, split into at most 6 words, and a query of
 * fewer than 2 characters is NOT a search (`searchable()` is false: the page shows a hint instead). Immutable; built only through `fromInput()`.
 *
 * The words are what the engine matches (AND between words); `likeWords()` are the same words with `%`, `_` and `\` escaped for a LIKE pattern, so a
 * visitor's `%` is a character, never a wildcard. `text` is the normalised query as typed (printed back in the field and the title, ALWAYS escaped by the view).
 */
final readonly class SearchQuery
{
    public const MAX_LENGTH = 100;

    public const MIN_LENGTH = 2;

    public const MAX_WORDS = 6;

    /** @param  list<string>  $words */
    private function __construct(public string $text, public array $words)
    {
    }

    /** Anything is accepted (a missing, array or oversized value included): the result is an empty or short query, never an error. */
    public static function fromInput(mixed $input): self
    {
        $raw = is_string($input) ? $input : '';
        // control characters (and a broken UTF-8 byte sequence) are not part of a search
        $raw = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_convert_encoding($raw, 'UTF-8', 'UTF-8'));
        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $text = trim(mb_substr($text, 0, self::MAX_LENGTH));
        $words = $text === '' ? [] : array_slice(explode(' ', $text), 0, self::MAX_WORDS);

        return new self($text, $words);
    }

    public function searchable(): bool
    {
        return mb_strlen($this->text) >= self::MIN_LENGTH;
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /** @return list<string> the words with LIKE metacharacters escaped (backslash first) */
    public function likeWords(): array
    {
        return array_map(self::escapeLike(...), $this->words);
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
