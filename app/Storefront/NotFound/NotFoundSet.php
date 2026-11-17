<?php

namespace App\Storefront\NotFound;

use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeValidator;

/**
 * NF-1: what «Дизайн» → «Страница 404» stores: the content of the storefront's 404 page. A closed shape, the ONE definition of it (the admin saves it, the storefront reads it):
 *
 *   {title: string ≤ 80, text: string ≤ 300 (plain text), search: bool, bottom: none|tiles|products|block, tiles: 4|6|8|12,
 *    products: {source, entity, count, sort, columns, columns_phone, gap}   (the HOME page product grid's own settings, validated by HomeValidator),
 *    block: int|null (an HTML block id), button: bool}
 *
 * SAVE / PUBLISH (validate()) checks strictly and reports the messages by path; READ (read()) never fails: every bad value is its default, the text is cut to its length, an unknown key is dropped, a product-grid
 * setting that does not validate makes the whole grid its default. Pure: no query, no setting (that an HTML block still exists is the page's question: a block that is gone prints nothing).
 *
 * @phpstan-type Set array{title: string, text: string, search: bool, bottom: string, tiles: string, products: array<string, mixed>, block: int|null, button: bool}
 */
final class NotFoundSet
{
    public const TITLE_MAX = 80;

    public const TEXT_MAX = 300;

    /** «Съдържание отдолу»: nothing, the category tiles, a product grid or an HTML block. */
    public const BOTTOMS = ['none', 'tiles', 'products', 'block'];

    public const DEFAULT_BOTTOM = 'tiles';

    public const TILE_COUNTS = ['4', '6', '8', '12'];

    public const DEFAULT_TILES = '8';

    /** NF-1.1: the most categories the owner can choose for the tiles (an empty list = the top-level categories, as before). */
    public const CATEGORIES_MAX = 12;

    /** The settings of the product grid the 404 holds: the home page product grid's schema, without the two every section has. */
    public static function productKeys(): array
    {
        return array_keys(HomeCatalog::all()['product_grid']['schema']);
    }

    /** @return array<string, mixed> the product grid's defaults (the home grid's own) */
    public static function productDefaults(): array
    {
        return array_intersect_key(HomeCatalog::defaultsFor('product_grid'), array_flip(self::productKeys()));
    }

    /** @return Set what the page shows when nothing is published: the texts come from the lang files (the shop's language) */
    public static function defaults(): array
    {
        return [
            'title' => (string) __('design.notfound.default_title'),
            'text' => (string) __('design.notfound.default_text'),
            'search' => true,
            'bottom' => self::DEFAULT_BOTTOM,
            'tiles' => self::DEFAULT_TILES,
            'categories' => [],
            'products' => self::productDefaults(),
            'block' => null,
            'button' => true,
        ];
    }

    /**
     * SAVE / PUBLISH: the whole set checked strictly. Returns the clean set and the messages by path.
     *
     * @return array{0: Set, 1: array<string, string>}
     */
    public static function validate(mixed $value): array
    {
        $errors = [];
        $fail = static function (string $path, string $code, array $params = []) use (&$errors): void {
            $errors[$path] ??= (string) __('design.notfound.errors.'.$code, $params);
        };
        $set = self::defaults();

        if (! is_array($value)) {
            $fail('title', 'bad_set');

            return [$set, $errors];
        }

        foreach (array_diff(array_keys($value), array_keys($set)) as $key) {
            $fail('title', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $title = self::text($value['title'] ?? null, self::TITLE_MAX, false);

        if ($title === false) {
            $fail('title', 'bad_text');
        } elseif ($title === '') {
            $fail('title', 'title_required');
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $fail('title', 'title_too_long', ['max' => self::TITLE_MAX]);
        } else {
            $set['title'] = $title;
        }

        $text = self::text($value['text'] ?? '', self::TEXT_MAX, true);

        if ($text === false) {
            $fail('text', 'bad_text');
        } elseif (mb_strlen($text) > self::TEXT_MAX) {
            $fail('text', 'text_too_long', ['max' => self::TEXT_MAX]);
        } else {
            $set['text'] = $text;
        }

        foreach (['search', 'button'] as $key) {
            if (! is_bool($value[$key] ?? $set[$key])) {
                $fail($key, 'bad_choice');
            } else {
                $set[$key] = (bool) ($value[$key] ?? $set[$key]);
            }
        }

        $bottom = $value['bottom'] ?? $set['bottom'];

        if (! is_string($bottom) || ! in_array($bottom, self::BOTTOMS, true)) {
            $fail('bottom', 'bad_choice');
        } else {
            $set['bottom'] = $bottom;
        }

        $tiles = $value['tiles'] ?? $set['tiles'];
        $tiles = is_int($tiles) ? (string) $tiles : $tiles;

        if (! is_string($tiles) || ! in_array($tiles, self::TILE_COUNTS, true)) {
            $fail('tiles', 'bad_choice');
        } else {
            $set['tiles'] = $tiles;
        }

        // NF-1.1: the chosen categories: a list of ids, no duplicates, at most CATEGORIES_MAX; that they exist (and show) is the page's question, not the save's
        $categories = $value['categories'] ?? [];

        if (! is_array($categories) || ! array_is_list($categories) || array_filter($categories, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
            $fail('categories', 'bad_categories');
        } elseif (count(array_unique($categories)) !== count($categories)) {
            $fail('categories', 'duplicate_categories');
        } elseif (count($categories) > self::CATEGORIES_MAX) {
            $fail('categories', 'too_many_categories', ['max' => self::CATEGORIES_MAX]);
        } else {
            $set['categories'] = $categories;
        }

        if (array_key_exists('products', $value)) {
            [$products, $problems] = self::products($value['products']);

            foreach ($problems as $at => $message) {
                $fail($at === '' ? 'products' : 'products.'.$at, 'bad_products', ['message' => $message]);
            }

            $problems === [] && $set['products'] = $products;
        }

        $block = $value['block'] ?? null;

        if ($block !== null && (! is_int($block) || $block < 1)) {
            $fail('block', 'bad_block');
        } else {
            $set['block'] = $block;
        }

        // the choice needs its own value: an HTML block that is not chosen, a grid whose source needs an item and has none
        if ($set['bottom'] === 'block' && $set['block'] === null) {
            $fail('block', 'bad_block');
        }

        return [$set, $errors];
    }

    /**
     * READ: what a stored set is worth. Never an error: a bad value is its default, the text is cut, an unknown key is dropped, a bad product-grid setting makes the grid its default, a bad block id is none.
     *
     * @return Set
     */
    public static function read(mixed $stored): array
    {
        $set = self::defaults();

        if (! is_array($stored)) {
            return $set;
        }

        $title = self::text($stored['title'] ?? null, self::TITLE_MAX, false);
        $title = is_string($title) ? trim(mb_substr($title, 0, self::TITLE_MAX)) : '';
        $title === '' || $set['title'] = $title;

        $text = self::text($stored['text'] ?? null, self::TEXT_MAX, true);

        if (is_string($text) && array_key_exists('text', $stored)) {
            $set['text'] = trim(mb_substr($text, 0, self::TEXT_MAX));
        }

        foreach (['search', 'button'] as $key) {
            is_bool($stored[$key] ?? null) && $set[$key] = $stored[$key];
        }

        is_string($stored['bottom'] ?? null) && in_array($stored['bottom'], self::BOTTOMS, true) && $set['bottom'] = $stored['bottom'];

        $tiles = is_int($stored['tiles'] ?? null) ? (string) $stored['tiles'] : ($stored['tiles'] ?? null);
        is_string($tiles) && in_array($tiles, self::TILE_COUNTS, true) && $set['tiles'] = $tiles;

        // NF-1.1: a bad list is an empty list; a bad id, a duplicate or a list past the maximum is dropped (the rest is kept, the first CATEGORIES_MAX)
        if (is_array($stored['categories'] ?? null) && array_is_list($stored['categories'])) {
            $ids = [];

            foreach ($stored['categories'] as $id) {
                is_int($id) && $id > 0 && ! in_array($id, $ids, true) && $ids[] = $id;
            }

            $set['categories'] = array_slice($ids, 0, self::CATEGORIES_MAX);
        }

        if (array_key_exists('products', $stored)) {
            [$products, $problems] = self::products($stored['products']);
            $problems === [] && $set['products'] = $products;
        }

        is_int($stored['block'] ?? null) && $stored['block'] > 0 && $set['block'] = $stored['block'];

        return $set;
    }

    /** The set a bottom «block» needs: the id of the HTML block, or null. @param Set $set */
    public static function blockId(array $set): ?int
    {
        return $set['bottom'] === 'block' ? $set['block'] : null;
    }

    /**
     * A text field: a string without control characters (a line break is kept in the long text, `\r\n` is `\n`), trimmed. False = not a text / has a control character.
     * No HTML is removed here: the text is plain text and escaped when it is printed.
     */
    private static function text(mixed $value, int $max, bool $multiline): string|false
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            return false;
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (preg_match($multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', $value) === 1) {
            return false;
        }

        return trim($value);
    }

    /**
     * The product grid's settings through the HOME layout's own validator (one section, `product_grid`): one set of rules, no second implementation. Unknown keys are not accepted on save and dropped on read by
     * the caller's choice (here: reported). Returns the clean settings and the messages by field.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function products(mixed $value): array
    {
        if (! is_array($value)) {
            return [self::productDefaults(), ['' => (string) __('design.notfound.errors.bad_set')]];
        }

        $problems = [];

        foreach (array_diff(array_keys($value), self::productKeys()) as $key) {
            $problems[''] ??= (string) __('design.notfound.errors.unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $settings = array_intersect_key($value, array_flip(self::productKeys()));
        [$clean, $errors] = HomeValidator::validate(['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [['type' => 'product_grid', 'settings' => $settings]]]);

        foreach ($errors as $at => $message) {
            $problems[str_contains($at, '.settings.') ? substr($at, strrpos($at, '.') + 1) : ''] ??= $message;
        }

        $one = $clean['sections'][0]['settings'] ?? null;

        if (! is_array($one)) {
            return [self::productDefaults(), $problems === [] ? ['' => (string) __('design.notfound.errors.bad_set')] : $problems];
        }

        $clean = array_intersect_key($one, array_flip(self::productKeys()));

        // a source that needs an item and has none shows nothing: refused on save
        $source = (string) ($clean['source'] ?? 'newest');

        if (in_array($source, HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) && ! is_int($clean['entity'] ?? null)) {
            $problems['entity'] ??= (string) __('design.notfound.errors.entity_required');
        }

        return [$clean, $problems];
    }
}
