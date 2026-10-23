<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\FooterPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneSpec;
use App\Storefront\Design\ZoneStore\FooterAdapter;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Footer\FooterDrafts;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderCatalog;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The «Футър» zone of the Design screen (DS-2b): the FT-1 footer as the generic tree (FooterAdapter): the UPPER level (1 to 2 rows of 1 to 5 columns of blocks) and the LOWER level (one
 * line). Rows and columns are added, removed and moved here (RestructuresTree); the blocks are added from the palette and moved with the arrows. Every rule comes from the FT-1 constants
 * (FooterLayout, through ZoneSpec) and from FooterValidator, which stays the single authority. The draft / publish life is FooterDrafts, the same code the old «Футър» page calls.
 */
final class FooterZone implements DesignZone, RestructuresTree
{
    public const UPPER_CELL_PREFIX = 'f-u-';

    public function key(): string
    {
        return 'footer';
    }

    public function mode(): string
    {
        return self::TREE;
    }

    public function layouts(): array
    {
        return ['upper', 'lower'];
    }

    public function hasDevices(): bool
    {
        return false;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-footer';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.footer');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function load(): array
    {
        return FooterAdapter::toTree(FooterDrafts::startingLayout());
    }

    /** The seeded footer as a tree: «Върни по подразбиране» puts it into the screen only. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return FooterAdapter::toTree(FooterLayout::default());
    }

    public function previewUrl(): string
    {
        return FooterPreviewController::previewUrl();
    }

    /** The frame scrolls to the footer by script (same origin): the footer's HTML has no id and must not change. */
    public function previewScroll(): string
    {
        return '.sf-footer';
    }

    public function rows(array $tree, string $layout): array
    {
        return $tree[$layout]['rows'] ?? [];
    }

    public function rowName(array $row, string $layout): string
    {
        return (string) __($layout === 'lower' ? 'design.footer.lower' : 'footer.fields.row');
    }

    public function cellName(array $cell, string $layout): string
    {
        return (string) __('footer.fields.column');
    }

    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('footer')[$type] ?? null;

        if ($type === 'text_line') {
            return (string) __('design.footer.line');
        }

        return $descriptor === null ? $type : (string) ($descriptor['builtin'] ? __($descriptor['label']) : $descriptor['label']);
    }

    public function summary(array $node): string
    {
        $settings = $node['settings'];

        foreach (['heading', 'text_template', 'body', 'phone'] as $key) {
            if (is_string($settings[$key] ?? null) && $settings[$key] !== '') {
                return mb_substr($settings[$key], 0, 24);
            }
        }

        return match ($node['type']) {
            'social' => (string) count($settings['items'] ?? []),
            'payments' => (string) count($settings['methods'] ?? []),
            'spacer' => (string) __('footer.options.'.($settings['size'] ?? 'm')),
            default => '',
        };
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $out = [];

        foreach (ComponentRegistry::for('footer') as $type => $descriptor) {
            $hint = $type === 'newsletter' && ! Route::has(FooterCatalog::NEWSLETTER_ROUTE) ? (string) __('design.footer.newsletter_hint') : null;
            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $this->reason($tree, $cellId), 'hint' => $hint];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $tree */
    private function reason(array $tree, ?string $cellId): ?string
    {
        if ($cellId === null) {
            return (string) __('design.palette.no_cell');
        }

        $cell = TreeEditor::cell($tree['upper'] ?? [], $cellId);

        if ($cell === null) {
            return (string) __('design.notices.unknown');
        }

        $spec = ZoneSpec::for('footer.upper');

        if (count($cell['items']) >= $spec->maxNodesPerCell) {
            return (string) __('design.palette.full', ['max' => $spec->maxNodesPerCell]);
        }

        if ($this->blocks($tree['upper']) >= (int) $spec->maxNodes) {
            return (string) __('footer.errors.too_many_blocks', ['max' => $spec->maxNodes]);
        }

        return null;
    }

    /** @param  array<string, mixed>  $upper */
    private function blocks(array $upper): int
    {
        $n = 0;

        foreach ($upper['rows'] ?? [] as $row) {
            foreach ($row['cells'] as $cell) {
                $n += count($cell['items']);
            }
        }

        return $n;
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        $settings = FooterCatalog::defaultsFor($type);

        // an id kind starts as "none chosen" (the catalog's `defaultsFor` turns every null into an empty text)
        foreach (FooterCatalog::all()[$type]['schema'] as $name => $field) {
            if (in_array($field['kind'], ['menu', 'html_block'], true)) {
                $settings[$name] = null;
            }
        }

        return Tree::node($id, $type, $settings);
    }

    public function structureProblem(array $tree): ?string
    {
        foreach (FooterValidator::validate(FooterAdapter::fromTree($tree))->errors as $path => $message) {
            if (preg_match('/^upper(?:\.rows(?:\.\d+\.columns(?:\.\d+\.blocks)?)?)?$/', $path) === 1) {
                return $message;
            }
        }

        return null;
    }

    // ---- rows and columns ------------------------------------------------------------------------------------------------------------

    public function restructure(array $tree, string $action, string $id, bool $confirmed): array
    {
        $upper = $tree['upper'];
        $spec = ZoneSpec::for('footer.upper');

        try {
            $upper = match ($action) {
                'add_row' => TreeEditor::addRow($upper, Tree::row($this->newId(), [], [$this->newColumn()]), $spec->maxRows),
                'remove_row' => count($upper['rows']) <= $spec->minRows ? throw new \DomainException((string) __('design.footer.last_row')) : TreeEditor::removeRow($upper, $id, $confirmed),
                'add_column' => TreeEditor::addCell($upper, $id, $this->newColumn(), $spec->maxCells),
                'remove_column' => $this->removeColumn($upper, $id, $confirmed, $spec),
                'move_column_left' => TreeEditor::moveCell($upper, $id, 'left'),
                'move_column_right' => TreeEditor::moveCell($upper, $id, 'right'),
                default => throw new \DomainException((string) __('design.notices.unknown')),
            };
        } catch (\DomainException $e) {
            throw new \DomainException(match ($e->getMessage()) {
                'limit' => (string) __($action === 'add_row' ? 'design.footer.limit_rows' : 'design.footer.limit_columns', ['max' => $action === 'add_row' ? $spec->maxRows : $spec->maxCells]),
                'not_empty' => (string) __('design.footer.confirm_needed'),
                default => $e->getMessage(),
            });
        } catch (\InvalidArgumentException) {
            throw new \DomainException((string) __('design.notices.unknown'));
        }

        $tree['upper'] = $upper;

        return $tree;
    }

    /** @param  array<string, mixed>  $upper  @return array<string, mixed> */
    private function removeColumn(array $upper, string $id, bool $confirmed, ZoneSpec $spec): array
    {
        foreach ($upper['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['id'] === $id && count($row['cells']) <= $spec->minCells) {
                    throw new \DomainException((string) __('design.footer.last_column'));
                }
            }
        }

        return TreeEditor::removeCell($upper, $id, $confirmed);
    }

    /** @return array<string, mixed> */
    private function newColumn(): array
    {
        return Tree::cell($this->newId(), ['span' => 1, 'align' => 'start'], []);
    }

    private function newId(): string
    {
        return 'n'.Str::lower(Str::random(8));
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
            $fields = $node === null ? [] : $this->fieldsOf($node['type']);
            $components = array_map(fn (string $name, array $field): Component => $this->field($name, $field), array_keys($fields), array_values($fields));

            if ($node !== null && $node['type'] === 'newsletter' && ! Route::has(FooterCatalog::NEWSLETTER_ROUTE)) {
                $components[] = Text::make(__('footer.newsletter_note'))->color('warning')->size('sm');
            }

            return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([Group::make($components)->statePath('settings')])];
        }

        if ($selected['kind'] === 'cell') {
            return [Section::make(__('footer.fields.column'))->schema([
                Select::make('span')->label(__('footer.fields.span'))->native(false)->options(['1' => __('footer.options.span_1'), '2' => __('footer.options.span_2')]),
                Select::make('align')->label(__('footer.fields.align'))->native(false)->options($this->aligns()),
            ])->columns(2)];
        }

        if ($selected['kind'] === 'layout') {
            return $selected['id'] === 'lower' ? $this->lowerComponents() : $this->upperComponents();
        }

        return [Text::make(__('design.footer.row_hint'))];
    }

    /** @return list<Component> */
    private function upperComponents(): array
    {
        return [
            Section::make(__('footer.sections.upper'))->schema([
                Toggle::make('enabled')->label(__('footer.fields.enabled')),
                Select::make('padding')->label(__('footer.fields.padding'))->native(false)->options($this->paddings()),
                ColorPicker::make('colors.bg')->label(__('footer.fields.bg'))->hex(),
                ColorPicker::make('colors.fg')->label(__('footer.fields.text'))->hex(),
                $this->contrast(),
                ColorPicker::make('border_color')->label(__('footer.fields.border_color'))->hex(),
                Select::make('border_width')->label(__('footer.fields.border_width'))->native(false)
                    ->options(array_combine(array_map('strval', FooterLayout::BORDER_WIDTHS), array_map(static fn (int $w): string => (string) __('footer.options.border_'.$w), FooterLayout::BORDER_WIDTHS))),
            ])->columns(2),
        ];
    }

    /** @return list<Component> */
    private function lowerComponents(): array
    {
        return [
            Section::make(__('footer.sections.lower'))->schema([
                Toggle::make('enabled')->label(__('footer.fields.enabled')),
                Select::make('padding')->label(__('footer.fields.padding'))->native(false)->options($this->paddings()),
                ColorPicker::make('colors.bg')->label(__('footer.fields.bg'))->hex(),
                ColorPicker::make('colors.fg')->label(__('footer.fields.text'))->hex(),
                $this->contrast(),
                Select::make('align')->label(__('footer.fields.lower_align'))->native(false)->options($this->aligns()),
                TextInput::make('text_template')->label(__('footer.fields.text_template'))->helperText(__('footer.fields.text_hint'))->maxLength(FooterLayout::MAX_TEMPLATE)->columnSpanFull(),
            ])->columns(2),
        ];
    }

    /** The non-blocking warning under a level's text / background pair: shown only when both are chosen here and the ratio is under 4.5. */
    private function contrast(): Text
    {
        return Text::make(static fn (Get $get): string => (string) __('design.footer.low_contrast', ['ratio' => number_format(AppearanceSettings::contrastRatio((string) $get('colors.fg'), (string) $get('colors.bg')), 2)]))
            ->color('warning')
            ->size('sm')
            ->visible(static fn (Get $get): bool => AppearanceSettings::isHex($get('colors.fg')) && AppearanceSettings::isHex($get('colors.bg'))
                && AppearanceSettings::contrastRatio((string) $get('colors.fg'), (string) $get('colors.bg')) < AppearanceSettings::MIN_CONTRAST);
    }

    /** @return array<string, string> */
    private function paddings(): array
    {
        return array_combine(FooterLayout::PADDINGS, array_map(static fn (string $p): string => (string) __('footer.options.'.$p), FooterLayout::PADDINGS));
    }

    /** @return array<string, string> */
    private function aligns(): array
    {
        return array_combine(FooterLayout::ALIGNS, array_map(static fn (string $a): string => (string) __('footer.options.'.$a), FooterLayout::ALIGNS));
    }

    /** @return array<string, array<string, mixed>> the fields of a block type, each with the footer's own label and option texts */
    private function fieldsOf(string $type): array
    {
        $fields = [];

        foreach (ComponentRegistry::for('footer')[$type]['fields'] ?? [] as $name => $field) {
            $fields[$name] = $field + ['label' => 'footer.settings.'.$name, 'options_prefix' => 'footer.options.'];

            if ($field['kind'] === 'menu') {
                $fields[$name]['place'] = 'footer';
            }
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $field */
    private function field(string $name, array $field): Component
    {
        // the stored shape stays a list of {network, url}; the panel has ONE address field per network, as the old page
        if ($field['kind'] === 'social') {
            return Fieldset::make(__($field['label']))->schema(array_map(
                static fn (string $network): TextInput => TextInput::make('n_'.$network)->label(__('header.social.'.$network))->placeholder('https://')->maxLength(300)->default(''),
                HeaderCatalog::SOCIAL_NETWORKS,
            ))->columns(2);
        }

        return FieldForms::make($name, $field);
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $current = $tree[$layout] ?? [];

        if ($selected === null) {
            return [];
        }

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);

            if ($node === null) {
                return [];
            }

            $settings = $node['settings'];

            if ($node['type'] === 'social') {
                $settings = $this->socialToFields($settings);
            }

            return ['settings' => $settings];
        }

        if ($selected['kind'] === 'cell') {
            $props = TreeEditor::cell($current, $selected['id'])['props'] ?? [];

            return ['span' => (string) ($props['span'] ?? 1), 'align' => $props['align'] ?? 'start'];
        }

        if ($selected['kind'] === 'layout') {
            $props = $current['props'] ?? [];
            $state = ['enabled' => (bool) ($props['enabled'] ?? false), 'padding' => $props['padding'] ?? 'normal', 'colors' => ['bg' => $props['colors']['bg'] ?? null, 'fg' => $props['colors']['fg'] ?? null]];

            if ($selected['id'] === 'upper') {
                return $state + ['border_color' => $props['border_color'] ?? null, 'border_width' => (string) ($props['border_width'] ?? 1)];
            }

            $line = $current['rows'][0]['cells'][0]['items'][0] ?? [];

            return $state + ['align' => $line['common']['align'] ?? 'start', 'text_template' => $line['settings']['text_template'] ?? ''];
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
            $in = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
            $fields = $this->fieldsOf($node['type']);
            $settings = FieldValues::settings(array_filter($fields, static fn (array $f): bool => $f['kind'] !== 'social'), $in);

            if ($node['type'] === 'social') {
                $settings['items'] = $this->socialFromFields($in);
            }

            $current = TreeEditor::setSettings($current, $selected['id'], $settings + $node['settings']);
        } elseif ($selected['kind'] === 'cell' && TreeEditor::cell($current, $selected['id']) !== null) {
            $span = $raw['span'] ?? 1;
            $current = TreeEditor::setCellProps($current, $selected['id'], ['span' => is_numeric($span) ? (int) $span : $span, 'align' => is_string($raw['align'] ?? null) ? $raw['align'] : 'start']);
        } elseif ($selected['kind'] === 'layout') {
            $colors = array_filter(['bg' => $raw['colors']['bg'] ?? null, 'fg' => $raw['colors']['fg'] ?? null], static fn (mixed $c): bool => is_string($c) && $c !== '');
            $props = ['enabled' => (bool) ($raw['enabled'] ?? false), 'padding' => is_string($raw['padding'] ?? null) ? $raw['padding'] : 'normal'];

            if ($layout === 'upper') {
                $border = $raw['border_color'] ?? null;
                $width = $raw['border_width'] ?? 1;
                $props += ['border_color' => is_string($border) && $border !== '' ? $border : null, 'border_width' => is_numeric($width) ? (int) $width : $width];
            }

            $colors !== [] && $props['colors'] = $colors;
            $current['props'] = $props;

            if ($layout === 'lower' && isset($current['rows'][0]['cells'][0]['items'][0])) {
                $align = is_string($raw['align'] ?? null) ? $raw['align'] : 'start';
                $line = &$current['rows'][0]['cells'][0]['items'][0];
                $line['settings']['text_template'] = is_string($raw['text_template'] ?? null) ? $raw['text_template'] : '';
                $line['common'] = $align === 'start' ? [] : ['align' => $align];
                unset($line);
            }
        }

        $tree[$layout] = $current;

        return $tree;
    }

    /** @param  array<string, mixed>  $settings  @return array<string, mixed> the social block's list as one field per network */
    private function socialToFields(array $settings): array
    {
        $fields = [];

        foreach (HeaderCatalog::SOCIAL_NETWORKS as $network) {
            $fields['n_'.$network] = '';
        }

        foreach ($settings['items'] ?? [] as $item) {
            $fields['n_'.$item['network']] = $item['url'];
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $data  @return list<array{network: string, url: string}> the networks that have an address, in the catalog's order */
    private function socialFromFields(array $data): array
    {
        $items = [];

        foreach (HeaderCatalog::SOCIAL_NETWORKS as $network) {
            $url = trim((string) ($data['n_'.$network] ?? ''));

            $url !== '' && $items[] = ['network' => $network, 'url' => $url];
        }

        return $items;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        $validation = FooterValidator::validate(FooterAdapter::fromTree($tree));

        return [$validation->layout, $validation->errors];
    }

    public function locate(array $tree, string $path): ?array
    {
        $at = FooterAdapter::locate($tree, $path);

        // a social item's `.url` / `.network` is the network's ONE field in the panel
        if ($at !== null && $at['kind'] === 'node' && preg_match('/^items\.(\d+)\./', (string) $at['field'], $m) === 1) {
            $node = TreeEditor::node($tree['upper'], $at['id']);
            $item = $node['settings']['items'][(int) $m[1]] ?? null;
            $item !== null && $at['field'] = 'n_'.$item['network'];
        }

        return $at;
    }

    public function fieldPath(array $at): ?string
    {
        if ($at['field'] === null) {
            return null;
        }

        return $at['kind'] === 'node' ? 'settings.'.$at['field'] : $at['field'];
    }

    public function warnings(array $stored): array
    {
        $settings = app(SiteSettingsRepository::class);
        $fallback = static function (string $short) use ($settings): string {
            [$key, , $default] = AppearanceSettings::COLORS[$short];
            $value = $settings->get($key);

            return is_string($value) && AppearanceSettings::isHex($value) ? strtolower($value) : $default;
        };
        $out = [];

        foreach (['upper', 'lower'] as $level) {
            $ratio = AppearanceSettings::contrastRatio($stored[$level]['text'] ?? $fallback('footer_text'), $stored[$level]['bg'] ?? $fallback('footer_bg'));

            if ($ratio < AppearanceSettings::MIN_CONTRAST) {
                $out[] = (string) __('footer.notices.low_contrast', ['level' => __('footer.notices.level_'.$level), 'ratio' => number_format($ratio, 2)]);
            }
        }

        return $out;
    }

    public function save(array $stored): void
    {
        FooterDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        FooterDrafts::publish($stored);
    }

    public function discard(): void
    {
        FooterDrafts::discard();
    }
}
