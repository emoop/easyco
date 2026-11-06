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

    /** The sources whose public page exists: a category (/product-category/…) and, since P2, a season, a tag and a brand (/season/…, /product-tag/…, /brand/…): a tile of one needs no link of its own (a free tile does). */
    public const SOURCES_WITH_PAGE = ['category', 'season', 'tag', 'brand'];

    public const TILE_LABEL_MAX = 60;

    /** HP-4: the sources of a «Продуктова решетка» (closed), the ones that need an item of their own, the counts, the sorts and the columns; the gap is the product grid's list (GAP-1). At most 2 such sections in a layout. */
    public const PRODUCT_SOURCES = ['newest', 'featured', 'category', 'season', 'tag', 'brand'];

    public const PRODUCT_SOURCES_WITH_ENTITY = ['category', 'season', 'tag', 'brand'];

    public const PRODUCT_COUNTS = ['4', '8', '12', '16', '20', '24'];

    public const PRODUCT_SORTS = ['newest', 'name_asc'];

    /**
     * P3: the carousel of a listing page's bottom slot («Съдържание»): only the kinds that need no compiled picture, and one source more: `current` = the products of the page's own category / brand / season / tag (the subject
     * comes from the PAGE, the stored template is the same for every page). `current` is NOT in PRODUCT_SOURCES: the home page never offers it and HomeValidator refuses it there.
     */
    public const CONTENT_CAROUSEL_KINDS = ['products', 'blocks'];

    public const CURRENT_SOURCE = 'current';

    /**
     * P4: the carousel of a PRODUCT page's «Под галерията» slot: `similar` = the products of the same category as the page's product, without the product itself. Like `current` it is not in PRODUCT_SOURCES (the
     * home page never offers it, HomeValidator refuses it there and in the listing templates); the product comes from the PAGE, the stored slot is the same for every product.
     */
    public const SIMILAR_SOURCE = 'similar';

    /** The content modes a carousel is validated / prepared in: `true` = a listing page's template (extra source `current`), `'product'` = a product page's slot (extra source `similar`). */
    public const PRODUCT_MODE = 'product';

    /** @return list<string> the one source a content mode adds to the home page's list (none for the home page itself) */
    public static function extraSources(bool|string $content): array
    {
        return $content === self::PRODUCT_MODE ? [self::SIMILAR_SOURCE] : ($content === true ? [self::CURRENT_SOURCE] : []);
    }

    public const PRODUCT_COLUMNS = ['3', '4', '5'];

    public const PRODUCT_PHONE_COLUMNS = ['1', '2'];

    public const PRODUCT_GAPS = \App\Storefront\PageDesign\PageDesign::GRID_GAPS;

    public const MAX_PRODUCT_GRIDS = 2;

    /** HP-5: a «Карусел»: at most 2 in a layout; the lists it holds (1–12 blocks, 1–24 pictures), the brands it shows (every brand with a logo, at most 24), the longest label and alt text. */
    public const MAX_CAROUSELS = 2;

    public const CAROUSEL_BLOCKS_MAX = 12;

    public const CAROUSEL_IMAGES_MAX = 24;

    public const CAROUSEL_BRANDS_MAX = 24;

    public const CAROUSEL_LABEL_MAX = 60;

    public const CAROUSEL_ALT_MAX = 120;

    /** HP-6: a «Hero слайдер»: at most 1 in a layout; its slides are 1 to HeroGeometry::SLIDES_MAX (6). */
    public const MAX_HEROES = 1;

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
            // HP-4: the first N products of a source, read at request time. `entity` (the item of the source) is validated by HomeValidator itself.
            'product_grid' => [
                'source' => ['kind' => 'enum', 'options' => self::PRODUCT_SOURCES, 'default' => 'newest', 'options_prefix' => 'design.home.products.sources.'],
                'entity' => ['kind' => 'entity', 'default' => null],
                'count' => ['kind' => 'enum', 'options' => self::PRODUCT_COUNTS, 'default' => '12', 'options_prefix' => 'design.home.products.counts.'],
                'sort' => ['kind' => 'enum', 'options' => self::PRODUCT_SORTS, 'default' => 'newest', 'options_prefix' => 'design.home.products.sorts.'],
                'columns' => ['kind' => 'enum', 'options' => self::PRODUCT_COLUMNS, 'default' => '4', 'options_prefix' => 'design.home.tiles.columns.'],
                'columns_phone' => ['kind' => 'enum', 'options' => self::PRODUCT_PHONE_COLUMNS, 'default' => '2', 'options_prefix' => 'design.home.tiles.columns.'],
                'gap' => ['kind' => 'enum', 'options' => self::PRODUCT_GAPS, 'default' => '16', 'options_prefix' => 'design.home.tiles.gap.'],
            ],
            // HP-5: one row that scrolls sideways: products (read at request time), HTML blocks, pictures or the logos of the brands. `blocks` / `images` are validated by HomeValidator itself; the settings a kind does not use are ignored (their defaults).
            'carousel' => [
                'kind' => ['kind' => 'enum', 'options' => CarouselGeometry::KINDS, 'default' => 'products', 'options_prefix' => 'design.home.carousel.kinds.'],
                'source' => ['kind' => 'enum', 'options' => self::PRODUCT_SOURCES, 'default' => 'newest', 'options_prefix' => 'design.home.products.sources.'],
                'entity' => ['kind' => 'entity', 'default' => null],
                'count' => ['kind' => 'enum', 'options' => CarouselGeometry::COUNTS, 'default' => CarouselGeometry::DEFAULT_COUNT, 'options_prefix' => 'design.home.carousel.counts.'],
                'sort' => ['kind' => 'enum', 'options' => self::PRODUCT_SORTS, 'default' => 'newest', 'options_prefix' => 'design.home.products.sorts.'],
                'height' => ['kind' => 'enum', 'options' => CarouselGeometry::heights(), 'default' => CarouselGeometry::DEFAULT_HEIGHT, 'options_prefix' => 'design.home.carousel.heights.'],
                'height_phone' => ['kind' => 'enum', 'options' => CarouselGeometry::heights(), 'default' => CarouselGeometry::DEFAULT_HEIGHT_PHONE, 'options_prefix' => 'design.home.carousel.heights.'],
                'ratio' => ['kind' => 'enum', 'options' => CarouselGeometry::RATIOS, 'default' => CarouselGeometry::DEFAULT_RATIO, 'options_prefix' => 'design.home.carousel.ratios.'],
                'gap' => ['kind' => 'enum', 'options' => CarouselGeometry::GAPS, 'default' => CarouselGeometry::DEFAULT_GAP, 'options_prefix' => 'design.home.tiles.gap.'],
                'radius' => ['kind' => 'enum', 'options' => CarouselGeometry::RADII, 'default' => CarouselGeometry::DEFAULT_RADIUS, 'options_prefix' => 'design.home.grid.radius.'],
                'arrows' => ['kind' => 'bool', 'default' => true],
                'label' => ['kind' => 'text', 'max' => self::CAROUSEL_LABEL_MAX, 'default' => ''],
                'blocks' => ['kind' => 'carousel_blocks', 'default' => []],
                'images' => ['kind' => 'carousel_images', 'default' => []],
            ],
            // HP-6: one big banner at a time, changing by itself (CSS only). A slide is an HTML block and, optionally, another one for the phone; `slides` is validated by HomeValidator itself. The seconds each slide stays are
            // `seconds` (NOT `show`: every section already has the common `show`, visible or hidden).
            'hero' => [
                'autoplay' => ['kind' => 'bool', 'default' => true],
                'seconds' => ['kind' => 'enum', 'options' => HeroGeometry::SECONDS, 'default' => HeroGeometry::DEFAULT_SECONDS, 'options_prefix' => 'design.home.hero.seconds.'],
                'ratio' => ['kind' => 'enum', 'options' => HeroGeometry::RATIOS, 'default' => HeroGeometry::DEFAULT_RATIO, 'options_prefix' => 'design.home.hero.ratios.'],
                'ratio_phone' => ['kind' => 'enum', 'options' => HeroGeometry::PHONE_RATIOS, 'default' => HeroGeometry::DEFAULT_PHONE_RATIO, 'options_prefix' => 'design.home.hero.ratios.'],
                'slides' => ['kind' => 'hero_slides', 'default' => []],
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
            // HP-5: a blocks carousel's list (the other kinds hold none)
            'carousel' => ($settings['kind'] ?? null) === 'blocks' ? array_values(is_array($settings['blocks'] ?? null) ? $settings['blocks'] : []) : [],
            // HP-6: every slide's desktop block and its phone block (when set)
            'hero' => self::heroBlockIds($settings),
            default => [],
        };

        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
    }

    /**
     * HP-6: the block ids of a hero's slides (the desktop block, then the phone block of each slide, when set), in order.
     *
     * @param  array<string, mixed>  $settings
     * @return list<mixed>
     */
    public static function heroBlockIds(array $settings): array
    {
        $ids = [];

        foreach (is_array($settings['slides'] ?? null) ? $settings['slides'] : [] as $slide) {
            $ids[] = is_array($slide) ? ($slide['block'] ?? null) : null;
            $ids[] = is_array($slide) ? ($slide['block_phone'] ?? null) : null;
        }

        return $ids;
    }

    /**
     * HP-5: the settings of a carousel that a kind USES (the others are ignored: their defaults, no message).
     *
     * @return list<string>
     */
    public static function carouselUses(string $kind): array
    {
        return match ($kind) {
            'products' => ['source', 'entity', 'count', 'sort'],
            'blocks' => ['height', 'height_phone', 'ratio', 'gap', 'radius', 'blocks'],
            'images' => ['height', 'height_phone', 'ratio', 'gap', 'radius', 'images'],
            'brands' => ['height', 'height_phone', 'ratio', 'gap', 'radius'],
            default => [],
        };
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
