<?php

namespace App\Storefront\Sections;

use Illuminate\Support\Str;

/**
 * P4b: the SECTIONS of the product page the merchant defines in «Дизайн» → «Секции на продукта» (Description, Individual size, Composition, Delivery and returns …): one ordered list for every product, at most MAX.
 *
 *   {sections: [ {key: uuid, title: string, source: description|global|product|product_or_global|meta|blocks, text: string (html), meta?: {seasons: bool, tags: bool}, blocks?: list<int>} ]}
 *
 * P4c: `meta` = the product's season and tags as links (the two switches in `meta`, both on by default); `blocks` = an ordered list of at most BLOCKS_MAX HTML blocks (`blocks`), of which the ones whose conditions
 * match the product are printed. `meta` / `blocks` are present only for their own source (a section of another source is stored as before).
 *
 * `source` says where the text of a section comes from: `global` = the section's own «общ текст» (the same for every product), `product` = the text of the product (the «Информация» tab of the product form),
 * `product_or_global` = the product's text, and when it has none the general one. The `text` is the rich-text editor's HTML (the one a product description is edited in); it is cleaned by the storefront's
 * description sanitiser when it is published and again when it is read. `key` is a stable uuid: renaming or moving a section keeps the products' texts, which are stored by it.
 *
 * SAVE validates strictly (a missing key is generated, errors by path `sections.2.title`); READ never fails: a section that does not validate is dropped, more than MAX are cut, an unknown key is dropped.
 * Pure: no query, no setting.
 *
 * @phpstan-type Section array{key: string, title: string, source: string, text: string, meta?: array{seasons: bool, tags: bool}, blocks?: list<int>}
 * @phpstan-type Set array{sections: list<Section>}
 */
final class SectionSet
{
    public const MAX = 8;

    public const TITLE_MAX = 60;

    /** The longest general text of a section (characters of HTML). */
    public const TEXT_MAX = 20_000;

    public const SOURCES = ['description', 'global', 'product', 'product_or_global', 'meta', 'blocks'];

    /** P4c: the most HTML blocks one `blocks` section holds. */
    public const BLOCKS_MAX = 10;

    /** @return Set no section: the product page it always was */
    public static function blank(): array
    {
        return ['sections' => []];
    }

    /** @param  Set  $set */
    public static function isBlank(array $set): bool
    {
        return $set['sections'] === [];
    }

    /** P4c: the ids of the HTML blocks the sections show. @param Set $set @return list<int> */
    public static function blockIds(array $set): array
    {
        $ids = [];

        foreach ($set['sections'] as $section) {
            foreach ($section['blocks'] ?? [] as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** Does a source take the product's own text? */
    public static function usesProductText(string $source): bool
    {
        return $source === 'product' || $source === 'product_or_global';
    }

    /** Does a source take the section's general text? */
    public static function usesGlobalText(string $source): bool
    {
        return $source === 'global' || $source === 'product_or_global';
    }

    /** Is it a section key (a lower-case uuid)? */
    public static function isKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $key) === 1;
    }

    /**
     * SAVE / PUBLISH: the whole set checked strictly. A section without a key gets a new one. Returns the clean set and the messages by path.
     *
     * @return array{0: Set, 1: array<string, string>}
     */
    public static function validate(mixed $value): array
    {
        $errors = [];

        if (! is_array($value)) {
            $errors['sections'] = (string) __('design.sections.errors.bad_set');

            return [self::blank(), $errors];
        }

        foreach (array_diff(array_keys($value), ['sections']) as $key) {
            $errors['sections'] ??= (string) __('design.sections.errors.unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $sections = $value['sections'] ?? [];

        if (! is_array($sections)) {
            $errors['sections'] ??= (string) __('design.sections.errors.bad_set');

            return [self::blank(), $errors];
        }

        $sections = array_values($sections);

        if (count($sections) > self::MAX) {
            $errors['sections'] ??= (string) __('design.sections.errors.too_many', ['max' => self::MAX]);
            $sections = array_slice($sections, 0, self::MAX);
        }

        $clean = [];
        $seen = [];

        foreach ($sections as $index => $section) {
            $one = self::section($section, 'sections.'.$index, static function (string $at, string $code, array $params = []) use (&$errors): void {
                $errors[$at] ??= (string) __('design.sections.errors.'.$code, $params);
            }, true);

            if ($one === null) {
                continue;
            }

            if (isset($seen[$one['key']])) {
                $errors['sections.'.$index] ??= (string) __('design.sections.errors.duplicate_key');

                continue;
            }

            $seen[$one['key']] = true;
            $clean[] = $one;
        }

        return [['sections' => $clean], $errors];
    }

    /**
     * READ: what a stored set is worth. Never an error: a section that does not validate is dropped (a missing key too: its texts could not be found), more than MAX are cut.
     *
     * @return Set
     */
    public static function read(mixed $stored): array
    {
        $sections = is_array($stored) && is_array($stored['sections'] ?? null) ? array_slice(array_values($stored['sections']), 0, self::MAX) : [];
        $clean = [];
        $seen = [];

        foreach ($sections as $section) {
            $failed = false;
            $one = self::section($section, 'section', static function () use (&$failed): void {
                $failed = true;
            }, false);

            if ($failed || $one === null || isset($seen[$one['key']])) {
                continue;
            }

            $seen[$one['key']] = true;
            $clean[] = $one;
        }

        return ['sections' => $clean];
    }

    /**
     * One section checked; `$fail(path, code, params)` for every problem. Null when the section is unusable.
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @param  bool  $generate  SAVE: a missing key is generated
     * @return Section|null
     */
    private static function section(mixed $section, string $path, callable $fail, bool $generate): ?array
    {
        if (! is_array($section)) {
            $fail($path, 'bad_section');

            return null;
        }

        foreach (array_diff(array_keys($section), ['key', 'title', 'source', 'text', 'meta', 'blocks']) as $key) {
            $fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $key = $section['key'] ?? null;

        if (($key === null || $key === '') && $generate) {
            $key = (string) Str::uuid();
        }

        if (! self::isKey($key)) {
            $fail($path.'.key', 'bad_key');

            return null;
        }

        $title = $section['title'] ?? '';

        if (! is_string($title) || preg_match('/\p{C}/u', $title) === 1) {
            $fail($path.'.title', 'bad_title');
            $title = '';
        }

        $title = trim((string) $title);
        $length = mb_strlen($title, 'UTF-8');

        if ($length < 1 || $length > self::TITLE_MAX) {
            $fail($path.'.title', $length < 1 ? 'title_required' : 'title_too_long', ['max' => self::TITLE_MAX]);

            return null;
        }

        $source = $section['source'] ?? null;

        if (! is_string($source) || ! in_array($source, self::SOURCES, true)) {
            $fail($path.'.source', 'bad_choice');

            return null;
        }

        $text = $section['text'] ?? '';

        if (! is_string($text) || mb_strlen($text, 'UTF-8') > self::TEXT_MAX) {
            $fail($path.'.text', is_string($text) ? 'text_too_long' : 'bad_choice', ['max' => self::TEXT_MAX]);

            return null;
        }

        $one = ['key' => (string) $key, 'title' => $title, 'source' => $source, 'text' => self::usesGlobalText($source) ? $text : ''];

        // P4c: the settings of the two new sources (only for their own source; any other source is stored as it always was)
        if ($source === 'meta') {
            $meta = $section['meta'] ?? ['seasons' => true, 'tags' => true];

            if (! is_array($meta) || array_diff(array_keys($meta), ['seasons', 'tags']) !== [] || ! is_bool($meta['seasons'] ?? true) || ! is_bool($meta['tags'] ?? true)) {
                $fail($path.'.meta', 'bad_choice');

                return null;
            }

            $one['meta'] = ['seasons' => $meta['seasons'] ?? true, 'tags' => $meta['tags'] ?? true];
        }

        if ($source === 'blocks') {
            $blocks = $section['blocks'] ?? [];

            if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > self::BLOCKS_MAX || count(array_filter($blocks, static fn (mixed $id): bool => is_int($id) && $id > 0)) !== count($blocks) || count(array_unique($blocks)) !== count($blocks)) {
                $fail($path.'.blocks', 'bad_blocks', ['max' => self::BLOCKS_MAX]);

                return null;
            }

            if ($blocks === [] && $generate) {
                $fail($path.'.blocks', 'blocks_required');

                return null;
            }

            $one['blocks'] = $blocks;
        }

        return $one;
    }
}
