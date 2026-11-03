<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\HomeAdapter;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeLayout;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Home\ImageGridGeometry;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
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

        return $node['type'] === 'heading' && is_string($settings['text'] ?? null) ? mb_strimwidth($settings['text'], 0, 24, '…') : '';
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];
        $out = [];

        foreach (HomeCatalog::all() as $type => $descriptor) {
            $reason = $cellId === null ? (string) __('design.palette.no_cell') : (count($items) >= HomeCatalog::MAX_SECTIONS ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null);
            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $reason, 'hint' => null];
        }

        return $out;
    }

    /** Why a component cannot be added: only the number of sections limits it (the screen asks when a type is not in the palette). */
    public function unavailableReason(string $type): ?string
    {
        return isset(HomeCatalog::all()[$type]) ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null;
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        return Tree::node($id, $type, HomeCatalog::defaultsFor($type));
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
            $control = $this->hinted(FieldForms::make($name, $field + ['label' => 'design.home.grid.settings.'.$name]), $name);
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
                $this->hinted(Radio::make('scheme')->label((string) __('design.home.grid.settings.scheme'))->options($schemes)->default(ImageGridGeometry::DEFAULT_SCHEME)->live(), 'scheme'),
                $control('ratio', $schema['ratio']),
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

    /** HINT-1: the explanation is an info icon next to the label (the text is in a bubble linked by aria-describedby), not a paragraph under the field. */
    private function hinted(Component $control, string $name): Component
    {
        $key = 'design.home.hints.'.$name;
        $text = (string) __($key);

        // HP-2: the image grid's own explanations
        if ($text === $key) {
            $key = 'design.home.grid.hints.'.$name;
            $text = (string) __($key);
        }

        $text !== $key && method_exists($control, 'afterLabel') && $control->afterLabel(\App\Filament\Design\InfoHint::make($text, $name));

        return $control;
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
            'node' => 'settings.'.$at['field'],
            'layout' => 'layout.'.$at['field'],
            default => $at['field'],
        };
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
