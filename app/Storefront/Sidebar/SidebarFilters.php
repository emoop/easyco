<?php

namespace App\Storefront\Sidebar;

/**
 * P1: the «Сайдбар» zone of «Дизайн» — ONE ordered list of the shop's filters, each shown or hidden. The same list drives the side panel, the top filter bar, the chips and «Изчисти» (FilterCatalog::all()).
 *
 * A key is one of the three built-ins (`brand`, `season`, `tag`) or the id of a filterable attribute as a string. The list is a CLOSED set of the keys the shop knows right now (FilterCatalog::knownKeys): unknown
 * keys and duplicates are dropped on read and REFUSED on save / publish; a known key the list does not mention (a filter added later) is appended at the end, shown. The kind of a built-in (list / links / buttons)
 * belongs to the same screen but keeps living in the «Каталог» JSON (`filterBrand` / `filterSeason` / `filterTag`), so the working copy carries it as `kinds` and the published list holds only `filters`.
 *
 * A VALIDATED working copy is `{filters: list<{key: string, show: bool}>, kinds: {brand: string, season: string, tag: string}}`. Pure: no query, no clock.
 *
 * @phpstan-type Row array{key: string, show: bool}
 */
final class SidebarFilters
{
    public const BUILTIN = ['brand', 'season', 'tag'];

    /** The kinds the screen offers for a built-in. (A stored `off` of the older «Каталог» form is still READ — as «hidden» — but never offered or written.) */
    public const KINDS = ['list', 'links', 'buttons'];

    public const MAX_ROWS = 80;

    /** Is this a key the closed grammar allows (a built-in word or a plain positive whole number)? */
    public static function isKey(mixed $key): bool
    {
        return is_string($key) && (in_array($key, self::BUILTIN, true) || preg_match('/\A[1-9][0-9]{0,17}\z/', $key) === 1);
    }

    /**
     * The default list: the known keys in their own order, every attribute shown, a built-in shown unless the older «Каталог» choice for it is `off` (so an untouched shop is exactly what it was).
     *
     * @param  list<string>  $known
     * @param  array<string, string>  $kinds  the stored kind by built-in (`off` | list | links | buttons)
     * @return list<Row>
     */
    public static function defaults(array $known, array $kinds): array
    {
        return array_map(static fn (string $key): array => ['key' => $key, 'show' => ! in_array($key, self::BUILTIN, true) || ($kinds[$key] ?? 'off') !== 'off'], $known);
    }

    /**
     * READ: the rows to use, from what was stored (null = nothing stored: the defaults). Anything unusable is dropped quietly, never an error: an unknown key, a duplicate, a row that is not an array, a `show`
     * that is not a real boolean (that row is shown); the known keys it does not mention are appended, shown.
     *
     * @param  list<string>  $known
     * @param  array<string, string>  $kinds
     * @return list<Row>
     */
    public static function effective(?array $stored, array $known, array $kinds): array
    {
        if ($stored === null) {
            return self::defaults($known, $kinds);
        }

        $rows = [];
        $seen = [];

        foreach (array_is_list($stored) ? $stored : [] as $row) {
            $key = is_array($row) && self::isKey($row['key'] ?? null) ? (string) $row['key'] : null;

            if ($key === null || isset($seen[$key]) || ! in_array($key, $known, true)) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = ['key' => $key, 'show' => ($row['show'] ?? true) !== false];
        }

        foreach ($known as $key) {
            isset($seen[$key]) || $rows[] = ['key' => $key, 'show' => true];
        }

        return $rows;
    }

    /** The stored JSON of the published list as raw rows, or null when there is none (or it is not `{"filters": [...]}`). @return list<mixed>|null */
    public static function rowsFromJson(mixed $json): ?array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($data) && is_array($data['filters'] ?? null) && array_is_list($data['filters']) ? $data['filters'] : null;
    }

    /** The kind to PRINT for a built-in: the stored one, or `list` when it is something else (the older `off` of a built-in that is now shown). */
    public static function kindToPrint(?string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? (string) $kind : 'list';
    }

    /**
     * SAVE / PUBLISH: the working copy checked strictly. Returns the clean copy (every known key present, missing ones appended shown, a missing kind `list`) and the messages by path. A failing copy returns the
     * clean copy of what could be kept (the caller does not store it when there are errors).
     *
     * @param  list<string>  $known
     * @return array{0: array{filters: list<Row>, kinds: array<string, string>}, 1: array<string, string>}
     */
    public static function validate(mixed $value, array $known): array
    {
        $errors = [];
        $fail = static function (string $path, string $code) use (&$errors): void {
            $errors[$path] ??= (string) __('design.sidebar.errors.'.$code);
        };
        $rows = [];
        $kinds = array_fill_keys(self::BUILTIN, 'list');

        if (! is_array($value) || ! is_array($value['filters'] ?? null) || ! array_is_list($value['filters'])) {
            $fail('filters', 'bad_list');
            $value = ['filters' => []];
        }

        foreach (array_diff(array_keys($value), ['filters', 'kinds']) as $extra) {
            $fail('filters', 'bad_list');
        }

        if (count($value['filters']) > self::MAX_ROWS) {
            $fail('filters', 'too_many');
        }

        $seen = [];

        foreach (array_slice($value['filters'], 0, self::MAX_ROWS) as $i => $row) {
            $path = 'filters.'.$i;

            if (! is_array($row) || array_diff(array_keys($row), ['key', 'show', 'kind']) !== []) {
                $fail($path, 'bad_row');

                continue;
            }

            $key = $row['key'] ?? null;

            if (! self::isKey($key) || ! in_array($key, $known, true)) {
                $fail($path, 'unknown_filter');

                continue;
            }

            if (isset($seen[$key])) {
                $fail($path, 'duplicate');

                continue;
            }

            $seen[$key] = true;
            $show = $row['show'] ?? null;

            if (! is_bool($show)) {
                $fail($path, 'bad_show');
                $show = true;
            }

            if (in_array($key, self::BUILTIN, true)) {
                $kind = $row['kind'] ?? (is_array($value['kinds'] ?? null) ? $value['kinds'][$key] ?? null : null) ?? 'list';

                if (! in_array($kind, self::KINDS, true)) {
                    $fail($path, 'bad_kind');
                    $kind = 'list';
                }

                $kinds[$key] = $kind;
            }

            $rows[] = ['key' => $key, 'show' => $show];
        }

        foreach ($known as $key) {
            isset($seen[$key]) || $rows[] = ['key' => $key, 'show' => true];
        }

        return [['filters' => $rows, 'kinds' => $kinds], $errors];
    }
}
