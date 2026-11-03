<?php

namespace App\Storefront\Home;

/**
 * HP-1: the sections the home page («Начална» in «Дизайн») may hold: a CLOSED list of component types, each with its own validated settings, and the closed lists the zone uses (the spacing between the
 * sections in px, the heading's level and lines). A section holds ONE component. Every section also has the two COMMON settings (`show`, `spacing`: its own top spacing or «as the general one»). Later
 * components (HP-2 … HP-6) are added HERE (a type + a schema + a Blade partial `storefront/partials/home/{type}.blade.php`): neither the zone, the validator nor the compiler changes.
 */
final class HomeCatalog
{
    public const ZONE = 'home';

    public const SCOPE = 'global';

    public const MAX_SECTIONS = 12;

    /** The steps of the spacing between the sections, in px (the CSS classes `sf-hp-gap-N` / `sf-hp-mt-N` give each its rem). */
    public const SPACINGS = ['0', '8', '16', '24', '32', '48', '64', '96'];

    public const DEFAULT_SPACING = '32';

    public const LEVELS = ['h2', 'h3', 'h4'];

    /** `lines` = a line on each side of the text, `none` = the text only. */
    public const LINE_STYLES = ['lines', 'none'];

    public const LINE_THICKNESSES = ['1', '2'];

    public const HEADING_MAX = 120;

    /** HP-3: the tiles of one «Плочки» section (a manual list), the sources a tile can show and the longest caption. */
    public const TILES_MAX = 12;

    public const TILE_SOURCES = ['category', 'season', 'tag', 'brand', 'custom'];

    /** The sources whose public page exists: only a category has one (/product-category/…); a season, a tag and a brand need a link of their own. */
    public const SOURCES_WITH_PAGE = ['category'];

    public const TILE_LABEL_MAX = 60;

    /** HP-2: the most blocks the phone list of an image grid holds. */
    public const PHONE_BLOCKS_MAX = 4;

    /**
     * The settings every section has (before its component's own).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function common(): array
    {
        return [
            'show' => ['kind' => 'bool', 'default' => true],
            'spacing' => ['kind' => 'enum', 'options' => ['default', ...self::SPACINGS], 'default' => 'default', 'options_prefix' => 'design.home.options.spacing.'],
        ];
    }

    /**
     * type => {label (a lang key), schema (the component's own settings), view}.
     *
     * @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string}>
     */
    public static function all(): array
    {
        $types = [
            'heading' => [
                'text' => ['kind' => 'text', 'max' => self::HEADING_MAX, 'required' => true, 'default' => ''],
                'level' => ['kind' => 'enum', 'options' => self::LEVELS, 'default' => 'h2', 'options_prefix' => 'design.home.options.level.'],
                'lines' => ['kind' => 'enum', 'options' => self::LINE_STYLES, 'default' => 'lines', 'options_prefix' => 'design.home.options.lines.'],
                'thickness' => ['kind' => 'enum', 'options' => self::LINE_THICKNESSES, 'default' => '1', 'options_prefix' => 'design.home.options.thickness.'],
            ],
            'html_block' => [
                'html_block' => ['kind' => 'html_block', 'default' => null, 'required' => true],
            ],
            // HP-2: a grid of HTML blocks in cells (a scheme of named areas), with its own list of up to 4 blocks for a phone. `cells` is validated by HomeValidator itself (the names of the chosen scheme, each a block or nothing).
            'image_grid' => [
                'scheme' => ['kind' => 'enum', 'options' => array_keys(ImageGridGeometry::SCHEMES), 'default' => ImageGridGeometry::DEFAULT_SCHEME, 'options_prefix' => 'design.home.grid.schemes.'],
                'ratio' => ['kind' => 'enum', 'options' => ImageGridGeometry::RATIOS, 'default' => ImageGridGeometry::DEFAULT_RATIO, 'options_prefix' => 'design.home.grid.ratios.'],
                'gap' => ['kind' => 'enum', 'options' => ImageGridGeometry::GAPS, 'default' => ImageGridGeometry::DEFAULT_GAP, 'options_prefix' => 'design.home.grid.gap.'],
                'radius' => ['kind' => 'enum', 'options' => ImageGridGeometry::RADII, 'default' => ImageGridGeometry::DEFAULT_RADIUS, 'options_prefix' => 'design.home.grid.radius.'],
                'cells' => ['kind' => 'cells', 'default' => []],
                'phone_blocks' => ['kind' => 'list', 'of' => 'html_block', 'max' => self::PHONE_BLOCKS_MAX, 'too_many_code' => 'too_many_phone', 'default' => []],
                'phone_gap' => ['kind' => 'enum', 'options' => ImageGridGeometry::GAPS, 'default' => ImageGridGeometry::PHONE_DEFAULT_GAP, 'options_prefix' => 'design.home.grid.gap.'],
            ],
            // HP-3: a row of tiles (a picture with its caption below and a link): categories, seasons, tags, brands or free tiles. `tiles` (the manual list) is validated by HomeValidator itself.
            'tiles' => [
                'columns' => ['kind' => 'enum', 'options' => TileGeometry::COLUMNS, 'default' => TileGeometry::DEFAULT_COLUMNS, 'options_prefix' => 'design.home.tiles.columns.'],
                'columns_phone' => ['kind' => 'enum', 'options' => TileGeometry::PHONE_COLUMNS, 'default' => TileGeometry::DEFAULT_PHONE_COLUMNS, 'options_prefix' => 'design.home.tiles.columns.'],
                'gap' => ['kind' => 'enum', 'options' => TileGeometry::GAPS, 'default' => TileGeometry::DEFAULT_GAP, 'options_prefix' => 'design.home.tiles.gap.'],
                'shape' => ['kind' => 'enum', 'options' => array_keys(TileGeometry::SHAPES), 'default' => TileGeometry::DEFAULT_SHAPE, 'options_prefix' => 'design.home.tiles.shapes.'],
                'radius' => ['kind' => 'enum', 'options' => TileGeometry::RADII, 'default' => TileGeometry::DEFAULT_RADIUS, 'options_prefix' => 'design.home.tiles.radius.'],
                'caption' => ['kind' => 'enum', 'options' => array_keys(TileGeometry::CAPTIONS), 'default' => TileGeometry::DEFAULT_CAPTION, 'options_prefix' => 'design.home.tiles.captions.'],
                'tiles' => ['kind' => 'tiles', 'default' => []],
            ],
        ];
        $out = [];

        foreach ($types as $type => $schema) {
            $out[$type] = ['label' => 'design.home.types.'.$type, 'schema' => $schema, 'view' => 'storefront.partials.home.'.$type];
        }

        return $out;
    }

    /**
     * HP-2: the ids of the HTML blocks a section shows (an html_block's one, an image grid's cells and phone list), in order, each once; the compiler reads their HTML in ONE batch.
     *
     * @param  array{type: string, settings: array<string, mixed>}  $section  VALIDATED
     * @return list<int>
     */
    public static function blockIds(array $section): array
    {
        $settings = $section['settings'];
        $ids = match ($section['type']) {
            'html_block' => [$settings['html_block'] ?? null],
            'image_grid' => [...array_values(is_array($settings['cells'] ?? null) ? $settings['cells'] : []), ...array_values(is_array($settings['phone_blocks'] ?? null) ? $settings['phone_blocks'] : [])],
            default => [],
        };

        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
    }

    /** The defaults of a type's settings: the common ones, then its own (an id kind starts as «none chosen»). @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        $defaults = [];

        foreach (self::common() + (self::all()[$type]['schema'] ?? []) as $name => $field) {
            $defaults[$name] = $field['default'] ?? null;
        }

        return $defaults;
    }
}
