<?php

namespace App\Storefront\Home;

use App\Content\HtmlBlocks\HtmlBlockSanitizer;

/**
 * What the home page prints from a PUBLISHED layout (HP-1): the sections that are shown, each with the classes of its place and what its Blade partial needs. Pure: no query, no setting. Every value that
 * reaches a class name is from a closed list (HomeCatalog), checked again here; the HTML of a block is the compiled, sanitised copy.
 *
 * The first section that is printed has no top spacing; every other one has the general spacing, or its own when it overrides it (`sf-hp-mt-N`).
 */
final class HomeView
{
    /**
     * @param  array{layout: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, blocks: array<string, string>}|null  $stored  HomeCompiler::published()
     * @return array{gap: string, items: list<array{type: string, class: string, view: string, settings: array<string, mixed>, html: string}>}|null null = print the home page as it always was
     */
    public static function prepare(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $catalog = HomeCatalog::all();
        $gap = in_array($stored['layout']['spacing'], HomeCatalog::SPACINGS, true) ? $stored['layout']['spacing'] : HomeCatalog::DEFAULT_SPACING;
        $items = [];

        foreach ($stored['layout']['sections'] as $index => $section) {
            $type = $section['type'];
            $settings = $section['settings'];

            if (! isset($catalog[$type]) || ($settings['show'] ?? true) === false) {
                continue;
            }

            $html = '';

            if ($type === 'html_block') {
                // cleaned AGAIN when the layout is read (a hand-edited compiled copy cannot bring markup in), as the footer and the right column do
                $html = HtmlBlockSanitizer::render((string) ($stored['blocks'][(string) ($settings['html_block'] ?? '')] ?? ''));

                // a block that is gone (or empty) prints nothing: no empty box in the page
                if ($html === '') {
                    continue;
                }
            }

            $grid = null;

            if ($type === 'image_grid') {
                $grid = self::grid($settings, $stored['blocks']);

                // a grid with no block to show (no cell, no phone block) prints nothing: no empty box in the page
                if ($grid === null) {
                    continue;
                }
            }

            $tiles = null;

            if ($type === 'tiles') {
                $tiles = self::tiles($settings, $stored['tiles'][(string) $index] ?? []);

                // a row with no tile to show prints nothing: no empty box in the page
                if ($tiles === null) {
                    continue;
                }
            }

            $products = null;

            if ($type === 'product_grid') {
                $products = self::products($settings);

                // no usable source (an item that is not chosen): nothing to print; the cards are read later, by fill()
                if ($products === null) {
                    continue;
                }
            }

            $carousel = null;

            if ($type === 'carousel') {
                $carousel = self::carousel($settings, $stored['blocks'], $stored['carousels'][(string) $index] ?? []);

                // nothing to show (no usable block, picture or source): nothing is printed, no empty box in the page
                if ($carousel === null) {
                    continue;
                }
            }

            $hero = null;

            if ($type === 'hero') {
                $hero = self::hero($settings, $stored['blocks']);

                // no slide with a block that still exists: nothing is printed, no empty box in the page
                if ($hero === null) {
                    continue;
                }
            }

            $class = 'sf-hp-section sf-hp-'.str_replace('_', '-', $type);
            $own = $settings['spacing'] ?? 'default';

            if ($items !== [] && is_string($own) && in_array($own, HomeCatalog::SPACINGS, true)) {
                $class .= ' sf-hp-mt-'.$own;
            }

            $items[] = ['type' => $type, 'class' => $class, 'view' => $catalog[$type]['view'], 'settings' => $settings, 'html' => $html, 'grid' => $grid, 'tiles' => $tiles, 'products' => $products, 'carousel' => $carousel, 'hero' => $hero];
        }

        // every section hidden or empty: nothing to show, but the layout is published (the page is NOT the old one)
        return ['gap' => $gap, 'items' => $items];
    }

    /**
     * HP-4: what a «Продуктова решетка» needs: the source, the classes (closed lists, checked again here; a value outside the list is its default) and the `sizes` of the card images from the component's
     * own columns and gap (ImageSetBuilder::cardSizes, as the category page). The cards are NOT here: they are read at request time by fill(). Null = no usable source (an item that is not chosen).
     *
     * @param  array<string, mixed>  $settings
     * @return array{class: string, source: string, entity: int|null, sort: string, count: int, sizes: string, cards: list<\App\Storefront\ReadModels\ProductCard>}|null
     */
    private static function products(array $settings): ?array
    {
        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $source = $pick('source', HomeCatalog::PRODUCT_SOURCES, 'newest');
        $entity = $settings['entity'] ?? null;
        $entity = is_int($entity) && $entity > 0 ? $entity : null;

        // a source that needs an item and has none prints nothing
        if (in_array($source, HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) && $entity === null) {
            return null;
        }

        $columns = $pick('columns', HomeCatalog::PRODUCT_COLUMNS, '4');
        $phone = $pick('columns_phone', HomeCatalog::PRODUCT_PHONE_COLUMNS, '2');
        $gap = $pick('gap', HomeCatalog::PRODUCT_GAPS, '16');

        return [
            'class' => 'sf-hp-products sf-hp-prc-'.$columns.' sf-hp-prp-'.$phone.' sf-hp-prg-'.$gap,
            'source' => $source,
            'entity' => in_array($source, HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) ? $entity : null,
            'sort' => $pick('sort', HomeCatalog::PRODUCT_SORTS, 'newest'),
            'count' => (int) $pick('count', HomeCatalog::PRODUCT_COUNTS, '12'),
            'sizes' => \App\Storefront\Reader\ImageSetBuilder::cardSizes((int) $columns, (int) $phone, (int) $gap),
            'cards' => [],
        ];
    }

    /**
     * HP-4: reads the cards of every product grid of the prepared sections, at request time (`$read(source, entity, sort, count, sizes)` = CatalogReader::homeProducts), in the order of the layout. A grid with
     * no product is dropped, and the top spacing is put right again: the first section that is printed has none. Sections without a product grid come back as they are.
     *
     * HP-5: a PRODUCT carousel is read the same way (one card read), its card images' `sizes` from the catalog's own gap and phone columns (GAP-1: the carousel's card is a cell of that grid).
     *
     * @param  array{gap: string, items: list<array<string, mixed>>}  $sections  prepare()'s result
     * @param  \Closure(string, int|null, string, int, string): list<\App\Storefront\ReadModels\ProductCard>  $read
     * @return array{gap: string, items: list<array<string, mixed>>}
     */
    public static function fill(array $sections, \Closure $read, int $gapX = 16, int $phoneColumns = 2): array
    {
        $items = [];
        $dropped = false;

        foreach ($sections['items'] as $item) {
            if ($item['type'] === 'carousel' && ($item['carousel']['kind'] ?? null) === 'products') {
                $carousel = $item['carousel'];
                $carousel['sizes'] = \App\Storefront\Reader\ImageSetBuilder::cardSizes(CarouselGeometry::CARD_COLUMNS, $phoneColumns, $gapX);
                $carousel['cards'] = $read($carousel['source'], $carousel['entity'], $carousel['sort'], $carousel['count'], $carousel['sizes']);

                if ($carousel['cards'] === []) {
                    $dropped = true;

                    continue;
                }

                $item['carousel'] = $carousel;
            }

            if ($item['type'] === 'product_grid' && is_array($item['products'] ?? null)) {
                $grid = $item['products'];
                $grid['cards'] = $read($grid['source'], $grid['entity'], $grid['sort'], $grid['count'], $grid['sizes']);

                if ($grid['cards'] === []) {
                    $dropped = true;

                    continue;
                }

                $item['products'] = $grid;
            }

            $items[] = $item;
        }

        // the section that is printed first has no top spacing (a spacing class is `sf-hp-mt-N`)
        if ($dropped && $items !== []) {
            $items[0]['class'] = (string) preg_replace('/ sf-hp-mt-\d+/', '', (string) $items[0]['class']);
        }

        $sections['items'] = $items;

        return $sections;
    }

    /**
     * HP-6: what a «Hero слайдер» prints. Every class is from a closed list (checked again here; a value outside it is its default); the blocks are the compiled, sanitised HTML (cleaned AGAIN here). A slide whose
     * desktop block is gone (or empty) is dropped; a phone block that is gone is none (the desktop block then shows at every size); no slide left = nothing to show. The number of slides in the classes is the number
     * of slides PRINTED, so the timing follows what is really there. A hero with one slide, or with autoplay off, is the manual row (no animation).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $blocks  the compiled HTML by block id
     * @return array{class: string, slides: list<array{d: string, m: string}>, count: int, autoplay: bool}|null
     */
    private static function hero(array $settings, array $blocks): ?array
    {
        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $html = static fn (mixed $id): string => is_int($id) ? HtmlBlockSanitizer::render((string) ($blocks[(string) $id] ?? '')) : '';
        $slides = [];

        foreach (array_slice(is_array($settings['slides'] ?? null) ? array_values($settings['slides']) : [], 0, HeroGeometry::SLIDES_MAX) as $slide) {
            $slide = is_array($slide) ? $slide : [];
            $desktop = $html($slide['block'] ?? null);

            $desktop === '' || $slides[] = ['d' => $desktop, 'm' => $html($slide['block_phone'] ?? null)];
        }

        if ($slides === []) {
            return null;
        }

        $count = count($slides);
        $autoplay = ($settings['autoplay'] ?? true) !== false && $count > 1;
        $seconds = $pick('seconds', HeroGeometry::SECONDS, HeroGeometry::DEFAULT_SECONDS);
        $ratio = $pick('ratio', HeroGeometry::RATIOS, HeroGeometry::DEFAULT_RATIO);
        $phone = $pick('ratio_phone', HeroGeometry::PHONE_RATIOS, HeroGeometry::DEFAULT_PHONE_RATIO);

        return [
            'class' => 'sf-hs '.($autoplay ? 'sf-hs-auto' : 'sf-hs-manual').' sf-hs-n-'.$count.' sf-hs-t-'.$seconds.' sf-hs-r-'.HeroGeometry::slug($ratio).' sf-hs-rp-'.HeroGeometry::slug($phone),
            'slides' => $slides,
            'count' => $count,
            'autoplay' => $autoplay,
        ];
    }

    /**
     * HP-5: what a «Карусел» prints. Every class is from a closed list (checked again here; a value outside it is its default); a products carousel is read later by fill(); blocks are the compiled, sanitised HTML
     * (cleaned AGAIN here); images and brands are the compiled, checked pictures. Null = nothing to show.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $blocks  the compiled HTML by block id
     * @param  list<array<string, mixed>>  $pictures  the compiled pictures of this section (images, brands), validated
     * @return array{kind: string, class: string, label: string, arrows: bool, source: string, entity: int|null, sort: string, count: int, sizes: string, cards: list<\App\Storefront\ReadModels\ProductCard>, blocks: list<string>, pictures: list<array<string, mixed>>}|null
     */
    private static function carousel(array $settings, array $blocks, array $pictures): ?array
    {
        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $kind = $pick('kind', CarouselGeometry::KINDS, 'products');
        $label = is_string($settings['label'] ?? null) ? trim($settings['label']) : '';
        $label = mb_strlen($label) > HomeCatalog::CAROUSEL_LABEL_MAX || $label !== strip_tags($label) ? '' : $label;
        $one = ['kind' => $kind, 'class' => 'sf-car sf-car-k-'.$kind, 'label' => $label, 'arrows' => ($settings['arrows'] ?? true) !== false, 'source' => 'newest', 'entity' => null, 'sort' => 'newest', 'count' => (int) CarouselGeometry::DEFAULT_COUNT, 'sizes' => \App\Storefront\Reader\ImageSetBuilder::cardSizes(CarouselGeometry::CARD_COLUMNS), 'cards' => [], 'blocks' => [], 'pictures' => []];

        if ($kind === 'products') {
            $source = $pick('source', HomeCatalog::PRODUCT_SOURCES, 'newest');
            $entity = $settings['entity'] ?? null;
            $entity = is_int($entity) && $entity > 0 ? $entity : null;

            // a source that needs an item and has none prints nothing
            if (in_array($source, HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) && $entity === null) {
                return null;
            }

            return ['source' => $source, 'entity' => in_array($source, HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) ? $entity : null, 'sort' => $pick('sort', HomeCatalog::PRODUCT_SORTS, 'newest'), 'count' => (int) $pick('count', CarouselGeometry::COUNTS, CarouselGeometry::DEFAULT_COUNT)] + $one;
        }

        $height = $pick('height', CarouselGeometry::heights(), CarouselGeometry::DEFAULT_HEIGHT);
        $phone = $pick('height_phone', CarouselGeometry::heights(), CarouselGeometry::DEFAULT_HEIGHT_PHONE);
        $ratio = $pick('ratio', CarouselGeometry::RATIOS, CarouselGeometry::DEFAULT_RATIO);
        $gap = $pick('gap', CarouselGeometry::GAPS, CarouselGeometry::DEFAULT_GAP);
        $radius = $pick('radius', CarouselGeometry::RADII, CarouselGeometry::DEFAULT_RADIUS);
        $one['class'] .= ' sf-car-box sf-car-h-'.$height.' sf-car-hp-'.$phone.' sf-car-r-'.str_replace(':', '-', $ratio).' sf-car-g-'.$gap.' sf-car-cr-'.$radius;
        $one['sizes'] = CarouselGeometry::sizes((int) $height, (int) $phone, $ratio);

        if ($kind === 'blocks') {
            foreach (array_slice(is_array($settings['blocks'] ?? null) ? array_values($settings['blocks']) : [], 0, HomeCatalog::CAROUSEL_BLOCKS_MAX) as $id) {
                $html = is_int($id) ? HtmlBlockSanitizer::render((string) ($blocks[(string) $id] ?? '')) : '';
                $html === '' || $one['blocks'][] = $html;
            }

            return $one['blocks'] === [] ? null : $one;
        }

        // images (up to 24) and brands (up to 24): the compiled pictures, in order
        // each picture says the width it is DISPLAYED at (contain, never enlarged): the sizes of its own box fit
        $one['pictures'] = array_map(static function (array $picture) use ($height, $phone, $ratio): array {
            $natural = max([(int) $picture['width'], ...array_map(static fn (array $row): int => (int) $row['width'], $picture['srcset'])]);

            return $picture + ['sizes' => CarouselGeometry::pictureSizes((int) $height, (int) $phone, $ratio, $natural, (int) round($natural * $picture['height'] / max(1, $picture['width'])))];
        }, array_slice($pictures, 0, HomeCatalog::CAROUSEL_IMAGES_MAX));

        return $one['pictures'] === [] ? null : $one;
    }

    /**
     * HP-3: what a «Плочки» row prints: every class from a closed list (checked again here; a value outside it is its default), the compiled tiles and the `sizes` of their pictures (TileGeometry, from the
     * published columns and gap). The first row of tiles is loaded at once, the rest lazily. Null = no tile to show.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $tiles  the compiled tiles of this section, validated
     * @return array{class: string, tiles: list<array<string, mixed>>, sizes: string, width: int, height: int, eager: int, caption: int}|null
     */
    private static function tiles(array $settings, array $tiles): ?array
    {
        if ($tiles === []) {
            return null;
        }

        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $columns = $pick('columns', TileGeometry::COLUMNS, TileGeometry::DEFAULT_COLUMNS);
        $phone = $pick('columns_phone', TileGeometry::PHONE_COLUMNS, TileGeometry::DEFAULT_PHONE_COLUMNS);
        $gap = $pick('gap', TileGeometry::GAPS, TileGeometry::DEFAULT_GAP);
        $shape = $pick('shape', array_keys(TileGeometry::SHAPES), TileGeometry::DEFAULT_SHAPE);
        $radius = $pick('radius', TileGeometry::RADII, TileGeometry::DEFAULT_RADIUS);
        $caption = $pick('caption', array_keys(TileGeometry::CAPTIONS), TileGeometry::DEFAULT_CAPTION);
        [$width, $height] = TileGeometry::attributes($shape);

        return [
            'class' => 'sf-hp-tiles-row sf-hp-tc-'.$columns.' sf-hp-tp-'.$phone.' sf-hp-tg-'.$gap.' sf-hp-sh-'.$shape.' sf-hp-tr-'.$radius.' sf-hp-cap-'.$caption,
            'tiles' => $tiles,
            'sizes' => TileGeometry::sizes((int) $columns, (int) $phone, (int) $gap),
            'width' => $width,
            'height' => $height,
            'eager' => (int) $columns,
            'caption' => TileGeometry::CAPTIONS[$caption],
        ];
    }

    /**
     * HP-2: what an image grid prints. Every class is from a closed list (checked again here; a value outside it is its default); the blocks are the compiled, sanitised HTML, cleaned AGAIN on read. A cell with
     * no block (or a block that is gone) is NOT printed; the phone list is the chosen blocks in order. Null = nothing to show at all.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $blocks  the compiled HTML by block id
     * @return array{class: string, cells: list<array{name: string, html: string}>, phone_class: string, phone: list<string>}|null
     */
    private static function grid(array $settings, array $blocks): ?array
    {
        $pick = static fn (string $key, array $list, string $default): string => in_array($settings[$key] ?? null, $list, true) ? (string) $settings[$key] : $default;
        $scheme = $pick('scheme', array_keys(ImageGridGeometry::SCHEMES), ImageGridGeometry::DEFAULT_SCHEME);
        $ratio = $pick('ratio', ImageGridGeometry::RATIOS, ImageGridGeometry::DEFAULT_RATIO);
        $gap = $pick('gap', ImageGridGeometry::GAPS, ImageGridGeometry::DEFAULT_GAP);
        $radius = $pick('radius', ImageGridGeometry::RADII, ImageGridGeometry::DEFAULT_RADIUS);
        $phoneGap = $pick('phone_gap', ImageGridGeometry::GAPS, ImageGridGeometry::PHONE_DEFAULT_GAP);
        $html = static fn (mixed $id): string => is_int($id) ? HtmlBlockSanitizer::render((string) ($blocks[(string) $id] ?? '')) : '';
        $cells = [];

        foreach (ImageGridGeometry::names($scheme) as $name) {
            $one = $html(is_array($settings['cells'] ?? null) ? ($settings['cells'][$name] ?? null) : null);
            $one === '' || $cells[] = ['name' => $name, 'html' => $one];
        }

        $phone = [];

        foreach (array_slice(is_array($settings['phone_blocks'] ?? null) ? array_values($settings['phone_blocks']) : [], 0, HomeCatalog::PHONE_BLOCKS_MAX) as $id) {
            $one = $html($id);
            $one === '' || $phone[] = $one;
        }

        if ($cells === [] && $phone === []) {
            return null;
        }

        return [
            'class' => 'sf-hp-grid sf-hp-s-'.$scheme.' sf-hp-r-'.str_replace(':', '-', $ratio).' sf-hp-gg-'.$gap.' sf-hp-cr-'.$radius,
            'cells' => $cells,
            'phone_class' => 'sf-hp-phone sf-hp-pg-'.$phoneGap.' sf-hp-cr-'.$radius,
            'phone' => $phone,
        ];
    }
}
