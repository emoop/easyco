<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Settings\AppearanceSettings;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderDrafts;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The «Хедър» zone of the Design screen (DS-2a, moved here in DS-2b without a change in behaviour): the header's desktop and phone trees, its palette and rules, its properties and its
 * draft / publish life (HeaderDrafts).
 */
final class HeaderZone implements DesignZone
{
    public const DRAWER_ROW = 'h-m-drawer-r';

    public const DRAWER_CELL = 'h-m-drawer';

    public function key(): string
    {
        return 'header';
    }

    public function mode(): string
    {
        return self::TREE;
    }

    public function layouts(): array
    {
        return ['desktop', 'mobile'];
    }

    public function hasDevices(): bool
    {
        return true;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.title');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function load(): array
    {
        return HeaderAdapter::toTree(HeaderDrafts::startingLayout());
    }

    public function previewUrl(): string
    {
        return HeaderPreviewController::previewUrl();
    }

    public function previewScroll(): string
    {
        return '';
    }

    public function rows(array $tree, string $layout): array
    {
        return array_values(array_filter($tree[$layout]['rows'] ?? [], static fn (array $row): bool => $row['id'] !== self::DRAWER_ROW));
    }

    public function rowName(array $row, string $layout): string
    {
        return (string) __('header.rows.'.substr($row['id'], strlen('h-'.($layout === 'mobile' ? 'm' : 'd').'-')));
    }

    /** @param  array<string, mixed>  $tree  @return array<string, mixed>|null the phone's drawer cell */
    public function drawerCell(array $tree, string $layout): ?array
    {
        return $layout === 'mobile' ? TreeEditor::cell($tree[$layout] ?? [], self::DRAWER_CELL) : null;
    }

    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('header')[$type] ?? null;

        return $descriptor === null ? $type : (string) ($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label']);
    }

    public function summary(array $node): string
    {
        $settings = $node['settings'];

        foreach (['text', 'label'] as $key) {
            if (is_string($settings[$key] ?? null) && $settings[$key] !== '') {
                return mb_substr($settings[$key], 0, 24);
            }
        }

        return match ($node['type']) {
            'logo' => ((int) ($settings['height'] ?? 0)).'px',
            'social' => (string) count($settings['items'] ?? []),
            default => is_string($settings['style'] ?? null) ? (string) __('header.options.'.$settings['style']) : (is_string($settings['mode'] ?? null) ? (string) __('header.options.'.$settings['mode']) : ''),
        };
    }

    public function cellName(array $cell, string $layout): string
    {
        return $cell['id'] === self::DRAWER_CELL ? (string) __('design.schema.drawer') : (string) __('header.slots.'.substr($cell['id'], strrpos($cell['id'], '-') + 1));
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $out = [];

        foreach (ComponentRegistry::for('header') as $type => $descriptor) {
            if ($cellId === self::DRAWER_CELL && ! $this->drawerAllows($type)) {
                continue;
            }

            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $this->reason($tree, $layout, $type, $cellId), 'hint' => null];
        }

        return $out;
    }

    private function drawerAllows(string $type): bool
    {
        return in_array($type, HeaderCatalog::DRAWER_TYPES, true) && $type !== 'account';
    }

    /** Why a component cannot be added to that cell right now (null = it can). @param  array<string, mixed>  $tree */
    public function reason(array $tree, string $layout, string $type, ?string $cellId): ?string
    {
        if ($cellId === null) {
            return (string) __('design.palette.no_cell');
        }

        $cell = TreeEditor::cell($tree[$layout] ?? [], $cellId);

        if ($cell === null || ! isset(ComponentRegistry::for('header')[$type])) {
            return (string) __('design.notices.unknown');
        }

        if ($cellId === self::DRAWER_CELL && ! $this->drawerAllows($type)) {
            return (string) __('design.palette.not_offered');
        }

        $max = $cellId === self::DRAWER_CELL ? HeaderLayout::MAX_DRAWER : HeaderLayout::MAX_PER_SLOT;

        if (count($cell['items']) >= $max) {
            return (string) __('design.palette.full', ['max' => $max]);
        }

        if (in_array($type, HeaderCatalog::ONCE_PER_LAYOUT, true) && $this->count($tree[$layout] ?? [], $type) > 0) {
            return (string) __('design.palette.once');
        }

        return null;
    }

    /** @param  array<string, mixed>  $layout */
    private function count(array $layout, string $type): int
    {
        $n = 0;

        foreach ($layout['rows'] ?? [] as $row) {
            foreach ($row['cells'] as $cell) {
                foreach ($cell['items'] as $node) {
                    $n += $node['type'] === $type ? 1 : 0;
                }
            }
        }

        return $n;
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        $settings = HeaderCatalog::defaultsFor($type);

        if ($type === 'logo') {
            $settings['height'] = HeaderLayout::LOGO_HEIGHTS[$layout][2];
        }

        return Tree::node($id, $type, $settings);
    }

    public function structureProblem(array $tree): ?string
    {
        foreach (HeaderValidator::validate(HeaderAdapter::fromTree($tree))->errors as $path => $message) {
            if (preg_match('/^(?:desktop|mobile)\.(?:rows|drawer|rows\.[a-z]+\.(?:left|center|right))$/', $path) === 1 || preg_match('/^mobile\.drawer\.\d+$/', $path) === 1) {
                return $message;
            }
        }

        return null;
    }

    // ---- the properties --------------------------------------------------------------------------------------------------------------

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $current = $tree[$layout] ?? [];

        if ($selected === null || $current === []) {
            return [Text::make(__('design.props.nothing'))];
        }

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);
            $fields = $node === null ? [] : $this->fieldsOf($node['type'], $layout, TreeEditor::cellOf($current, $selected['id']) === self::DRAWER_CELL);

            $components = [];

            foreach ($fields as $name => $field) {
                // DS-2c: the colours of an element sit under a small heading, with a non-blocking hint when the badge's pair is hard to read
                $name === 'color' && $node !== null && $node['type'] === 'cart' && $components[] = Text::make(__('design.cart.colors'))->weight('semibold');
                $components[] = FieldForms::make($name, $field);
            }

            if ($node !== null && $node['type'] === 'cart') {
                $components[] = Text::make(static fn (Get $get): string => (string) __('design.cart.low_contrast', ['ratio' => number_format(AppearanceSettings::contrastRatio((string) $get('badge_fg'), (string) $get('badge_bg')), 2)]))
                    ->color('warning')->size('sm')
                    ->visible(static fn (Get $get): bool => AppearanceSettings::isHex($get('badge_fg')) && AppearanceSettings::isHex($get('badge_bg')) && AppearanceSettings::contrastRatio((string) $get('badge_fg'), (string) $get('badge_bg')) < AppearanceSettings::MIN_CONTRAST);
            }

            return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([Group::make($components)->statePath('settings')])];
        }

        if ($selected['kind'] === 'row') {
            [$min, $max] = HeaderLayout::ROW_HEIGHTS[$layout][substr($selected['id'], strlen('h-m-'))] ?? [0, 0];

            return [
                Section::make(__('design.props.general'))->schema([
                    Toggle::make('enabled')->label(__('header.fields.enabled')),
                    TextInput::make('height')->label(__('header.fields.height'))->helperText(__('header.fields.height_hint', ['min' => $min, 'max' => $max]))->numeric()->integer()->minValue($min)->maxValue($max)->suffix('px'),
                ]),
                Section::make(__('design.props.colors'))->schema([
                    ColorPicker::make('colors.bg')->label(__('design.props.bg')),
                    ColorPicker::make('colors.fg')->label(__('design.props.fg')),
                    Select::make('text_size')->label(__('design.props.text_size'))->native(false)->placeholder(__('design.props.text_size_default'))
                        ->options(array_combine(HeaderLayout::TEXT_SIZES, array_map(static fn (string $s): string => (string) __('design.props.sizes.'.$s), HeaderLayout::TEXT_SIZES))),
                ])->columns(2),
            ];
        }

        if ($selected['kind'] === 'layout') {
            $components = [Toggle::make('sticky')->label(__('header.fields.sticky'))];

            if ($layout === 'mobile') {
                $components[] = Select::make('drawer_side')->label(__('header.fields.drawer_side'))->native(false)->options(['left' => __('header.options.left'), 'right' => __('header.options.right')]);
                $components[] = Toggle::make('drawer_upper')->label(__('header.fields.drawer_upper'));
            }

            return [Section::make(__('design.props.layout', ['device' => __('design.devices.'.$layout)]))->schema($components)];
        }

        $cell = TreeEditor::cell($current, $selected['id']);

        return [Text::make(__('design.props.cell', ['name' => $cell === null ? '' : $this->cellName($cell, $layout)]))];
    }

    /** @return array<string, array<string, mixed>> the fields of a node type (the logo's height range follows the device) */
    private function fieldsOf(string $type, string $layout, bool $inDrawer = false): array
    {
        $fields = ComponentRegistry::for('header')[$type]['fields'] ?? [];

        // DS-2a.1: in the drawer the menu is always an accordion, so «Вид» would change nothing there (the stored value is kept); the menu choice says "the drawer's menu"
        if ($type === 'menu' && $inDrawer) {
            unset($fields['style']);
            $fields['menu']['place'] = 'drawer';
        }

        if ($type === 'logo') {
            [$min, $max, $default] = HeaderLayout::LOGO_HEIGHTS[$layout];
            $fields['height'] = ['kind' => 'int', 'min' => $min, 'max' => $max, 'default' => $default];
        }

        return $fields;
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $current = $tree[$layout] ?? [];

        if ($selected === null) {
            return [];
        }

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);

            return $node === null ? [] : ['settings' => $node['settings']];
        }

        if ($selected['kind'] === 'row') {
            $props = TreeEditor::row($current, $selected['id'])['props'] ?? [];

            return [
                'enabled' => (bool) ($props['enabled'] ?? false), 'height' => $props['height'] ?? null,
                'colors' => ['bg' => $props['colors']['bg'] ?? null, 'fg' => $props['colors']['fg'] ?? null], 'text_size' => $props['text_size'] ?? null,
            ];
        }

        if ($selected['kind'] === 'layout') {
            $props = $current['props'] ?? [];

            return ['sticky' => (bool) ($props['sticky'] ?? false)] + ($layout === 'mobile' ? ['drawer_side' => $props['drawer_side'] ?? 'left', 'drawer_upper' => (bool) ($props['drawer_upper'] ?? true)] : []);
        }

        return [];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $current = $tree[$layout] ?? [];

        if ($selected === null || $current === []) {
            return $tree;
        }

        if ($selected['kind'] === 'node' && ($node = TreeEditor::node($current, $selected['id'])) !== null) {
            $fields = $this->fieldsOf($node['type'], $layout, TreeEditor::cellOf($current, $selected['id']) === self::DRAWER_CELL);
            $settings = FieldValues::settings($fields, is_array($raw['settings'] ?? null) ? $raw['settings'] : []);
            // a setting the panel does not show (the drawer menu's «Вид») keeps what is stored
            $current = TreeEditor::setSettings($current, $selected['id'], $settings + $node['settings']);
        } elseif ($selected['kind'] === 'row' && TreeEditor::row($current, $selected['id']) !== null && $selected['id'] !== self::DRAWER_ROW) {
            $props = ['enabled' => (bool) ($raw['enabled'] ?? false), 'height' => FieldValues::wholeNumber($raw['height'] ?? null)];
            $colors = array_filter(['bg' => $raw['colors']['bg'] ?? null, 'fg' => $raw['colors']['fg'] ?? null], static fn (mixed $c): bool => is_string($c) && $c !== '');

            if ($colors !== []) {
                $props['colors'] = $colors;
            }

            if (is_string($raw['text_size'] ?? null) && $raw['text_size'] !== '') {
                $props['text_size'] = $raw['text_size'];
            }

            $current = TreeEditor::setRowProps($current, $selected['id'], $props);
        } elseif ($selected['kind'] === 'layout') {
            $current['props']['sticky'] = (bool) ($raw['sticky'] ?? false);

            if ($layout === 'mobile') {
                $current['props']['drawer_side'] = is_string($raw['drawer_side'] ?? null) ? $raw['drawer_side'] : 'left';
                $current['props']['drawer_upper'] = (bool) ($raw['drawer_upper'] ?? true);
            }
        }

        $tree[$layout] = $current;

        return $tree;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        $validation = HeaderValidator::validate(HeaderAdapter::fromTree($tree));

        return [$validation->layout, $validation->errors];
    }

    public function locate(array $tree, string $path): ?array
    {
        return HeaderAdapter::locate($tree, $path);
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'] === null ? null : ($at['kind'] === 'node' ? 'settings.' : '').$at['field'];
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        HeaderDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        HeaderDrafts::publish($stored);
    }

    public function discard(): void
    {
        HeaderDrafts::discard();
    }
}
