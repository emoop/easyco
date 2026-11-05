<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Filament\Forms\Components\MediaPicker;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\HomeAdapter;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Home\CarouselGeometry;
use App\Storefront\Home\HeroGeometry;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeLayout;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Home\ImageGridGeometry;
use App\Storefront\Home\TileGeometry;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * The «Начална» zone of the Design screen (HP-1): the home page as ONE ordered list of SECTIONS, each holding ONE component (heading with lines, free HTML block; later HP-2 … HP-6 add more types to
 * HomeCatalog and nothing here changes). The home page is always the full width of the content area. The list is the generic tree with one row of one cell (HomeAdapter); the screen hides the row and
 * the cell (HasOneCell). The zone-wide spacing between the sections is the LAYOUT's property (the «Общи настройки» button); a section has its own «Показвай» and «Отстояние отгоре». Every rule comes from
 * HomeCatalog and HomeValidator, which stay the single authority; the draft / publish life is HomeDrafts. The default layout (no sections) is also the «Начални настройки»: the shop then prints the home page it
 * always printed.
 */
final class HomeZone implements DesignZone, HasOneCell
{
    public function key(): string
    {
        return 'home';
    }

    public function mode(): string
    {
        return self::TREE;
    }

    public function layouts(): array
    {
        return ['main'];
    }

    public function hasDevices(): bool
    {
        return false;
    }

    public function onlyCell(): string
    {
        return HomeAdapter::CELL;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-home';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.home');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return HomeDrafts::hasUnpublishedChanges();
    }

    /** The preview is the real home page with the APPLIED working copy (a signed preview); it reaches the shop after «Публикувай». */
    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return HomeAdapter::toTree(HomeDrafts::startingLayout());
    }

    /** The default layout as a tree: «Начални настройки» puts it into the working copy. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return HomeAdapter::toTree(HomeLayout::default());
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl(HomeCatalog::ZONE);
    }

    public function previewScroll(): string
    {
        return '';
    }

    public function rows(array $tree, string $layout): array
    {
        return $tree['main']['rows'] ?? [];
    }

    public function rowName(array $row, string $layout): string
    {
        return (string) __('design.zones.home');
    }

    public function cellName(array $cell, string $layout): string
    {
        return (string) __('design.zones.home');
    }

    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('home')[$type] ?? null;

        return $descriptor === null ? $type : (string) __($descriptor['label']);
    }

    /** A short summary for the chip: «скрита» for a hidden section, else a heading's own text. @param  array<string, mixed>  $node */
    public function summary(array $node): string
    {
        $settings = $node['settings'] ?? [];

        if (($settings['show'] ?? true) === false) {
            return (string) __('design.home.hidden');
        }

        if ($node['type'] === 'image_grid') {
            $scheme = is_string($settings['scheme'] ?? null) && isset(ImageGridGeometry::SCHEMES[$settings['scheme']]) ? $settings['scheme'] : ImageGridGeometry::DEFAULT_SCHEME;

            return (string) __('design.home.grid.summary', ['scheme' => __('design.home.grid.schemes.'.$scheme)]);
        }

        if ($node['type'] === 'tiles') {
            $columns = in_array($settings['columns'] ?? null, TileGeometry::COLUMNS, true) ? $settings['columns'] : TileGeometry::DEFAULT_COLUMNS;

            return (string) __('design.home.tiles.summary', ['count' => count(is_array($settings['tiles'] ?? null) ? $settings['tiles'] : []), 'columns' => $columns]);
        }

        if ($node['type'] === 'product_grid') {
            $source = is_string($settings['source'] ?? null) && in_array($settings['source'], HomeCatalog::PRODUCT_SOURCES, true) ? $settings['source'] : 'newest';
            $count = in_array($settings['count'] ?? null, HomeCatalog::PRODUCT_COUNTS, true) ? $settings['count'] : '12';

            return (string) __('design.home.products.summary', ['source' => __('design.home.products.sources.'.$source), 'count' => $count]);
        }

        // HP-5: «Карусел: Марки, 120px» (products: «Карусел: Продукти, 12 продукта»)
        if ($node['type'] === 'carousel') {
            $kind = is_string($settings['kind'] ?? null) && in_array($settings['kind'], CarouselGeometry::KINDS, true) ? $settings['kind'] : 'products';

            if ($kind === 'products') {
                $count = in_array($settings['count'] ?? null, CarouselGeometry::COUNTS, true) ? $settings['count'] : CarouselGeometry::DEFAULT_COUNT;

                return (string) __('design.home.carousel.summary_products', ['kind' => __('design.home.carousel.kinds.'.$kind), 'count' => __('design.home.carousel.counts.'.$count)]);
            }

            $height = in_array($settings['height'] ?? null, CarouselGeometry::heights(), true) ? $settings['height'] : CarouselGeometry::DEFAULT_HEIGHT;

            return (string) __('design.home.carousel.summary', ['kind' => __('design.home.carousel.kinds.'.$kind), 'size' => $height.'px']);
        }

        // HP-6: «Hero слайдер: 4 слайда, 5 с» (autoplay off: «…, ръчно»; one slide: «Hero слайдер: 1 слайд»)
        if ($node['type'] === 'hero') {
            $count = count(is_array($settings['slides'] ?? null) ? $settings['slides'] : []);
            $seconds = in_array($settings['seconds'] ?? null, HeroGeometry::SECONDS, true) ? $settings['seconds'] : HeroGeometry::DEFAULT_SECONDS;

            return (string) match (true) {
                $count === 1 => __('design.home.hero.summary_one'),
                ($settings['autoplay'] ?? true) === false => __('design.home.hero.summary_manual', ['count' => $count]),
                default => __('design.home.hero.summary', ['count' => $count, 'seconds' => $seconds]),
            };
        }

        return $node['type'] === 'heading' && is_string($settings['text'] ?? null) ? mb_strimwidth($settings['text'], 0, 24, '…') : '';
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];
        $out = [];

        $grids = count(array_filter($items, static fn (array $node): bool => ($node['type'] ?? null) === 'product_grid'));
        $carousels = count(array_filter($items, static fn (array $node): bool => ($node['type'] ?? null) === 'carousel'));
        $heroes = count(array_filter($items, static fn (array $node): bool => ($node['type'] ?? null) === 'hero'));

        foreach (HomeCatalog::all() as $type => $descriptor) {
            $reason = $cellId === null ? (string) __('design.palette.no_cell') : (count($items) >= HomeCatalog::MAX_SECTIONS ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null);

            // HP-4: at most 2 product grids
            $reason === null && $type === 'product_grid' && $grids >= HomeCatalog::MAX_PRODUCT_GRIDS && $reason = (string) __('design.home.errors.too_many_product_grids', ['max' => HomeCatalog::MAX_PRODUCT_GRIDS]);
            // HP-5: at most 2 carousels
            $reason === null && $type === 'carousel' && $carousels >= HomeCatalog::MAX_CAROUSELS && $reason = (string) __('design.home.errors.too_many_carousels', ['max' => HomeCatalog::MAX_CAROUSELS]);
            // HP-6: at most 1 hero slider
            $reason === null && $type === 'hero' && $heroes >= HomeCatalog::MAX_HEROES && $reason = (string) __('design.home.errors.too_many_heroes', ['max' => HomeCatalog::MAX_HEROES]);
            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $reason, 'hint' => null];
        }

        return $out;
    }

    /** Why a component cannot be added: only the number of sections limits it (the screen asks when a type is not in the palette). */
    public function unavailableReason(string $type): ?string
    {
        return isset(HomeCatalog::all()[$type]) ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null;
    }

    /**
     * DRIBNI-2: the ratio a NEW image grid starts with (the owner's preferred value). Only the default of a new section: a saved ratio stays, and HomeCatalog's schema default (which HomeValidator reads for a
     * missing value) and ImageGridGeometry::DEFAULT_RATIO (the reading fallback) are NOT changed, so a published page does not change.
     */
    private const NEW_GRID_RATIO = '16:9';

    public function newNode(string $type, string $layout, string $id): array
    {
        $settings = HomeCatalog::defaultsFor($type);

        if ($type === 'image_grid') {
            $settings['ratio'] = self::NEW_GRID_RATIO;
        }

        return Tree::node($id, $type, $settings);
    }

    /** The first rule about the LIST as a whole that the tree breaks (too many sections), or null. */
    public function structureProblem(array $tree): ?string
    {
        [, $errors] = HomeValidator::validate(HomeAdapter::fromTree($tree));

        foreach ($errors as $path => $message) {
            if (preg_match('/^sections(\.\d+)?$/', $path) === 1) {
                return $message;
            }
        }

        return null;
    }

    // ---- the properties --------------------------------------------------------------------------------------------------------------

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $current = $tree['main'] ?? [];

        if ($selected === null || $current === []) {
            return [Text::make(__('design.props.nothing'))];
        }

        if ($selected['kind'] === 'layout') {
            $field = ['kind' => 'enum', 'options' => HomeCatalog::SPACINGS, 'default' => HomeCatalog::DEFAULT_SPACING, 'options_prefix' => 'design.home.options.gap.', 'label' => 'design.home.settings.gap'];

            return [Section::make((string) __('design.home.general'))->schema([
                Group::make([$this->hinted(FieldForms::make('spacing', $field), 'gap')])->statePath('layout'),
            ])];
        }

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);

            if ($node !== null && $node['type'] === 'image_grid') {
                return $this->gridPanel();
            }

            if ($node !== null && $node['type'] === 'tiles') {
                return $this->tilesPanel();
            }

            if ($node !== null && $node['type'] === 'product_grid') {
                return $this->productsPanel();
            }

            if ($node !== null && $node['type'] === 'carousel') {
                return $this->carouselPanel();
            }

            if ($node !== null && $node['type'] === 'hero') {
                return $this->heroPanel();
            }

            $fields = $node === null ? [] : $this->fieldsOf($node['type']);

            return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([
                Group::make(array_map(fn (string $name, array $field): Component => $this->hinted(FieldForms::make($name, $field), $name), array_keys($fields), array_values($fields)))->statePath('settings'),
            ])];
        }

        return [Text::make(__('design.home.pick'))];
    }

    /**
     * HP-2: the panel of an «Решетка от снимки» section. The scheme is a row of radio cards (a small CSS picture of the cells), the proportion / gap / radius are selects, there is ONE block selector per cell of
     * the chosen scheme (the list follows the scheme live), the size diagram comes from ImageGridGeometry, and the phone list is a repeater of up to 4 selectors (add, remove, ↑ ↓). Every control that another one
     * shows is live (Livewire; no JavaScript is added). The values are put back into the settings by applyProps.
     *
     * @return list<Component>
     */
    private function gridPanel(): array
    {
        $schema = HomeCatalog::all()['image_grid']['schema'];
        $common = HomeCatalog::common();
        $control = function (string $name, array $field): Component {
            $control = $this->hinted(FieldForms::make($name, $field + ['label' => 'design.home.grid.settings.'.$name]), $name, 'grid');
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $blocks = static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all();
        $schemes = [];

        foreach (ImageGridGeometry::SCHEMES as $name => $spec) {
            $thumb = '<span class="ds-gt ds-gt-'.$name.'" aria-hidden="true">'.implode('', array_map(static fn (string $cell): string => '<i class="ds-ga-'.$cell.'"></i>', array_keys($spec['cells']))).'</span>';
            $schemes[$name] = new HtmlString($thumb.e((string) __('design.home.grid.schemes.'.$name)));
        }

        $cells = [];

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $cell) {
            $cells[] = Select::make('cells.'.$cell)->label((string) __('design.home.grid.settings.cells.'.$cell))->native(false)->options($blocks)->placeholder((string) __('design.home.grid.cell_empty'))
                ->afterLabel(\App\Filament\Design\InfoHint::make((string) __('design.home.grid.hints.cell'), 'cell-'.$cell))
                ->visible(static fn (Get $get): bool => in_array($cell, ImageGridGeometry::names((string) $get('scheme')), true));
        }

        $emptyPhone = static fn (Get $get): bool => array_filter(is_array($get('phone_items')) ? array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['block'] ?? null) : null, $get('phone_items')) : []) === [];

        return [Section::make($this->typeLabel('image_grid'))->schema([
            Group::make([
                $control('show', $common['show'] + ['label' => 'design.home.settings.show']),
                $control('spacing', $common['spacing'] + ['label' => 'design.home.settings.spacing']),
                $this->hinted(Radio::make('scheme')->label((string) __('design.home.grid.settings.scheme'))->options($schemes)->default(ImageGridGeometry::DEFAULT_SCHEME)->live(), 'scheme', 'grid'),
                $control('ratio', array_merge($schema['ratio'], ['default' => self::NEW_GRID_RATIO])),
                $control('gap', $schema['gap']),
                $control('radius', $schema['radius']),
                ...$cells,
                // the diagram reads the panel's own state (the screen's `props`), the one that every live control has just written: the numbers are always those of what is chosen now
                Text::make(static function (Get $get, $livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $scheme = isset(ImageGridGeometry::SCHEMES[(string) ($own['scheme'] ?? $get('scheme'))]) ? (string) ($own['scheme'] ?? $get('scheme')) : ImageGridGeometry::DEFAULT_SCHEME;
                    $ratio = in_array($own['ratio'] ?? $get('ratio'), ImageGridGeometry::RATIOS, true) ? (string) ($own['ratio'] ?? $get('ratio')) : ImageGridGeometry::DEFAULT_RATIO;
                    $gap = in_array((string) ($own['gap'] ?? $get('gap')), ImageGridGeometry::GAPS, true) ? (string) ($own['gap'] ?? $get('gap')) : ImageGridGeometry::DEFAULT_GAP;

                    return new HtmlString(view('filament.pages.design.partials.image-grid-diagram', ['scheme' => $scheme, 'ratio' => $ratio, 'gap' => $gap, 'cells' => ImageGridGeometry::cells($scheme, $ratio, (int) $gap)])->render());
                }),
                Repeater::make('phone_items')->label((string) __('design.home.grid.settings.phone_blocks'))
                    ->afterLabel(\App\Filament\Design\InfoHint::make((string) __('design.home.grid.hints.phone_blocks'), 'phone_blocks'))
                    ->maxItems(HomeCatalog::PHONE_BLOCKS_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.grid.phone_add'))->live()
                    ->schema([Select::make('block')->label((string) __('design.home.grid.settings.phone_block'))->native(false)->options($blocks)->required()]),
                Text::make((string) __('design.home.grid.phone_note'))->color('gray')->size('sm'),
                Text::make((string) __('design.home.grid.phone_empty'))->color('warning')->size('sm')->visible($emptyPhone),
                $control('phone_gap', $schema['phone_gap']),
            ])->statePath('settings'),
        ])];
    }

    /**
     * HINT-1: the explanation is an info icon next to the label (the text is in a bubble linked by aria-describedby), not a paragraph under the field. The lookup takes the panel's OWN section first
     * when it names one (`$section`: the tiles' `gap` and `radius` are also names of the section's and the image grid's, so the common texts would win there), then the common ones, the image grid's, the tiles'.
     */
    private function hinted(Component $control, string $name, string $section = ''): Component
    {
        $keys = [...($section === '' ? [] : ['design.home.'.$section.'.hints.'.$name]), 'design.home.hints.'.$name, 'design.home.grid.hints.'.$name, 'design.home.tiles.hints.'.$name];

        foreach ($keys as $key) {
            $text = (string) __($key);

            if ($text !== $key) {
                method_exists($control, 'afterLabel') && $control->afterLabel(\App\Filament\Design\InfoHint::make($text, $name));

                break;
            }
        }

        return $control;
    }

    /**
     * HP-3: the panel of a «Плочки» section. The look settings are selects, ONE per row (DRIBNI-2: a narrow panel, the admin standard), the size hint is computed live by TileGeometry from what is chosen now (read from the
     * screen's own `props`, the state every live control has just written), and the tiles are a repeater (add, remove, ↑ ↓) of full-width items: the source, the item of that source (a select that follows the
     * source), the caption, the link and the picture; a tile that would have no picture shows a warning line.
     *
     * @return list<Component>
     */
    private function tilesPanel(): array
    {
        $schema = HomeCatalog::all()['tiles']['schema'];
        $common = HomeCatalog::common();
        $control = function (string $name, array $field): Component {
            $control = $this->hinted(FieldForms::make($name, $field + ['label' => 'design.home.tiles.settings.'.$name]), $name, 'tiles');
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => \App\Filament\Design\InfoHint::make((string) __('design.home.tiles.hints.'.$name), 'tile-'.$name);
        $label = static fn (string $name): string => (string) __('design.home.tiles.settings.'.$name);

        return [Section::make($this->typeLabel('tiles'))->schema([
            Group::make([
                Grid::make(['default' => 1])->schema([
                    $control('show', $common['show'] + ['label' => 'design.home.settings.show']),
                    $control('spacing', $common['spacing'] + ['label' => 'design.home.settings.spacing']),
                    $control('columns', $schema['columns']),
                    $control('columns_phone', $schema['columns_phone']),
                    $control('gap', $schema['gap']),
                    $control('shape', $schema['shape']),
                    $control('radius', $schema['radius']),
                    $control('caption', $schema['caption']),
                ]),
                // the recommended picture size: the numbers are TileGeometry's (the very function the tests pin)
                Text::make(static function ($livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $pick = static fn (string $key, array $list, string $default): string => in_array((string) ($own[$key] ?? ''), $list, true) ? (string) $own[$key] : $default;
                    $gap = (int) $pick('gap', TileGeometry::GAPS, TileGeometry::DEFAULT_GAP);
                    $shape = $pick('shape', array_keys(TileGeometry::SHAPES), TileGeometry::DEFAULT_SHAPE);

                    return new HtmlString(view('filament.pages.design.partials.tiles-hint', [
                        'desktop' => TileGeometry::tile((int) $pick('columns', TileGeometry::COLUMNS, TileGeometry::DEFAULT_COLUMNS), $gap, $shape, TileGeometry::REFERENCE_WIDTH),
                        'phone' => TileGeometry::tile((int) $pick('columns_phone', TileGeometry::PHONE_COLUMNS, TileGeometry::DEFAULT_PHONE_COLUMNS), $gap, $shape, TileGeometry::PHONE_REFERENCE_WIDTH),
                    ])->render());
                }),
                Repeater::make('tiles')->label($label('tiles'))->afterLabel($hint('tiles'))
                    ->maxItems(HomeCatalog::TILES_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.tiles.add'))->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1])->schema([
                            Select::make('source')->label($label('source'))->afterLabel($hint('source'))->native(false)->default('category')->live()
                                ->options(array_combine(HomeCatalog::TILE_SOURCES, array_map(static fn (string $s): string => (string) __('design.home.tiles.sources.'.$s), HomeCatalog::TILE_SOURCES)))
                                ->afterStateUpdated(static fn (Set $set) => $set('entity', null)),
                            Select::make('entity')->label($label('entity'))->afterLabel($hint('entity'))->native(false)->searchable()->live()
                                ->options(fn (Get $get): array => self::tileEntities((string) $get('source')))
                                ->visible(static fn (Get $get): bool => $get('source') !== 'custom'),
                            TextInput::make('label')->label($label('label'))->afterLabel($hint('label'))->maxLength(HomeCatalog::TILE_LABEL_MAX),
                            TextInput::make('link')->label($label('link'))->afterLabel($hint('link'))->placeholder('/product-category/…')->maxLength(500)->columnSpanFull(),
                            MediaPicker::make('picture')->label($label('picture'))->afterLabel($hint('picture'))->plainImagesOnly()->live()->columnSpanFull(),
                            Text::make((string) __('design.home.tiles.no_picture'))->color('warning')->size('sm')->columnSpanFull()
                                ->visible(static fn (Get $get): bool => self::tileHasNoPicture((string) $get('source'), $get('entity'), $get('picture'))),
                        ]),
                    ]),
            ])->statePath('settings'),
        ])];
    }

    /**
     * HP-4: the panel of a «Продуктова решетка» section: the settings ONE per row (DRIBNI-2), the item select follows the source, and a live line says how many products the grid
     * resolves to NOW (the very read the shop does, from the screen's own `props`). Explanations are info icons.
     *
     * @return list<Component>
     */
    private function productsPanel(): array
    {
        $schema = HomeCatalog::all()['product_grid']['schema'];
        $common = HomeCatalog::common();
        $control = function (string $name, array $field): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.products.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel(\App\Filament\Design\InfoHint::make((string) __('design.home.products.hints.'.$name), 'products-'.$name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $hint = static fn (string $name): HtmlString => \App\Filament\Design\InfoHint::make((string) __('design.home.products.hints.'.$name), 'products-'.$name);

        return [Section::make($this->typeLabel('product_grid'))->schema([
            Group::make([
                Grid::make(['default' => 1])->schema([
                    $control('show', $common['show'] + ['label' => 'design.home.settings.show']),
                    $control('spacing', $common['spacing'] + ['label' => 'design.home.settings.spacing']),
                    $control('source', $schema['source'])->afterStateUpdated(static fn (Set $set) => $set('entity', null)),
                    Select::make('entity')->label((string) __('design.home.products.settings.entity'))->afterLabel($hint('entity'))->native(false)->searchable()->live()
                        ->options(fn (Get $get): array => self::tileEntities((string) $get('source')))
                        ->visible(static fn (Get $get): bool => in_array((string) $get('source'), HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true)),
                    $control('count', $schema['count']),
                    $control('sort', $schema['sort']),
                    $control('columns', $schema['columns']),
                    $control('columns_phone', $schema['columns_phone']),
                    $control('gap', $schema['gap']),
                ]),
                Text::make(static function ($livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $n = self::productCount($own);

                    return new HtmlString('<span class="'.($n === 0 ? 'text-warning-600' : 'text-gray-600').'">'.e($n === 0 ? (string) __('design.home.products.none') : (string) __('design.home.products.count_line', ['count' => $n])).'</span>');
                }),
            ])->statePath('settings'),
        ])];
    }

    /**
     * HP-5: the panel of a «Карусел» section. The settings are ONE per row (DRIBNI-2: kind, source, count, order, the two heights, ratio, gap, radius and arrows in their order); the lists (blocks,
     * pictures) and the item select are full width; the fields the chosen kind does not use are hidden (live). The recommended size comes from CarouselGeometry (the very function the tests pin), from the panel's own
     * `props`. Explanations are info icons.
     *
     * @return list<Component>
     */
    private function carouselPanel(): array
    {
        $schema = HomeCatalog::all()['carousel']['schema'];
        $common = HomeCatalog::common();
        $label = static fn (string $name): string => (string) __('design.home.carousel.settings.'.$name);
        $hint = static fn (string $name): HtmlString => \App\Filament\Design\InfoHint::make((string) __('design.home.carousel.hints.'.$name), 'carousel-'.$name);
        $control = function (string $name, array $field) use ($hint): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.carousel.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel($hint($name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $kind = static fn (Get $get): string => in_array($get('kind'), CarouselGeometry::KINDS, true) ? (string) $get('kind') : 'products';
        $products = static fn (Get $get): bool => $kind($get) === 'products';
        $box = static fn (Get $get): bool => $kind($get) !== 'products';
        $blocks = static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all();

        return [Section::make($this->typeLabel('carousel'))->schema([
            Group::make([
                Grid::make(['default' => 1])->schema([
                    $control('show', $common['show'] + ['label' => 'design.home.settings.show']),
                    $control('spacing', $common['spacing'] + ['label' => 'design.home.settings.spacing']),
                    $control('kind', $schema['kind']),
                    $control('source', $schema['source'])->afterStateUpdated(static fn (Set $set) => $set('entity', null))->visible($products),
                    Select::make('entity')->label($label('entity'))->afterLabel($hint('entity'))->native(false)->searchable()->live()->columnSpanFull()
                        ->options(fn (Get $get): array => self::tileEntities((string) $get('source')))
                        ->visible(static fn (Get $get): bool => $products($get) && in_array((string) $get('source'), HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true)),
                    $control('count', $schema['count'])->visible($products),
                    $control('sort', $schema['sort'])->visible($products),
                    $control('height', $schema['height'])->visible($box),
                    $control('height_phone', $schema['height_phone'])->visible($box),
                    $control('ratio', $schema['ratio'])->visible($box),
                    $control('gap', $schema['gap'])->visible($box),
                    $control('radius', $schema['radius'])->visible($box),
                    $control('arrows', $schema['arrows']),
                    $control('label', $schema['label'])->columnSpanFull(),
                ]),
                // the recommended size (non-product kinds: the box and its 2× size; products: the card), from CarouselGeometry
                Text::make(static function ($livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $pick = static fn (string $key, array $list, string $default): string => in_array((string) ($own[$key] ?? ''), $list, true) ? (string) $own[$key] : $default;

                    if ($pick('kind', CarouselGeometry::KINDS, 'products') === 'products') {
                        $card = CarouselGeometry::card(CarouselGeometry::CARD_COLUMNS, 16);

                        return new HtmlString('<span class="text-gray-600">'.e((string) __('design.home.carousel.hint_card', ['w' => $card['rounded_width'], 'h' => $card['rounded_image_height'], 'w2' => $card['double_width'], 'h2' => $card['double_image_height']])).'</span>');
                    }

                    $ratio = $pick('ratio', CarouselGeometry::RATIOS, CarouselGeometry::DEFAULT_RATIO);
                    $desktop = CarouselGeometry::hint((int) $pick('height', CarouselGeometry::heights(), CarouselGeometry::DEFAULT_HEIGHT), $ratio);
                    $phone = CarouselGeometry::hint((int) $pick('height_phone', CarouselGeometry::heights(), CarouselGeometry::DEFAULT_HEIGHT_PHONE), $ratio);

                    return new HtmlString('<span class="text-gray-600">'.e((string) __('design.home.carousel.hint_box', [
                        'w' => $desktop['rounded_width'], 'h' => $desktop['rounded_height'], 'w2' => $desktop['double_width'], 'h2' => $desktop['double_height'],
                        'pw' => $phone['rounded_width'], 'ph' => $phone['rounded_height'], 'pw2' => $phone['double_width'], 'ph2' => $phone['double_height'],
                    ])).'</span>');
                }),
                Repeater::make('block_items')->label($label('blocks'))->afterLabel($hint('blocks'))
                    ->maxItems(HomeCatalog::CAROUSEL_BLOCKS_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.carousel.block_add'))->live()->columnSpanFull()
                    ->schema([Select::make('block')->label($label('block'))->native(false)->options($blocks)->required()])
                    ->visible(static fn (Get $get): bool => $kind($get) === 'blocks'),
                Repeater::make('images')->label($label('images'))->afterLabel($hint('images'))
                    ->maxItems(HomeCatalog::CAROUSEL_IMAGES_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.carousel.image_add'))->live()->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1])->schema([
                            MediaPicker::make('picture')->label($label('picture'))->plainImagesOnly()->live()->columnSpanFull(),
                            TextInput::make('link')->label($label('link'))->placeholder('/product-category/…')->maxLength(500),
                            TextInput::make('alt')->label($label('alt'))->maxLength(HomeCatalog::CAROUSEL_ALT_MAX),
                        ]),
                    ])
                    ->visible(static fn (Get $get): bool => $kind($get) === 'images'),
                Text::make(static function ($livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $n = ($own['kind'] ?? 'products') === 'products' ? self::carouselCount($own) : null;

                    return new HtmlString($n === null ? '' : '<span class="'.($n === 0 ? 'text-warning-600' : 'text-gray-600').'">'.e($n === 0 ? (string) __('design.home.carousel.none') : (string) __('design.home.carousel.count_line', ['count' => $n])).'</span>');
                }),
            ])->statePath('settings'),
        ])];
    }

    /**
     * HP-6: the panel of a «Hero слайдер» section. The settings are selects and a switch, ONE per row (the seconds are hidden while the slides do not change by themselves), the recommended
     * picture size comes from HeroGeometry (the very function the tests pin) for what is chosen NOW (the screen's own `props`), and the slides are a repeater (add, remove, ↑ ↓) of full-width items: the desktop block
     * and the optional phone block in two TABS (one selector each). Explanations are info icons. The values are put back into the settings by applyProps.
     *
     * @return list<Component>
     */
    private function heroPanel(): array
    {
        $schema = HomeCatalog::all()['hero']['schema'];
        $common = HomeCatalog::common();
        $label = static fn (string $name): string => (string) __('design.home.hero.settings.'.$name);
        $hint = static fn (string $name): HtmlString => \App\Filament\Design\InfoHint::make((string) __('design.home.hero.hints.'.$name), 'hero-'.$name);
        $control = function (string $name, array $field) use ($hint): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.hero.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel($hint($name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $general = function (string $name) use ($common): Component {
            $control = $this->hinted(FieldForms::make($name, $common[$name] + ['label' => 'design.home.settings.'.$name]), $name);
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $blocks = static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all();

        return [Section::make($this->typeLabel('hero'))->schema([
            Group::make([
                Grid::make(['default' => 1])->schema([
                    $general('show'),
                    $general('spacing'),
                    $control('autoplay', $schema['autoplay']),
                    $control('seconds', $schema['seconds'])->visible(static fn (Get $get): bool => (bool) $get('autoplay')),
                    $control('ratio', $schema['ratio']),
                    $control('ratio_phone', $schema['ratio_phone']),
                ]),
                // the recommended picture sizes of the two blocks (desktop: the 1320px reference; phone: 358px), from HeroGeometry
                Text::make(static function ($livewire): HtmlString {
                    $own = is_array($livewire->props['settings'] ?? null) ? $livewire->props['settings'] : [];
                    $desktop = HeroGeometry::desktop((string) ($own['ratio'] ?? ''));
                    $phone = HeroGeometry::phone((string) ($own['ratio_phone'] ?? ''));

                    return new HtmlString('<span class="text-gray-600">'.e((string) __('design.home.hero.hint_size', [
                        'w' => $desktop['rounded_width'], 'h' => $desktop['rounded_height'], 'w2' => $desktop['double_width'], 'h2' => $desktop['double_height'],
                        'pw' => $phone['rounded_width'], 'ph' => $phone['rounded_height'], 'pw2' => $phone['double_width'], 'ph2' => $phone['double_height'],
                    ])).'</span>');
                }),
                Repeater::make('slide_items')->label($label('slides'))->afterLabel($hint('slides'))
                    ->maxItems(HeroGeometry::SLIDES_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.hero.slide_add'))->live()->columnSpanFull()
                    // a slide is two tabs, «Компютър» and «Телефон»: each tab holds ONE selector, so the fields stay one per row and are not crowded
                    ->schema([
                        Tabs::make('slide')->tabs([
                            Tabs\Tab::make((string) __('design.home.hero.tabs.desktop'))->schema([
                                Select::make('block')->label($label('block'))->afterLabel($hint('block'))->native(false)->options($blocks)->required(),
                            ]),
                            Tabs\Tab::make((string) __('design.home.hero.tabs.phone'))->schema([
                                Select::make('block_phone')->label($label('block_phone'))->afterLabel($hint('block_phone'))->native(false)->options($blocks)->placeholder((string) __('design.home.grid.cell_empty')),
                            ]),
                        ]),
                    ]),
            ])->statePath('settings'),
        ])];
    }

    /**
     * HP-6: the settings of a «Hero слайдер» from the panel: the selects and the switch as they are (the validator refuses a value outside its list), the slides as a list in order `{block, block_phone}` (an empty
     * phone block is none; an item with no block is kept so that the validator names it). Never validates.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function heroSettings(array $in): array
    {
        $out = ['show' => (bool) ($in['show'] ?? true), 'spacing' => $in['spacing'] ?? 'default'];

        foreach (['seconds', 'ratio', 'ratio_phone'] as $name) {
            isset($in[$name]) && $out[$name] = $in[$name];
        }

        array_key_exists('autoplay', $in) && $out['autoplay'] = (bool) $in['autoplay'];

        if (array_key_exists('slide_items', $in)) {
            $slides = [];

            foreach (is_array($in['slide_items']) ? array_values($in['slide_items']) : [] as $item) {
                $item = is_array($item) ? $item : [];
                $slides[] = ['block' => FieldValues::id($item['block'] ?? null), 'block_phone' => FieldValues::id($item['block_phone'] ?? null)];
            }

            $out['slides'] = $slides;
        }

        return $out;
    }

    /** HP-5: how many products a product carousel with these (panel) settings resolves to now (the shop's own read). @param  array<string, mixed>  $own */
    private static function carouselCount(array $own): int
    {
        $source = in_array($own['source'] ?? null, HomeCatalog::PRODUCT_SOURCES, true) ? (string) $own['source'] : 'newest';
        $count = in_array((string) ($own['count'] ?? ''), CarouselGeometry::COUNTS, true) ? (int) $own['count'] : (int) CarouselGeometry::DEFAULT_COUNT;
        $sort = in_array($own['sort'] ?? null, HomeCatalog::PRODUCT_SORTS, true) ? (string) $own['sort'] : 'newest';

        return count(app(\App\Storefront\Reader\CatalogReader::class)->homeProducts($source, FieldValues::id($own['entity'] ?? null), $sort, $count, \App\Storefront\Reader\ImageSetBuilder::SIZES_CARD));
    }

    /**
     * HP-5: the settings of a «Карусел» from the panel: the selects as they are (the validator refuses a value outside its list), the item as an id (none for «най-нови»), the blocks as a list of ids in order and the
     * pictures as a list of `{picture, link, alt}` (an empty link is none). Never validates.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function carouselSettings(array $in): array
    {
        $out = ['show' => (bool) ($in['show'] ?? true), 'spacing' => $in['spacing'] ?? 'default'];

        foreach (['kind', 'source', 'count', 'sort', 'height', 'height_phone', 'ratio', 'gap', 'radius', 'label'] as $name) {
            isset($in[$name]) && $out[$name] = $in[$name];
        }

        array_key_exists('arrows', $in) && $out['arrows'] = (bool) $in['arrows'];
        array_key_exists('source', $in) && $out['entity'] = in_array($in['source'] ?? 'newest', HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) ? FieldValues::id($in['entity'] ?? null) : null;

        if (array_key_exists('block_items', $in)) {
            $blocks = [];

            foreach (is_array($in['block_items']) ? array_values($in['block_items']) : [] as $item) {
                $id = is_array($item) ? FieldValues::id($item['block'] ?? null) : null;
                $id === null || $blocks[] = $id;
            }

            $out['blocks'] = $blocks;
        }

        if (array_key_exists('images', $in)) {
            $images = [];

            foreach (is_array($in['images']) ? array_values($in['images']) : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $link = is_string($item['link'] ?? null) ? trim($item['link']) : '';
                $images[] = ['picture' => FieldValues::id($item['picture'] ?? null), 'link' => $link === '' ? null : $link, 'alt' => is_string($item['alt'] ?? null) ? trim($item['alt']) : ''];
            }

            $out['images'] = $images;
        }

        return $out;
    }

    /** HP-4: how many products a product grid with these (panel) settings resolves to now: the shop's own read (visibility, the in-stock rule), at most the chosen count. @param  array<string, mixed>  $own */
    private static function productCount(array $own): int
    {
        $source = in_array($own['source'] ?? null, HomeCatalog::PRODUCT_SOURCES, true) ? (string) $own['source'] : 'newest';
        $count = in_array((string) ($own['count'] ?? ''), HomeCatalog::PRODUCT_COUNTS, true) ? (int) $own['count'] : 12;
        $sort = in_array($own['sort'] ?? null, HomeCatalog::PRODUCT_SORTS, true) ? (string) $own['sort'] : 'newest';

        return count(app(\App\Storefront\Reader\CatalogReader::class)->homeProducts($source, FieldValues::id($own['entity'] ?? null), $sort, $count, \App\Storefront\Reader\ImageSetBuilder::SIZES_CARD));
    }

    /** The items a tile of this source can show, by name: a category as «Parent › Child». @return array<int, string> */
    public static function tileEntities(string $source): array
    {
        if ($source === 'category') {
            $rows = DB::table('catalog_categories')->orderBy('name')->get(['id', 'parent_id', 'name'])->keyBy('id');
            $labels = [];

            foreach ($rows as $row) {
                $path = [];

                for ($current = $row, $guard = 0; $current !== null && $guard < 10; $current = $current->parent_id === null ? null : ($rows[$current->parent_id] ?? null), $guard++) {
                    array_unshift($path, (string) $current->name);
                }

                $labels[(int) $row->id] = implode(' › ', $path);
            }

            asort($labels);

            return $labels;
        }

        $table = ['season' => 'catalog_seasons', 'tag' => 'catalog_tags', 'brand' => 'catalog_brands'][$source] ?? null;

        return $table === null ? [] : DB::table($table)->orderBy('name')->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }

    /** Would this tile end up with no picture (so it would not be shown)? A free tile with none; an item that has no picture of its own (a tag never has one) and none given here. Nothing chosen yet: no warning. */
    private static function tileHasNoPicture(string $source, mixed $entity, mixed $picture): bool
    {
        if (filled($picture)) {
            return false;
        }

        if ($source === 'custom') {
            return true;
        }

        $id = FieldValues::id($entity);

        if (! is_int($id)) {
            return false;
        }

        $own = ['category' => ['catalog_categories', 'picture_media_asset_id'], 'season' => ['catalog_seasons', 'picture_media_asset_id'], 'brand' => ['catalog_brands', 'logo_media_asset_id']][$source] ?? null;

        return $own === null || DB::table($own[0])->where('id', $id)->value($own[1]) === null;
    }

    /**
     * The settings of a «Продуктова решетка» from the panel: the selects as they are (the validator refuses a value outside its list), the item as an id (none for «най-нови»). Never validates.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function productsSettings(array $in): array
    {
        $out = ['show' => (bool) ($in['show'] ?? true), 'spacing' => $in['spacing'] ?? 'default'];

        foreach (['source', 'count', 'sort', 'columns', 'columns_phone', 'gap'] as $name) {
            isset($in[$name]) && $out[$name] = $in[$name];
        }

        $out['entity'] = in_array($in['source'] ?? 'newest', HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true) ? FieldValues::id($in['entity'] ?? null) : null;

        return $out;
    }

    /**
     * The settings of a «Плочки» section from the panel: the selects as they are (the validator refuses a value outside its list), the tiles as a list in order (the free tile has no item; an empty link is none).
     * Never validates.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function tilesSettings(array $in): array
    {
        $out = ['show' => (bool) ($in['show'] ?? true), 'spacing' => $in['spacing'] ?? 'default'];

        foreach (['columns', 'columns_phone', 'gap', 'shape', 'radius', 'caption'] as $name) {
            isset($in[$name]) && $out[$name] = $in[$name];
        }

        $tiles = [];

        foreach (is_array($in['tiles'] ?? null) ? array_values($in['tiles']) : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $source = is_string($item['source'] ?? null) ? $item['source'] : '';
            $link = is_string($item['link'] ?? null) ? trim($item['link']) : '';
            $tiles[] = [
                'source' => $source,
                'entity' => $source === 'custom' ? null : FieldValues::id($item['entity'] ?? null),
                'label' => is_string($item['label'] ?? null) ? trim($item['label']) : '',
                'picture' => FieldValues::id($item['picture'] ?? null),
                'link' => $link === '' ? null : $link,
            ];
        }

        $out['tiles'] = $tiles;

        return $out;
    }

    /**
     * The settings of an image grid from the panel: the enums as they are (the validator refuses a value outside its list), the cells as a map of block ids (an empty select is null), the phone repeater's items
     * as a list of ids in order (an empty item is left out). Never validates.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function gridSettings(array $in): array
    {
        $out = [
            'show' => (bool) ($in['show'] ?? true),
            'spacing' => $in['spacing'] ?? 'default',
        ];

        foreach (['scheme', 'ratio', 'gap', 'radius', 'phone_gap'] as $name) {
            isset($in[$name]) && $out[$name] = $in[$name];
        }

        $cells = [];

        foreach (is_array($in['cells'] ?? null) ? $in['cells'] : [] as $name => $id) {
            $cells[$name] = FieldValues::id($id);
        }

        $out['cells'] = $cells;
        $phone = [];

        foreach (is_array($in['phone_items'] ?? null) ? array_values($in['phone_items']) : [] as $item) {
            $id = is_array($item) ? FieldValues::id($item['block'] ?? null) : null;
            $id === null || $phone[] = $id;
        }

        $out['phone_blocks'] = $phone;

        return $out;
    }

    /** @return array<string, array<string, mixed>> the fields of a section type: the common ones, then the component's own, each with its own label */
    private function fieldsOf(string $type): array
    {
        $fields = [];

        foreach (HomeCatalog::common() + (HomeCatalog::all()[$type]['schema'] ?? []) as $name => $field) {
            $fields[$name] = $field + ['label' => 'design.home.settings.'.$name];
        }

        return $fields;
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        if ($selected === null) {
            return [];
        }

        if ($selected['kind'] === 'layout') {
            return ['layout' => ['spacing' => (string) ($tree['main']['props']['spacing'] ?? HomeCatalog::DEFAULT_SPACING)]];
        }

        if ($selected['kind'] !== 'node') {
            return [];
        }

        $node = TreeEditor::node($tree['main'] ?? [], $selected['id']);

        if ($node !== null && $node['type'] === 'image_grid') {
            // the phone list is a repeater of `{block}` items in the panel and a plain list of ids in the settings
            $settings = $node['settings'];
            $settings['phone_items'] = array_map(static fn (mixed $id): array => ['block' => $id], array_values(is_array($settings['phone_blocks'] ?? null) ? $settings['phone_blocks'] : []));

            return ['settings' => $settings];
        }

        if ($node !== null && $node['type'] === 'hero') {
            // the slides are a repeater of `{block, block_phone}` items in the panel (the very same shape as the settings)
            $settings = $node['settings'];
            $settings['slide_items'] = array_map(static fn (mixed $slide): array => ['block' => is_array($slide) ? ($slide['block'] ?? null) : null, 'block_phone' => is_array($slide) ? ($slide['block_phone'] ?? null) : null], array_values(is_array($settings['slides'] ?? null) ? $settings['slides'] : []));

            return ['settings' => $settings];
        }

        if ($node !== null && $node['type'] === 'carousel') {
            // the blocks are a repeater of `{block}` items in the panel and a plain list of ids in the settings
            $settings = $node['settings'];
            $settings['block_items'] = array_map(static fn (mixed $id): array => ['block' => $id], array_values(is_array($settings['blocks'] ?? null) ? $settings['blocks'] : []));

            return ['settings' => $settings];
        }

        return $node === null ? [] : ['settings' => $node['settings']];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $current = $tree['main'] ?? [];

        if ($selected === null || $current === []) {
            return $tree;
        }

        if ($selected['kind'] === 'layout') {
            $in = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];
            // a value outside the closed list stays as it is: the validator refuses it with its message
            $tree['main']['props']['spacing'] = $in['spacing'] ?? ($current['props']['spacing'] ?? HomeCatalog::DEFAULT_SPACING);

            return $tree;
        }

        if ($selected['kind'] !== 'node' || ($node = TreeEditor::node($current, $selected['id'])) === null) {
            return $tree;
        }

        $in = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];

        if ($node['type'] === 'product_grid') {
            $settings = $this->productsSettings($in) + $node['settings'];
            $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

            return $tree;
        }

        if ($node['type'] === 'hero') {
            $settings = $this->heroSettings($in) + $node['settings'];
            $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

            return $tree;
        }

        if ($node['type'] === 'carousel') {
            $settings = $this->carouselSettings($in) + $node['settings'];
            $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

            return $tree;
        }

        if ($node['type'] === 'tiles') {
            $settings = $this->tilesSettings($in) + $node['settings'];
            $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

            return $tree;
        }

        if ($node['type'] === 'image_grid') {
            $settings = $this->gridSettings($in) + $node['settings'];
            $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

            return $tree;
        }

        $settings = FieldValues::settings($this->fieldsOf($node['type']), $in) + $node['settings'];
        $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

        return $tree;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return HomeValidator::validate(HomeAdapter::fromTree($tree));
    }

    public function locate(array $tree, string $path): ?array
    {
        return HomeAdapter::locate($tree, $path);
    }

    public function fieldPath(array $at): ?string
    {
        if ($at['field'] === null) {
            return null;
        }

        return match ($at['kind']) {
            'node' => 'settings.'.self::stateField($at['field']),
            'layout' => 'layout.'.$at['field'],
            default => $at['field'],
        };
    }

    /**
     * DRIBNI-4: the repeaters whose STATE name is not the settings name: settings name => [state name, the field of an item that holds a plain value (a list of ids has no field of its own in the
     * settings, the item's one select is `block`)]. The screen turns the position of an item into its key; this table gives it the name the panel's repeater has.
     */
    private const REPEATER_STATE = [
        'slides' => ['slide_items', null],
        'blocks' => ['block_items', 'block'],
        'phone_blocks' => ['phone_items', 'block'],
    ];

    /** The path of a settings field as the panel's state knows it (`slides.1.block` → `slide_items.1.block`; `blocks.0` → `block_items.0.block`). */
    private static function stateField(string $field): string
    {
        $parts = explode('.', $field);
        $map = self::REPEATER_STATE[$parts[0]] ?? null;

        if ($map === null) {
            return $field;
        }

        $parts[0] = $map[0];
        $map[1] !== null && count($parts) === 2 && ctype_digit($parts[1]) && $parts[] = $map[1];

        return implode('.', $parts);
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        HomeDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        HomeDrafts::publish($stored);
    }

    public function discard(): void
    {
        HomeDrafts::discard();
    }
}
