<?php

namespace App\Storefront\Badges;

use App\Rules\PlainText;
use App\Storefront\Conditions\ProductConditions;

/**
 * P7-1: the SET of card badges the «Значки» zone of «Дизайн» edits and the storefront prints (packages/EasyCo/documents/badges-research.md §D). One row per BUILT-IN badge (`sale` «Промо», `new` «Нови», `low_stock`
 * «Последни бройки», P7-2: `free_shipping` «Безплатна доставка»); every one is OFF until the merchant switches it on. P7-2 adds up to 12 `custom` rows («Допълнителни значки»): each has a stable `id` (a short random slug) and ONE
 * condition (ProductConditions: categories, tags, seasons, brands, collections, on_sale; never `attributes`: they would cost a read per card) and a required text.
 *
 * A VALIDATED working copy is `{badges: list<Row>}` with ONE row for each built-in kind in the fixed order (SALE, NEW, LOW_STOCK, FREE_SHIPPING), then the custom rows in their order:
 *   Row = {kind, enabled: bool, text: string (≤ 24, plain; '' = the built-in's default word), position: top|image_bottom|bottom, colour: one of the six contrast pairs, shape: square|rounded|pill, opacity: 100|90|80|70 (70 only for black / orange / grey), priority: 1..99}
 *         + sale: `mode` word|percent · new: `days` 1..90 · low_stock: `max` 1..6 · custom: `id`, `conditions` (a non-empty ProductConditions set).
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

    /** P7-2: «Безплатна доставка» (threshold only: the card's lowest price is at or above the store's lowest free-shipping threshold; FreeShippingThreshold). */
    public const FREE_SHIPPING = 'free_shipping';

    /** P7-2: a merchant's own badge, shown on the products that match its condition. */
    public const CUSTOM = 'custom';

    /** The built-in kinds, in the fixed order of the zone. */
    public const KINDS = [self::SALE, self::NEW, self::LOW_STOCK, self::FREE_SHIPPING];

    public const MAX_CUSTOM = 12;

    /** The condition kinds a custom badge may use: the ones a listing page can answer from what it has or with ONE grouped read each (the `attributes` rule would need the variations' values). */
    public const CONDITION_KEYS = ['on_sale', 'categories', 'tags', 'seasons', 'brands', 'collections'];

    /** P7-1.2: `top` / `image_bottom` sit over the picture (upper-left / lower-left), `bottom` is under the price (what a stored `bottom` always meant). */
    public const POSITIONS = ['top', 'image_bottom', 'bottom'];

    /** P7-1.2: how many badges one position holds (the resolver keeps the first ones by priority, then kind order). */
    public const PER_POSITION = ['top' => 2, 'image_bottom' => 1, 'bottom' => 2];

    /**
     * P7-1.1: the SIX ready contrast pairs (background + text), a closed list. Every pair passes WCAG AA (4.5:1) for the small text at 100 / 90 / 80 % of the background over white (BadgeContrastTest computes it); the very
     * same values are the custom properties of `.sf-cbadge--{key}` in storefront.css (the same test compares them: one source of truth).
     */
    public const PAIRS = [
        'red' => ['bg' => '#be0f2c', 'fg' => '#ffffff'],
        'orange' => ['bg' => '#f59e0b', 'fg' => '#000000'],
        'blue' => ['bg' => '#1e40af', 'fg' => '#ffffff'],
        'green' => ['bg' => '#15612f', 'fg' => '#ffffff'],
        'black' => ['bg' => '#111111', 'fg' => '#ffffff'],
        'grey' => ['bg' => '#e5e5e5', 'fg' => '#111111'],
    ];

    public const COLOURS = ['red', 'orange', 'blue', 'green', 'black', 'grey'];

    /** A colour token stored by the first version of the zone, read as its closest pair (the admin offers only the six). */
    public const LEGACY_COLOURS = ['dark' => 'black', 'light' => 'grey', 'neutral' => 'grey', 'purple' => 'blue'];

    /** The opacity of the BACKGROUND in percent (the text stays fully opaque). */
    public const OPACITIES = [100, 90, 80, 70];

    /** P7-1.2: 70 % is allowed only for the pairs that still pass AA 4.5:1 at 70 % over white (black 7.07, orange 12.24, grey 16.1; red 3.96, blue 4.13, green 3.70 do not). */
    public const OPACITY_70_PAIRS = ['black', 'orange', 'grey'];

    public const SHAPES = ['square', 'rounded', 'pill'];

    public const MODES = ['word', 'percent'];

    public const MAX_TEXT = 24;

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 90;

    public const STOCK_CHOICES = [1, 2, 3, 4, 5, 6];

    /** The row a kind starts from: OFF, at its default position / colour / shape / priority and parameter. @return Row */
    public static function defaultRow(string $kind): array
    {
        $row = ['kind' => $kind, 'enabled' => false, 'text' => '', 'position' => in_array($kind, [self::LOW_STOCK, self::FREE_SHIPPING], true) ? 'bottom' : 'top', 'shape' => 'square', 'opacity' => 100];

        return match ($kind) {
            self::SALE => $row + ['colour' => 'red', 'priority' => 10, 'mode' => 'word'],
            self::NEW => $row + ['colour' => 'black', 'priority' => 20, 'days' => 14],
            self::FREE_SHIPPING => $row + ['colour' => 'green', 'priority' => 40],
            self::CUSTOM => ['id' => ''] + $row + ['colour' => 'black', 'priority' => 50, 'conditions' => []],
            default => $row + ['colour' => 'orange', 'priority' => 30, 'max' => 3],
        };
    }

    /** A new random id of a custom badge (a short slug: never shown, only the key of the row). */
    public static function newId(): string
    {
        return strtolower(substr(bin2hex(random_bytes(6)), 0, 10));
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

        foreach ($published[self::CUSTOM] ?? [] as $custom) {
            $rows[] = $custom;
        }

        return ['badges' => $rows];
    }

    /**
     * The storefront's READ of a stored (compiled) copy: the enabled rows by kind, or [] when the JSON is missing or does not validate (a hand-edited row hides every badge, it never prints something unsafe).
     *
     * @return array<string, Row|list<Row>> the built-ins by kind and, under `custom`, the enabled custom rows in their order
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

        // the storefront never asks the database: the ids of a condition are only checked for their shape
        [$clean, $errors] = self::validate($stored, false);

        if ($errors !== []) {
            return [];
        }

        $out = [];

        foreach ($clean['badges'] as $row) {
            if (! $row['enabled']) {
                continue;
            }

            $row['kind'] === self::CUSTOM ? $out[self::CUSTOM][] = $row : $out[$row['kind']] = $row;
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
     * Validates a working copy (the zone's form, a stored draft, a stored compiled copy): a closed key set, one row per built-in kind (a missing one is its default, a duplicate or an unknown kind is refused), up to MAX_CUSTOM
     * custom rows (each with its own valid, unique id, a required text and a non-empty condition), plain text, closed colour / shape / position lists, whole numbers in range. Returns the NORMALISED copy (the built-ins in the
     * fixed order, then the custom rows in their order) and the messages by path (`sale.text`, `custom.0.conditions` ...).
     *
     * @param  bool  $exists  whether the ids of a condition must exist now (the form, the draft) or only be well formed (the storefront reads a compiled copy: no query)
     * @return array{0: array{badges: list<Row>}, 1: array<string, string>}
     */
    public static function validate(mixed $input, bool $exists = true): array
    {
        $errors = [];
        $rows = is_array($input) && is_array($input['badges'] ?? null) ? array_values($input['badges']) : [];
        $byKind = [];
        $custom = [];

        if (! is_array($input) || ! is_array($input['badges'] ?? null)) {
            $errors['badges'] = self::message('bad_set');
        }

        foreach ($rows as $row) {
            $kind = is_array($row) && is_string($row['kind'] ?? null) ? $row['kind'] : null;

            if ($kind === self::CUSTOM) {
                $custom[] = $row;

                continue;
            }

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
            $out[] = isset($byKind[$kind]) ? self::row($kind, $byKind[$kind], $errors, $kind, $exists) : self::defaultRow($kind);
        }

        if (count($custom) > self::MAX_CUSTOM) {
            $errors['custom'] = self::message('too_many_custom');
            $custom = array_slice($custom, 0, self::MAX_CUSTOM);
        }

        $ids = [];

        foreach ($custom as $i => $raw) {
            $row = self::row(self::CUSTOM, $raw, $errors, "custom.{$i}", $exists);

            if (isset($ids[$row['id']]) && $row['id'] !== '') {
                $errors["custom.{$i}.id"] = self::customMessage($i, 'duplicate_id');
            }

            $ids[$row['id']] = true;
            $out[] = $row;
        }

        return [['badges' => $out], $errors];
    }

    /** @param  array<string, mixed>  $raw @param  array<string, string>  $errors @return Row */
    private static function row(string $kind, array $raw, array &$errors, string $p, bool $exists): array
    {
        $row = self::defaultRow($kind);
        $row['enabled'] = self::bool($raw['enabled'] ?? false, "{$p}.enabled", $errors);
        $row['text'] = self::text($raw['text'] ?? '', "{$p}.text", $errors);
        $row['position'] = self::choice($raw['position'] ?? null, self::POSITIONS, $row['position'], "{$p}.position", $errors);
        $colour = $raw['colour'] ?? null;
        $row['colour'] = self::choice(is_string($colour) ? (self::LEGACY_COLOURS[$colour] ?? $colour) : $colour, self::COLOURS, $row['colour'], "{$p}.colour", $errors);
        $row['opacity'] = self::opacity($raw['opacity'] ?? null, "{$p}.opacity", $errors);

        if (! isset($errors["{$p}.opacity"]) && $row['opacity'] === 70 && ! in_array($row['colour'], self::OPACITY_70_PAIRS, true)) {
            $errors["{$p}.opacity"] = self::message('opacity_70');
            $row['opacity'] = 100;
        }

        $row['shape'] = self::choice($raw['shape'] ?? null, self::SHAPES, $row['shape'], "{$p}.shape", $errors);
        $row['priority'] = self::whole($raw['priority'] ?? null, 1, 99, $row['priority'], "{$p}.priority", $errors);

        match ($kind) {
            self::SALE => $row['mode'] = self::choice($raw['mode'] ?? null, self::MODES, $row['mode'], 'sale.mode', $errors),
            self::NEW => $row['days'] = self::whole($raw['days'] ?? null, self::MIN_DAYS, self::MAX_DAYS, $row['days'], 'new.days', $errors),
            self::FREE_SHIPPING => null,
            self::CUSTOM => self::customFields($row, $raw, $errors, (int) substr($p, 7), $exists),
            default => $row['max'] = self::stockMax($raw['max'] ?? null, $row['max'], $errors),
        };

        return $row;
    }

    /** The parts only a custom row has: its id, the required text and the ONE condition. @param  Row  $row @param  array<string, mixed>  $raw @param  array<string, string>  $errors */
    private static function customFields(array &$row, array $raw, array &$errors, int $i, bool $exists): void
    {
        $p = "custom.{$i}";
        $id = $raw['id'] ?? null;

        if (is_string($id) && preg_match('/\A[a-z0-9]{6,16}\z/', $id) === 1) {
            $row['id'] = $id;
        } else {
            $errors["{$p}.id"] = self::customMessage($i, 'bad_id');
        }

        if ($row['text'] === '' && ! isset($errors["{$p}.text"])) {
            $errors["{$p}.text"] = self::customMessage($i, 'text_required');
        }

        $conditions = $raw['conditions'] ?? null;

        if (is_array($conditions) && array_diff(array_keys($conditions), self::CONDITION_KEYS) !== []) {
            $errors["{$p}.conditions"] = self::customMessage($i, 'condition_kind');

            return;
        }

        [$clean, $problems] = ProductConditions::validate($conditions, $exists);

        if ($problems !== []) {
            $errors["{$p}.conditions"] = self::customMessage($i, 'bad_condition').' '.reset($problems);

            return;
        }

        if ($clean === []) {
            $errors["{$p}.conditions"] = self::customMessage($i, 'condition_required');

            return;
        }

        $row['conditions'] = $clean;
    }

    /** «№3: …» — the message of a custom row names the row (the panel shows the errors of the repeater together). */
    private static function customMessage(int $i, string $code): string
    {
        return '№'.($i + 1).': '.self::message($code);
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

    /** The background opacity from the closed list 100 / 90 / 80 (a number or a numeric string); a missing value is 100. @param  array<string, string>  $errors */
    private static function opacity(mixed $value, string $path, array &$errors): int
    {
        if ($value === null) {
            return 100;
        }

        $number = self::number($value);

        if ($number === null || ! in_array($number, self::OPACITIES, true)) {
            $errors[$path] = self::message('bad_choice');

            return 100;
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
