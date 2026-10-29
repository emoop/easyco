<?php

namespace App\Storefront\Conditions;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * «Показвай, когато…» (HB-2): the product conditions of an HTML block AND the named predicates a theme calls from Blade (`@productIs('on_sale')`): ONE logic in ONE place. The admin's conditions are
 * evaluated through the very same predicates the directive uses; there is no second copy.
 *
 * PREDICATES (name => function (ProductContext, list<mixed> $params): bool):
 *   on_sale        [bool = true]            the product is (true) / is not (false) on sale: the price block's own regular price
 *   in_category    [id | list<id>]          the product is in ANY of these categories or in a DESCENDANT of one
 *   has_tag        [id | list<id>]          the product has ANY of these tags
 *   in_season      [id | list<id>]          the product's season is ANY of these
 *   has_attribute  [attribute id, value id | list<value id> = any value]   the product (its shown variations) has ANY of the values of that attribute
 * There is no «new» predicate: the shop has no concept of a new product (no flag, no time window).
 *
 * CONDITIONS (stored as the block's JSON `conditions`, null = none): `on_sale` true|false (a `false` IS a condition), `categories` (≤ 50 ids), `tags` (≤ 50), `seasons` (≤ 20), `attributes` (≤ 5 rules
 * `{attribute, values ≤ 20}`, an attribute once). ALL set conditions must hold (AND); inside a list the values are alternatives (OR).
 */
final class ProductConditions
{
    public const MAX_CATEGORIES = 50;

    public const MAX_TAGS = 50;

    public const MAX_SEASONS = 20;

    public const MAX_RULES = 5;

    public const MAX_VALUES = 20;

    public const KEYS = ['on_sale', 'categories', 'tags', 'seasons', 'attributes'];

    private static ?bool $hasColumn = null;

    /** @return array<string, Closure(ProductContext, list<mixed>): bool> */
    public static function predicates(): array
    {
        return [
            'on_sale' => static fn (ProductContext $c, array $p): bool => $c->onSale() === (($p[0] ?? true) !== false),
            'in_category' => static fn (ProductContext $c, array $p): bool => array_intersect(self::ids($p), $c->categoryIds()) !== [],
            'has_tag' => static fn (ProductContext $c, array $p): bool => array_intersect(self::ids($p), $c->tagIds()) !== [],
            'in_season' => static fn (ProductContext $c, array $p): bool => $c->seasonId() !== null && in_array($c->seasonId(), self::ids($p), true),
            'has_attribute' => static function (ProductContext $c, array $p): bool {
                $own = $c->attributeValues()[(int) ($p[0] ?? 0)] ?? [];
                $wanted = self::ids(array_slice($p, 1));

                return $own !== [] && ($wanted === [] || array_intersect($wanted, $own) !== []);
            },
        ];
    }

    /**
     * The theme's entry point: `@productIs('on_sale')`. Outside a product page it is false. A predicate name that does not exist is a developer error: it throws everywhere but in production,
     * where it is false (the page must never break).
     *
     * @throws InvalidArgumentException
     */
    public static function is(string $name, mixed ...$params): bool
    {
        $predicate = self::predicates()[$name] ?? null;

        if ($predicate === null) {
            if (app()->isProduction()) {
                return false;
            }

            throw new InvalidArgumentException('There is no product predicate "'.mb_substr($name, 0, 40).'". Known: '.implode(', ', array_keys(self::predicates())).'.');
        }

        $context = app(CurrentProduct::class)->get();

        return $context !== null && $predicate($context, array_values($params));
    }

    /**
     * Do the conditions hold for this product? null / nothing set = yes. A stored value that is not structurally valid never matches (a hand-edited row hides the block, it never shows it by mistake).
     *
     * @param  array<string, mixed>|false|null  $conditions
     */
    public static function matches(ProductContext $context, array|false|null $conditions): bool
    {
        if ($conditions === null) {
            return true;
        }

        if ($conditions === false) {
            return false;
        }

        [$clean, $errors] = self::validate($conditions, false);

        if ($errors !== []) {
            return false;
        }

        $predicates = self::predicates();
        $checks = [];

        array_key_exists('on_sale', $clean) && $checks[] = $predicates['on_sale']($context, [$clean['on_sale']]);

        foreach (['categories' => 'in_category', 'tags' => 'has_tag', 'seasons' => 'in_season'] as $key => $predicate) {
            ($clean[$key] ?? []) !== [] && $checks[] = $predicates[$predicate]($context, [$clean[$key]]);
        }

        foreach ($clean['attributes'] ?? [] as $rule) {
            $checks[] = $predicates['has_attribute']($context, [$rule['attribute'], $rule['values']]);
        }

        // ALL the set conditions must hold (AND); the alternatives (OR) are the values inside one list
        return ! in_array(false, $checks, true);
    }

    // ---- validation ----------------------------------------------------------------------------------------------------------------------

    /**
     * Validates conditions as they come from the form or the database: a closed key set, whole positive ids (a list that is too long, an id that does not exist, an attribute twice are refused),
     * duplicates removed. `$exists` says whether the ids must exist now (the form, the model) or only be well formed (the storefront reads a compiled copy: no query). Returns the NORMALISED
     * conditions (only the keys that are set; ids ascending) and the messages by path.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(mixed $conditions, bool $exists = true): array
    {
        if ($conditions === null || $conditions === []) {
            return [[], []];
        }

        if (! is_array($conditions)) {
            return [[], ['conditions' => self::message('bad_value')]];
        }

        $clean = [];
        $errors = [];

        foreach (array_diff(array_keys($conditions), self::KEYS) as $key) {
            $errors['conditions'] ??= self::message('unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        if (array_key_exists('on_sale', $conditions) && $conditions['on_sale'] !== null) {
            is_bool($conditions['on_sale']) ? $clean['on_sale'] = $conditions['on_sale'] : $errors['on_sale'] = self::message('bad_boolean');
        }

        foreach (['categories' => [self::MAX_CATEGORIES, 'catalog_categories'], 'tags' => [self::MAX_TAGS, 'catalog_tags'], 'seasons' => [self::MAX_SEASONS, 'catalog_seasons']] as $key => [$max, $table]) {
            $ids = self::idList($conditions[$key] ?? null, $max, $key, $errors);

            if ($ids === []) {
                continue;
            }

            if ($exists && ! isset($errors[$key]) && ($missing = array_diff($ids, self::existing($table, $ids))) !== []) {
                $errors[$key] = self::message('unknown_id', ['id' => reset($missing)]);

                continue;
            }

            isset($errors[$key]) || $clean[$key] = $ids;
        }

        $rules = self::rules($conditions['attributes'] ?? null, $exists, $errors);
        $rules === [] || isset($errors['attributes']) || $clean['attributes'] = $rules;

        return [$clean, $errors];
    }

    /** Is any condition set? @param  array<string, mixed>  $clean  NORMALISED conditions (validate()) */
    public static function isSet(array $clean): bool
    {
        return $clean !== [];
    }

    /**
     * What is stored: the normalised conditions, or null when nothing is set. Throws on conditions that do not validate (the form validates first and shows field errors).
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException
     */
    public static function storable(mixed $conditions): ?array
    {
        [$clean, $errors] = self::validate($conditions);

        if ($errors !== []) {
            throw new InvalidArgumentException('The block conditions are not valid.');
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * The conditions of a stored value for the compiled copy: the normalised set conditions, `false` when the stored value is not structurally valid (the block is then hidden), null when none.
     *
     * @return array<string, mixed>|false|null
     */
    public static function active(mixed $stored): array|false|null
    {
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        if ($stored === null || $stored === []) {
            return null;
        }

        [$clean, $errors] = self::validate($stored, false);

        return $errors !== [] ? false : ($clean === [] ? null : $clean);
    }

    /**
     * The conditions of these blocks (id => conditions or false): only the blocks that have some. One query; nothing when the column is not there yet.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>|false>
     */
    public static function storedFor(array $ids): array
    {
        if ($ids === [] || ! self::hasColumn()) {
            return [];
        }

        $out = [];

        foreach (DB::table('html_blocks')->whereIn('id', $ids)->whereNotNull('conditions')->orderBy('id')->get(['id', 'conditions']) as $row) {
            $conditions = self::active($row->conditions);

            $conditions === null || $out[(int) $row->id] = $conditions;
        }

        return $out;
    }

    /** Is the `conditions` column there (the migration may not have run yet: the code then behaves as if no block had conditions)? */
    public static function hasColumn(): bool
    {
        return self::$hasColumn ??= Schema::hasColumn('html_blocks', 'conditions');
    }

    // ---- the form ---------------------------------------------------------------------------------------------------------------------------

    /**
     * The form's state for stored conditions: the yes/no of «Промоция» as `all|yes|no`, the lists, the attribute rules. An id that does not exist any more is dropped (it would match nothing).
     *
     * @return array<string, mixed>
     */
    public static function formState(mixed $stored): array
    {
        $conditions = self::active($stored);
        $conditions = is_array($conditions) ? $conditions : [];
        $state = ['on_sale' => ! array_key_exists('on_sale', $conditions) ? 'all' : ($conditions['on_sale'] ? 'yes' : 'no')];

        foreach (['categories' => 'catalog_categories', 'tags' => 'catalog_tags', 'seasons' => 'catalog_seasons'] as $key => $table) {
            $state[$key] = self::existing($table, $conditions[$key] ?? []);
        }

        $state['attributes'] = [];

        foreach ($conditions['attributes'] ?? [] as $rule) {
            if (self::existing('catalog_attribute_definitions', [$rule['attribute']]) === []) {
                continue;
            }

            $values = DB::table('catalog_attribute_values')->where('attribute_definition_id', $rule['attribute'])->whereIn('id', $rule['values'])->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $values === [] || $state['attributes'][] = ['attribute' => $rule['attribute'], 'values' => $values];
        }

        return $state;
    }

    /**
     * The form's state as raw conditions (not validated): `all` = no condition, the lists as they are, a rule that is empty on both sides dropped.
     *
     * @return array<string, mixed>
     */
    public static function fromForm(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $raw = [];

        match ($state['on_sale'] ?? 'all') {
            'yes' => $raw['on_sale'] = true,
            'no' => $raw['on_sale'] = false,
            default => null,
        };

        foreach (['categories', 'tags', 'seasons'] as $key) {
            is_array($state[$key] ?? null) && $state[$key] !== [] && $raw[$key] = array_values($state[$key]);
        }

        $rules = [];

        foreach (is_array($state['attributes'] ?? null) ? $state['attributes'] : [] as $rule) {
            if (! is_array($rule) || (($rule['attribute'] ?? null) === null || $rule['attribute'] === '') && ($rule['values'] ?? []) === []) {
                continue;
            }

            $rules[] = ['attribute' => $rule['attribute'] ?? null, 'values' => is_array($rule['values'] ?? null) ? array_values($rule['values']) : []];
        }

        $rules === [] || $raw['attributes'] = $rules;

        return $raw;
    }

    // ---- internals ---------------------------------------------------------------------------------------------------------------------------

    /** A whole positive id: an int, or a string of digits (no sign, no spaces, at most 10 digits); anything else is null. */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 4_294_967_295 ? $value : null;
        }

        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1 && (int) $value <= 4_294_967_295 ? (int) $value : null;
    }

    /** Ids out of a predicate's parameters (ints, digit strings, or lists of them), positive and unique. @param  array<int, mixed>  $params @return list<int> */
    private static function ids(array $params): array
    {
        $out = [];

        array_walk_recursive($params, static function (mixed $value) use (&$out): void {
            $id = self::id($value);

            $id === null || $out[$id] = $id;
        });

        return array_values($out);
    }

    /**
     * A list of ids from the form: a list (not a map), at most `$max` AFTER the duplicates went, every id well formed. Sets the error under `$key`.
     *
     * @param  array<string, string>  $errors
     * @return list<int> ascending
     */
    private static function idList(mixed $value, int $max, string $key, array &$errors): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $errors[$key] = self::message('bad_list');

            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = self::id($item);

            if ($id === null) {
                $errors[$key] ??= self::message('bad_id');

                continue;
            }

            $ids[$id] = $id;
        }

        if (count($ids) > $max) {
            $errors[$key] ??= self::message('too_many', ['max' => $max]);
        }

        sort($ids);

        return array_values($ids);
    }

    /**
     * The attribute rules: at most MAX_RULES, an attribute once, its values (1 to MAX_VALUES) existing and belonging to it.
     *
     * @param  array<string, string>  $errors
     * @return list<array{attribute: int, values: list<int>}>
     */
    private static function rules(mixed $value, bool $exists, array &$errors): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $errors['attributes'] = self::message('bad_list');

            return [];
        }

        if (count($value) > self::MAX_RULES) {
            $errors['attributes'] = self::message('too_many_rules', ['max' => self::MAX_RULES]);

            return [];
        }

        $rules = [];

        foreach ($value as $rule) {
            if (! is_array($rule) || array_diff(array_keys($rule), ['attribute', 'values']) !== []) {
                $errors['attributes'] ??= self::message('bad_rule');

                continue;
            }

            $attribute = self::id($rule['attribute'] ?? null);

            if ($attribute === null) {
                $errors['attributes'] ??= self::message('bad_attribute');

                continue;
            }

            if (isset($rules[$attribute])) {
                $errors['attributes'] ??= self::message('duplicate_attribute');

                continue;
            }

            $local = [];
            $values = self::idList($rule['values'] ?? null, self::MAX_VALUES, 'attributes', $local);

            if ($local !== []) {
                $errors['attributes'] ??= reset($local);

                continue;
            }

            if ($values === []) {
                $errors['attributes'] ??= self::message('values_required');

                continue;
            }

            if ($exists) {
                $known = self::existing('catalog_attribute_definitions', [$attribute]) !== []
                    && DB::table('catalog_attribute_values')->where('attribute_definition_id', $attribute)->whereIn('id', $values)->count() === count($values);

                if (! $known) {
                    $errors['attributes'] ??= self::message('unknown_value');

                    continue;
                }
            }

            $rules[$attribute] = ['attribute' => $attribute, 'values' => $values];
        }

        ksort($rules);

        return array_values($rules);
    }

    /** The ids of `$table` among these that exist. @param  array<int, mixed>  $ids @return list<int> */
    private static function existing(string $table, array $ids): array
    {
        $ids = array_values(array_filter(array_map([self::class, 'id'], $ids)));

        if ($ids === []) {
            return [];
        }

        $found = DB::table($table)->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($found);

        return $found;
    }

    /** @param  array<string, mixed>  $params */
    private static function message(string $code, array $params = []): string
    {
        return (string) __('html_blocks.conditions.errors.'.$code, $params);
    }
}
