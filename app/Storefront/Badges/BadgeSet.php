<?php

namespace App\Storefront\Badges;

use App\Rules\PlainText;

/**
 * P7-1: the SET of card badges the «Значки» zone of «Дизайн» edits and the storefront prints (packages/EasyCo/documents/badges-research.md §D). One row per BUILT-IN badge (`sale` «Промо», `new` «Нови», `low_stock`
 * «Последни бройки»); every one is OFF until the merchant switches it on. The row carries a `kind`, so P7-2 can add the kinds `free_shipping` and `custom` without changing the stored shape.
 *
 * A VALIDATED working copy is `{badges: list<Row>}` with ONE row for each built-in kind in the fixed order (SALE, NEW, LOW_STOCK):
 *   Row = {kind, enabled: bool, text: string (≤ 24, plain; '' = the built-in's default word), position: top|bottom, colour: <closed palette>, shape: square|rounded|pill, priority: 1..99}
 *         + sale: `mode` word|percent · new: `days` 1..90 · low_stock: `max` 1..6.
 * The PUBLISHED (compiled) copy keeps ONLY the enabled rows, and nothing at all (the key is forgotten) when none is enabled, so a shop that never used the zone prints today's HTML. The storefront reads it with
 * `fromJson()`, which is strict: a copy that does not validate renders NOTHING (never an error). Pure: no query, no clock, no markup.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class BadgeSet
{
    public const SALE = 'sale';

    public const NEW = 'new';

    public const LOW_STOCK = 'low_stock';

    /** The built-in kinds, in the fixed order of the zone (P7-2 appends its own kinds here). */
    public const KINDS = [self::SALE, self::NEW, self::LOW_STOCK];

    public const POSITIONS = ['top', 'bottom'];

    public const COLOURS = ['neutral', 'red', 'orange', 'green', 'blue', 'purple', 'dark', 'light'];

    public const SHAPES = ['square', 'rounded', 'pill'];

    public const MODES = ['word', 'percent'];

    public const MAX_TEXT = 24;

    public const MAX_PER_POSITION = 2;

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 90;

    public const STOCK_CHOICES = [1, 2, 3, 4, 5, 6];

    /** The row a kind starts from: OFF, at its default position / colour / shape / priority and parameter. @return Row */
    public static function defaultRow(string $kind): array
    {
        $row = ['kind' => $kind, 'enabled' => false, 'text' => '', 'position' => $kind === self::LOW_STOCK ? 'bottom' : 'top', 'shape' => 'square'];

        return match ($kind) {
            self::SALE => $row + ['colour' => 'red', 'priority' => 10, 'mode' => 'word'],
            self::NEW => $row + ['colour' => 'dark', 'priority' => 20, 'days' => 14],
            default => $row + ['colour' => 'orange', 'priority' => 30, 'max' => 3],
        };
    }

    /** @return array{badges: list<Row>} every built-in OFF */
    public static function defaults(): array
    {
        return ['badges' => array_map(self::defaultRow(...), self::KINDS)];
    }

    /**
     * The working copy as the zone starts from the PUBLISHED (compiled) JSON: the stored enabled rows over the defaults; a stored copy that does not validate is the defaults.
     *
     * @return array{badges: list<Row>}
     */
    public static function workingFrom(?string $json): array
    {
        $published = self::fromJson($json);
        $rows = [];

        foreach (self::KINDS as $kind) {
            $rows[] = $published[$kind] ?? self::defaultRow($kind);
        }

        return ['badges' => $rows];
    }

    /**
     * The storefront's READ of a stored (compiled) copy: the enabled rows by kind, or [] when the JSON is missing or does not validate (a hand-edited row hides every badge, it never prints something unsafe).
     *
     * @return array<string, Row>
     */
    public static function fromJson(?string $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $stored = json_decode($json, true);

        if (! is_array($stored)) {
            return [];
        }

        [$clean, $errors] = self::validate($stored);

        if ($errors !== []) {
            return [];
        }

        $out = [];

        foreach ($clean['badges'] as $row) {
            $row['enabled'] && $out[$row['kind']] = $row;
        }

        return $out;
    }

    /**
     * The compiled copy to publish: the enabled rows of a VALIDATED working copy, or null when none is enabled (the caller forgets the key).
     *
     * @param  array{badges: list<Row>}  $clean
     */
    public static function compile(array $clean): ?string
    {
        $rows = array_values(array_filter($clean['badges'], static fn (array $row): bool => $row['enabled'] === true));

        return $rows === [] ? null : (string) json_encode(['badges' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validates a working copy (the zone's form, a stored draft, a stored compiled copy): a closed key set, one row per known kind (a missing one is its default, a duplicate or an unknown kind is refused), plain text,
     * closed colour / shape / position lists, whole numbers in range. Returns the NORMALISED copy (every row complete, in the fixed order) and the messages by path (`sale.text` ...).
     *
     * @return array{0: array{badges: list<Row>}, 1: array<string, string>}
     */
    public static function validate(mixed $input): array
    {
        $errors = [];
        $rows = is_array($input) && is_array($input['badges'] ?? null) ? array_values($input['badges']) : [];
        $byKind = [];

        if (! is_array($input) || ! is_array($input['badges'] ?? null)) {
            $errors['badges'] = self::message('bad_set');
        }

        foreach ($rows as $row) {
            $kind = is_array($row) && is_string($row['kind'] ?? null) ? $row['kind'] : null;

            if ($kind === null || ! in_array($kind, self::KINDS, true)) {
                $errors['badges'] = self::message('unknown_kind');

                continue;
            }

            if (isset($byKind[$kind])) {
                $errors[$kind] = self::message('duplicate');

                continue;
            }

            $byKind[$kind] = $row;
        }

        $out = [];

        foreach (self::KINDS as $kind) {
            $out[] = isset($byKind[$kind]) ? self::row($kind, $byKind[$kind], $errors) : self::defaultRow($kind);
        }

        return [['badges' => $out], $errors];
    }

    /** @param  array<string, mixed>  $raw @param  array<string, string>  $errors @return Row */
    private static function row(string $kind, array $raw, array &$errors): array
    {
        $row = self::defaultRow($kind);
        $row['enabled'] = self::bool($raw['enabled'] ?? false, "{$kind}.enabled", $errors);
        $row['text'] = self::text($raw['text'] ?? '', "{$kind}.text", $errors);
        $row['position'] = self::choice($raw['position'] ?? null, self::POSITIONS, $row['position'], "{$kind}.position", $errors);
        $row['colour'] = self::choice($raw['colour'] ?? null, self::COLOURS, $row['colour'], "{$kind}.colour", $errors);
        $row['shape'] = self::choice($raw['shape'] ?? null, self::SHAPES, $row['shape'], "{$kind}.shape", $errors);
        $row['priority'] = self::whole($raw['priority'] ?? null, 1, 99, $row['priority'], "{$kind}.priority", $errors);

        match ($kind) {
            self::SALE => $row['mode'] = self::choice($raw['mode'] ?? null, self::MODES, $row['mode'], 'sale.mode', $errors),
            self::NEW => $row['days'] = self::whole($raw['days'] ?? null, self::MIN_DAYS, self::MAX_DAYS, $row['days'], 'new.days', $errors),
            default => $row['max'] = self::stockMax($raw['max'] ?? null, $row['max'], $errors),
        };

        return $row;
    }

    /** @param  array<string, string>  $errors */
    private static function bool(mixed $value, string $path, array &$errors): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (int) $value === 1;
        }

        $errors[$path] = self::message('bad_enabled');

        return false;
    }

    /** @param  array<string, string>  $errors */
    private static function text(mixed $value, string $path, array &$errors): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            $errors[$path] = self::message('bad_text');

            return '';
        }

        $text = trim($value);
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        if ($failed || mb_strlen($text) > self::MAX_TEXT) {
            $errors[$path] = self::message($failed ? 'bad_text' : 'text_too_long');

            return '';
        }

        return $text;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     */
    private static function choice(mixed $value, array $allowed, string $default, string $path, array &$errors): string
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        $errors[$path] = self::message('bad_choice');

        return $default;
    }

    /** @param  array<string, string>  $errors */
    private static function whole(mixed $value, int $min, int $max, int $default, string $path, array &$errors): int
    {
        if ($value === null) {
            return $default;
        }

        $number = self::number($value);

        if ($number === null || $number < $min || $number > $max) {
            $errors[$path] = self::message('out_of_range');

            return $default;
        }

        return $number;
    }

    /** A whole number from an int, a numeric string of up to three digits or a whole float (a numeric form field hands one of them over), else null. */
    private static function number(mixed $value): ?int
    {
        if (is_float($value) && $value === floor($value) && abs($value) < 1000) {
            return (int) $value;
        }

        return is_int($value) ? $value : (is_string($value) && preg_match('/\A[0-9]{1,3}\z/', $value) === 1 ? (int) $value : null);
    }

    /** «Последни бройки»: N from the CLOSED list 1–6. @param  array<string, string>  $errors */
    private static function stockMax(mixed $value, int $default, array &$errors): int
    {
        if ($value === null) {
            return $default;
        }

        $number = self::number($value);

        if ($number === null || ! in_array($number, self::STOCK_CHOICES, true)) {
            $errors['low_stock.max'] = self::message('bad_stock');

            return $default;
        }

        return $number;
    }

    private static function message(string $code): string
    {
        return (string) __('design.badges.errors.'.$code);
    }
}
