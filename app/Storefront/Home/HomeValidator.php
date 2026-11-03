<?php

namespace App\Storefront\Home;

use App\Content\HtmlBlocks\HtmlBlockPictures;
use App\Storefront\Design\FieldKinds;

/**
 * Validates the home layout and returns it NORMALISED (HP-1). It runs on «Приложи» (the admin), when the layout is compiled and again every time the storefront reads the compiled copy: an unknown key, a
 * type that is not in the catalog, more than 12 sections, a spacing outside the closed list, a bad heading level, a text over 120 characters or with HTML, or an HTML block that is not chosen is REFUSED
 * (the page then prints the old home page). Paths: `spacing`, `sections` (the list as a whole), `sections.{i}` (one section), `sections.{i}.settings.{name}`.
 */
final class HomeValidator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, true> HP-2: the paths of an image grid's settings: a bad value there falls back to its own default (the other settings stay), so READING the layout does not refuse it for them */
    private array $soft = [];

    /**
     * @param  array<string, mixed>  $layout
     * @return array{0: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, 1: array<string, string>} the normalised layout and the messages by path
     */
    public static function validate(array $layout): array
    {
        $validator = new self;
        $clean = $validator->layout($layout);

        return [$clean, $validator->errors];
    }

    /**
     * HP-2: the same as validate(), for READING a stored layout: an image grid's bad value is replaced by its default (validate() reports it, the page must still print the other settings), so only the
     * errors that really make the layout unusable are returned.
     *
     * @param  array<string, mixed>  $layout
     * @return array{0: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, 1: array<string, string>}
     */
    public static function validateForRead(array $layout): array
    {
        $validator = new self;
        $clean = $validator->layout($layout);

        return [$clean, array_diff_key($validator->errors, $validator->soft)];
    }

    /** @param  array<string, mixed>  $params */
    private function fail(string $path, string $code, array $params = []): void
    {
        // the first message of a path is the useful one
        $this->errors[$path] ??= (string) __('design.home.errors.'.$code, $params);
    }

    /** @param  array<string, mixed>  $layout */
    private function layout(array $layout): array
    {
        foreach (array_diff(array_keys($layout), ['spacing', 'sections']) as $key) {
            $this->fail('sections', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $spacing = $layout['spacing'] ?? HomeCatalog::DEFAULT_SPACING;

        if (! is_string($spacing) || ! in_array($spacing, HomeCatalog::SPACINGS, true)) {
            $this->fail('spacing', 'bad_choice');
            $spacing = HomeCatalog::DEFAULT_SPACING;
        }

        $list = $layout['sections'] ?? [];

        if (! is_array($list) || ! array_is_list($list)) {
            $this->fail('sections', 'bad_list');

            return ['spacing' => $spacing, 'sections' => []];
        }

        if (count($list) > HomeCatalog::MAX_SECTIONS) {
            $this->fail('sections', 'too_many', ['max' => HomeCatalog::MAX_SECTIONS]);
            $list = array_slice($list, 0, HomeCatalog::MAX_SECTIONS);
        }

        $catalog = HomeCatalog::all();
        $clean = [];

        foreach ($list as $i => $section) {
            $one = $this->section($section, "sections.{$i}", $catalog);
            $one === null || $clean[] = $one;
        }

        return ['spacing' => $spacing, 'sections' => $clean];
    }

    /**
     * @param  array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string}>  $catalog
     * @return array{type: string, settings: array<string, mixed>}|null
     */
    private function section(mixed $section, string $path, array $catalog): ?array
    {
        if (! is_array($section)) {
            $this->fail($path, 'bad_element');

            return null;
        }

        foreach (array_diff(array_keys($section), ['type', 'settings']) as $key) {
            $this->fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $type = $section['type'] ?? null;

        if (! is_string($type) || ! isset($catalog[$type])) {
            $this->fail($path, 'unknown_type');

            return null;
        }

        $settings = $section['settings'] ?? [];

        if (! is_array($settings)) {
            $this->fail("{$path}.settings", 'bad_element');

            return null;
        }

        $schema = HomeCatalog::common() + $catalog[$type]['schema'];

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];
        $soft = in_array($type, ['image_grid', 'tiles'], true);
        $failer = function (string $p, string $code, array $params = []) use ($soft): void {
            $this->fail($p, $code, $params);
            $soft && $this->soft[$p] = true;
        };

        foreach ($schema as $name => $field) {
            if (($field['kind'] ?? null) === 'tiles') {
                $clean[$name] = $this->tiles($settings[$name] ?? [], "{$path}.settings.{$name}", $failer);

                continue;
            }

            if (($field['kind'] ?? null) === 'cells') {
                $clean[$name] = $this->cells($settings[$name] ?? [], (string) ($clean['scheme'] ?? ImageGridGeometry::DEFAULT_SCHEME), "{$path}.settings.{$name}", $failer);

                continue;
            }

            $clean[$name] = FieldKinds::validate($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}", $failer);
        }

        // a heading is PLAIN text: a tag, even one the plain-text rule let through, is refused
        if (isset($clean['text']) && is_string($clean['text']) && $clean['text'] !== strip_tags($clean['text'])) {
            $this->fail("{$path}.settings.text", 'not_plain');
            $clean['text'] = '';
        }

        return ['type' => $type, 'settings' => $clean];
    }

    /**
     * HP-3: the manual list of tiles of a «Плочки» section: 1 to 12, each tile validated by tile(). A tile that does not validate is DROPPED from the result (and reported): saving refuses the layout, reading
     * prints the tiles that are fine.
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return list<array{source: string, entity: int|null, label: string, picture: int|null, link: string|null}>
     */
    private function tiles(mixed $value, string $path, callable $fail): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $fail($path, 'bad_list', []);

            return [];
        }

        if ($value === []) {
            $fail($path, 'too_few_tiles', []);
        }

        if (count($value) > HomeCatalog::TILES_MAX) {
            $fail($path, 'too_many_tiles', ['max' => HomeCatalog::TILES_MAX]);
            $value = array_slice($value, 0, HomeCatalog::TILES_MAX);
        }

        $out = [];

        foreach ($value as $i => $tile) {
            $one = $this->tile($tile, "{$path}.{$i}", $fail);
            $one === null || $out[] = $one;
        }

        return $out;
    }

    /**
     * One tile: its source (a closed list), the entity (a whole number, but for a free tile), the caption (plain text, at most 60; a free tile needs one), the picture (the library's id; a free tile needs one) and the
     * link (the shop's own address or https:, never javascript:; a season, a tag, a brand and a free tile have no page of their own, so the link is required). Null = the tile is not valid.
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return array{source: string, entity: int|null, label: string, picture: int|null, link: string|null}|null
     */
    private function tile(mixed $tile, string $path, callable $fail): ?array
    {
        $bad = false;
        $report = function (string $p, string $code, array $params = []) use (&$bad, $fail): void {
            $bad = true;
            $fail($p, $code, $params);
        };

        if (! is_array($tile)) {
            $report($path, 'bad_element');

            return null;
        }

        foreach (array_diff(array_keys($tile), ['source', 'entity', 'label', 'picture', 'link']) as $key) {
            $report($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $source = $tile['source'] ?? null;

        if (! is_string($source) || ! in_array($source, HomeCatalog::TILE_SOURCES, true)) {
            $report("{$path}.source", 'bad_choice');

            return null;
        }

        $custom = $source === 'custom';
        $entity = $custom ? null : FieldKinds::reference($tile['entity'] ?? null, true, "{$path}.entity", $report);

        $label = FieldKinds::text($tile['label'] ?? '', HomeCatalog::TILE_LABEL_MAX, $custom, "{$path}.label", $report);

        if ($label !== strip_tags($label)) {
            $report("{$path}.label", 'not_plain');
            $label = '';
        }

        $picture = FieldKinds::reference($tile['picture'] ?? null, $custom, "{$path}.picture", $report);

        $link = $tile['link'] ?? null;
        $link = is_string($link) && trim($link) !== '' ? trim($link) : null;

        if ($link !== null) {
            $safe = HtmlBlockPictures::safeUrl($link);
            $safe === null ? $report("{$path}.link", 'bad_link') : $link = $safe;
        } elseif (! in_array($source, HomeCatalog::SOURCES_WITH_PAGE, true)) {
            $report("{$path}.link", 'link_required');
        }

        return $bad ? null : ['source' => $source, 'entity' => $entity, 'label' => $label, 'picture' => $picture, 'link' => $link];
    }

    /**
     * HP-2: the cells of an image grid: a map from a cell name of the CHOSEN scheme to an HTML block id or nothing. A name that is not in the scheme is dropped (and reported); a value that is not an id is
     * refused (that cell is empty). Every cell of the scheme is in the result, in reading order.
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return array<string, int|null>
     */
    private function cells(mixed $value, string $scheme, string $path, callable $fail): array
    {
        $names = ImageGridGeometry::names($scheme);
        $out = array_fill_keys($names, null);

        if (! is_array($value)) {
            $fail($path, 'bad_cells', []);

            return $out;
        }

        foreach ($value as $name => $id) {
            if (! is_string($name) || ! in_array($name, $names, true)) {
                // an empty cell of a scheme that is no longer chosen is not a mistake: only a cell that holds a block is reported
                $id === null || $id === '' || $fail($path.'.'.(is_string($name) ? mb_substr($name, 0, 10) : '?'), 'unknown_cell', []);

                continue;
            }

            $out[$name] = FieldKinds::reference($id, false, "{$path}.{$name}", $fail);
        }

        return $out;
    }
}
