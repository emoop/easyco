<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use App\Filament\Resources\HtmlBlockResource;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\ReadModels\CatalogSubjectKind;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P7-1: «Значки» — the badges of the product card (badges-research.md): three BUILT-IN badges, each OFF until switched on: «Промо» (the word, or the percent of the printed price), «Нови» (days since the product was created),
 * «Последни бройки» (the stock of the shown variations is 1..N). Each has a switch, its text, position (top over the picture / bottom under the price), colour (a closed palette), shape, priority and its own parameter. A FORM zone with a
 * draft (the one button model of the screen). The panel is narrow: one field per row (admin-ui-standard.md). The preview is the first shown category with the APPLIED working copy.
 *
 * The panel's fields are flat (`sale_enabled`, `sale_text` ...): the working copy keeps ONE row per kind (BadgeSet), the panel maps them both ways and never validates (BadgeSet::validate does).
 */
final class BadgesZone implements DesignZone
{
    public const KEY = 'badges';

    /** The fields every badge has, in the order of the panel. */
    private const COMMON = ['enabled', 'text', 'position', 'colour', 'opacity', 'shape', 'priority'];

    public function key(): string
    {
        return self::KEY;
    }

    public function mode(): string
    {
        return self::FORM;
    }

    public function layouts(): array
    {
        return [];
    }

    public function hasDevices(): bool
    {
        return false;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-badges';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.badges');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return BadgeStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => BadgeStore::startingValues()];
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl(self::KEY);
    }

    public function previewScroll(): string
    {
        return '';
    }

    public function rows(array $tree, string $layout): array
    {
        return [];
    }

    public function rowName(array $row, string $layout): string
    {
        return '';
    }

    public function cellName(array $cell, string $layout): string
    {
        return '';
    }

    public function typeLabel(string $type): string
    {
        return $type;
    }

    public function summary(array $node): string
    {
        return '';
    }

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        return [];
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        return [];
    }

    public function structureProblem(array $tree): ?string
    {
        return null;
    }

    // ---- the panel -------------------------------------------------------------------------------------------------------------------

    /**
     * P7-2: every badge is a Section COLLAPSED by default (the column stays short; the fields stay in the DOM, so «Приложи» / «Публикувай» still read every one). The header says what matters without opening it: the name, «включена»
     * / «изключена» and, when on, the place. The two fields it reads are `live()`: the header follows the switch and the place at once (a round trip, no query).
     *
     * @return list<Component>
     */
    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $options = static fn (string $group, array $values): array => array_combine($values, array_map(static fn (string $value): string => (string) __("design.badges.options.{$group}.{$value}"), $values));
        $sections = [];

        foreach (BadgeSet::KINDS as $kind) {
            $fields = [
                Toggle::make("{$kind}_enabled")->label((string) __('design.badges.fields.enabled'))->live()
                    ->afterLabel(InfoHint::make((string) __("design.badges.hints.{$kind}_enabled"), "{$kind}_enabled")),
                TextInput::make("{$kind}_text")->label((string) __('design.badges.fields.text'))->maxLength(BadgeSet::MAX_TEXT)->placeholder((string) __("storefront.badges.{$kind}"))
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.text'), "{$kind}_text")),
            ];

            if ($kind === BadgeSet::SALE) {
                $fields[] = Select::make('sale_mode')->label((string) __('design.badges.fields.mode'))->native(false)->selectablePlaceholder(false)->options($options('mode', BadgeSet::MODES))
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.sale_mode'), 'sale_mode'));
            }

            if ($kind === BadgeSet::NEW) {
                $fields[] = TextInput::make('new_days')->label((string) __('design.badges.fields.days'))->numeric()->minValue(BadgeSet::MIN_DAYS)->maxValue(BadgeSet::MAX_DAYS)
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.new_days'), 'new_days'));
            }

            if ($kind === BadgeSet::LOW_STOCK) {
                $fields[] = Select::make('low_stock_max')->label((string) __('design.badges.fields.max'))->native(false)->selectablePlaceholder(false)
                    ->options(array_combine(array_map('strval', BadgeSet::STOCK_CHOICES), array_map('strval', BadgeSet::STOCK_CHOICES)))
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.low_stock_max'), 'low_stock_max'));
            }

            array_push($fields, ...$this->look($kind.'_', $kind));

            $sections[] = Section::make(static fn (Get $get): string => self::header((string) __("design.badges.kinds.{$kind}"), (bool) $get("{$kind}_enabled"), (string) $get("{$kind}_position")))
                ->collapsed()->columns(1)->schema($fields);
        }

        $sections[] = Section::make((string) __('design.badges.custom.heading'))->collapsed()->columns(1)->schema([
            Repeater::make('custom')->label((string) __('design.badges.custom.list'))->afterLabel(InfoHint::make((string) __('design.badges.hints.custom'), 'custom'))
                ->addActionLabel((string) __('design.badges.custom.add'))->maxItems(BadgeSet::MAX_CUSTOM)->defaultItems(0)->collapsible()->collapsed()->reorderable(false)->live()
                ->deleteAction(static fn ($action) => $action->label((string) __('design.badges.custom.remove'))->tooltip((string) __('design.badges.custom.remove')))
                ->itemLabel(static fn (array $state): string => self::header(trim((string) ($state['text'] ?? '')) !== '' ? mb_substr(trim((string) $state['text']), 0, 24) : (string) __('design.badges.custom.untitled'), (bool) ($state['enabled'] ?? false), (string) ($state['position'] ?? '')))
                ->schema([
                    Hidden::make('id')->default(static fn (): string => BadgeSet::newId()),
                    Toggle::make('enabled')->label((string) __('design.badges.fields.enabled'))->live()
                        ->afterLabel(InfoHint::make((string) __('design.badges.hints.custom_enabled'), 'custom_enabled')),
                    TextInput::make('text')->label((string) __('design.badges.fields.text'))->required()->maxLength(BadgeSet::MAX_TEXT)
                        ->afterLabel(InfoHint::make((string) __('design.badges.hints.custom_text'), 'custom_text')),
                    ...$this->conditionFields(),
                    ...$this->look('', BadgeSet::CUSTOM),
                ]),
        ]);

        return $sections;
    }

    /** «Горе» / «Долу върху снимката» / «Под цената» after the name and the state: «Промо · включена · Горе». */
    private static function header(string $name, bool $enabled, string $position): string
    {
        $place = $enabled && in_array($position, BadgeSet::POSITIONS, true) ? ' · '.__("design.badges.places.{$position}") : '';

        return $name.' · '.__($enabled ? 'design.badges.on' : 'design.badges.off').$place;
    }

    /**
     * The look every badge has: the place, the colour, the background opacity, the shape and the order.
     *
     * @param  string  $prefix  the field-name prefix (`sale_`; none inside the repeater, whose items are their own state)
     * @return list<Component>
     */
    private function look(string $prefix, string $kind): array
    {
        $options = static fn (string $group, array $values): array => array_combine($values, array_map(static fn (string $value): string => (string) __("design.badges.options.{$group}.{$value}"), $values));
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => InfoHint::make((string) __("design.badges.hints.{$name}"), ($kind === BadgeSet::CUSTOM ? 'custom_' : $prefix).$name);

        return [
            Select::make("{$prefix}position")->label((string) __('design.badges.fields.position'))->native(false)->selectablePlaceholder(false)->live()->options($options('position', BadgeSet::POSITIONS))->afterLabel($hint('position')),
            Select::make("{$prefix}colour")->label((string) __('design.badges.fields.colour'))->native(false)->selectablePlaceholder(false)->options($options('colour', BadgeSet::COLOURS)),
            Select::make("{$prefix}opacity")->label((string) __('design.badges.fields.opacity'))->native(false)->selectablePlaceholder(false)->options(array_combine(array_map('strval', BadgeSet::OPACITIES), array_map(static fn (int $o): string => $o.'%', BadgeSet::OPACITIES)))->afterLabel($hint('opacity')),
            Select::make("{$prefix}shape")->label((string) __('design.badges.fields.shape'))->native(false)->selectablePlaceholder(false)->options($options('shape', BadgeSet::SHAPES)),
            TextInput::make("{$prefix}priority")->label((string) __('design.badges.fields.priority'))->numeric()->minValue(1)->maxValue(99)->afterLabel($hint('priority')),
        ];
    }

    /**
     * A custom badge's ONE condition: the product-page blocks' own «Показвай, когато…» fields (ProductConditions: the same kinds, the same labels and hints), except the attribute rules (a read per card). Collections only while
     * the switch is on; while OFF a Hidden field keeps the stored ids, so saving never drops them.
     *
     * @return list<Component>
     */
    private function conditionFields(): array
    {
        $hint = static fn (string $key): \Illuminate\Support\HtmlString => InfoHint::make((string) __('html_blocks.conditions.hints.'.$key), 'custom_cond_'.$key);
        $names = static fn (string $table): array => DB::table($table)->orderBy('name')->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();

        return [
            Select::make('conditions.on_sale')->label((string) __('html_blocks.conditions.fields.on_sale'))->afterLabel($hint('on_sale'))->native(false)->selectablePlaceholder(false)->default('all')
                ->options(['all' => __('html_blocks.conditions.options.all'), 'yes' => __('html_blocks.conditions.options.on_sale_yes'), 'no' => __('html_blocks.conditions.options.on_sale_no')]),
            Select::make('conditions.categories')->label((string) __('html_blocks.conditions.fields.categories'))->afterLabel($hint('categories'))->native(false)->multiple()->searchable()
                ->options(static fn (): array => HtmlBlockResource::categoryLabels()),
            Select::make('conditions.brands')->label((string) __('html_blocks.conditions.fields.brands'))->afterLabel($hint('brands'))->native(false)->multiple()->searchable()
                ->options(static fn (): array => $names('catalog_brands')),
            Select::make('conditions.tags')->label((string) __('html_blocks.conditions.fields.tags'))->afterLabel($hint('tags'))->native(false)->multiple()->searchable()
                ->options(static fn (): array => $names('catalog_tags')),
            Select::make('conditions.seasons')->label((string) __('html_blocks.conditions.fields.seasons'))->afterLabel($hint('seasons'))->native(false)->multiple()->searchable()
                ->options(static fn (): array => $names('catalog_seasons')),
            Select::make('conditions.collections')->label((string) __('html_blocks.conditions.fields.collections'))->afterLabel($hint('collections'))->native(false)->multiple()->searchable()
                ->visible(static fn (): bool => CatalogSubjectKind::collectionsEnabled())
                ->options(static fn (): array => $names('catalog_collections')),
            Hidden::make('conditions.collections')->visible(static fn (): bool => ! CatalogSubjectKind::collectionsEnabled()),
        ];
    }

    /** The working copy as the panel's flat fields (the built-ins) and the repeater of the custom rows. @return array<string, mixed> */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $state = ['custom' => []];

        foreach (is_array($tree['values']['badges'] ?? null) ? $tree['values']['badges'] : [] as $row) {
            $kind = is_array($row) ? ($row['kind'] ?? null) : null;

            if ($kind === BadgeSet::CUSTOM) {
                $state['custom'][(string) Str::uuid()] = $this->customState($row);

                continue;
            }

            if (! in_array($kind, BadgeSet::KINDS, true)) {
                continue;
            }

            foreach ([...self::COMMON, ...$this->extra($kind)] as $field) {
                $value = $row[$field] ?? null;
                $state["{$kind}_{$field}"] = is_int($value) && in_array($field, ['max', 'opacity'], true) ? (string) $value : $value;
            }
        }

        return $state;
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    private function customState(array $row): array
    {
        $conditions = is_array($row['conditions'] ?? null) ? $row['conditions'] : [];
        $state = ['on_sale' => ! array_key_exists('on_sale', $conditions) ? 'all' : ($conditions['on_sale'] ? 'yes' : 'no')];

        foreach (['categories', 'brands', 'tags', 'seasons', 'collections'] as $key) {
            $state[$key] = is_array($conditions[$key] ?? null) ? array_values($conditions[$key]) : [];
        }

        $out = ['id' => (string) ($row['id'] ?? ''), 'conditions' => $state];

        foreach (self::COMMON as $field) {
            $value = $row[$field] ?? null;
            $out[$field] = is_int($value) && $field === 'opacity' ? (string) $value : $value;
        }

        return $out;
    }

    /** The panel's flat fields put back as the working copy (one row per built-in kind, then the custom rows in the order of the list), never validated here. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $rows = [];

        foreach (BadgeSet::KINDS as $kind) {
            $row = ['kind' => $kind];

            foreach ([...self::COMMON, ...$this->extra($kind)] as $field) {
                $row[$field] = $raw["{$kind}_{$field}"] ?? null;
            }

            // an untouched empty text input arrives as null: it is the empty text (the built-in's default word)
            $row['text'] = $row['text'] ?? '';
            $rows[] = $row;
        }

        foreach (is_array($raw['custom'] ?? null) ? array_values($raw['custom']) : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = ['kind' => BadgeSet::CUSTOM, 'id' => is_string($item['id'] ?? null) && $item['id'] !== '' ? $item['id'] : BadgeSet::newId()];

            foreach (self::COMMON as $field) {
                $row[$field] = $item[$field] ?? null;
            }

            $row['text'] = $row['text'] ?? '';
            $row['conditions'] = ProductConditions::fromForm($item['conditions'] ?? null);
            $rows[] = $row;
        }

        $tree['values'] = ['badges' => $rows];

        return $tree;
    }

    public function validate(array $tree): array
    {
        return BadgeSet::validate($tree['values'] ?? []);
    }

    public function locate(array $tree, string $path): ?array
    {
        // a custom row's message sits at its own row (`custom.1.text`: the screen turns the position into the item's key); a condition's under the first condition field, the list's own (too many) under the list
        if (preg_match('/\Acustom\.(\d+)\.(\w+)\z/', $path, $m) === 1) {
            $field = match ($m[2]) {
                'conditions' => 'conditions.on_sale',
                'id' => 'text',
                default => $m[2],
            };

            return ['kind' => 'form', 'id' => self::KEY, 'field' => "custom.{$m[1]}.{$field}"];
        }

        return ['kind' => 'form', 'id' => self::KEY, 'field' => $path === 'custom' ? 'custom' : (str_contains($path, '.') ? str_replace('.', '_', $path) : null)];
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'];
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    // ---- storage ---------------------------------------------------------------------------------------------------------------------

    /** «Начални настройки»: every built-in OFF at its defaults (nothing is printed until one is switched on). @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => BadgeSet::defaults()];
    }

    public function save(array $stored): void
    {
        BadgeStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        BadgeStore::publish($stored);
    }

    public function discard(): void
    {
        BadgeStore::discard();
    }

    /** @return list<string> the parameter field of a kind */
    private function extra(string $kind): array
    {
        return match ($kind) {
            BadgeSet::SALE => ['mode'],
            BadgeSet::NEW => ['days'],
            BadgeSet::FREE_SHIPPING => [],
            default => ['max'],
        };
    }
}
