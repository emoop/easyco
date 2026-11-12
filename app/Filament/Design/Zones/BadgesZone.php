<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

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
    private const COMMON = ['enabled', 'text', 'position', 'colour', 'shape', 'priority'];

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

    /** @return list<Component> */
    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $options = static fn (string $group, array $values): array => array_combine($values, array_map(static fn (string $value): string => (string) __("design.badges.options.{$group}.{$value}"), $values));
        $sections = [];

        foreach (BadgeSet::KINDS as $kind) {
            $fields = [
                Toggle::make("{$kind}_enabled")->label((string) __('design.badges.fields.enabled'))
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

            array_push(
                $fields,
                Select::make("{$kind}_position")->label((string) __('design.badges.fields.position'))->native(false)->selectablePlaceholder(false)->options($options('position', BadgeSet::POSITIONS))
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.position'), "{$kind}_position")),
                Select::make("{$kind}_colour")->label((string) __('design.badges.fields.colour'))->native(false)->selectablePlaceholder(false)->options($options('colour', BadgeSet::COLOURS)),
                Select::make("{$kind}_shape")->label((string) __('design.badges.fields.shape'))->native(false)->selectablePlaceholder(false)->options($options('shape', BadgeSet::SHAPES)),
                TextInput::make("{$kind}_priority")->label((string) __('design.badges.fields.priority'))->numeric()->minValue(1)->maxValue(99)
                    ->afterLabel(InfoHint::make((string) __('design.badges.hints.priority'), "{$kind}_priority")),
            );

            $sections[] = Section::make((string) __("design.badges.kinds.{$kind}"))->columns(1)->schema($fields);
        }

        return $sections;
    }

    /** The working copy as the panel's flat fields. @return array<string, mixed> */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $state = [];

        foreach (is_array($tree['values']['badges'] ?? null) ? $tree['values']['badges'] : [] as $row) {
            $kind = is_array($row) ? ($row['kind'] ?? null) : null;

            if (! in_array($kind, BadgeSet::KINDS, true)) {
                continue;
            }

            foreach ([...self::COMMON, ...$this->extra($kind)] as $field) {
                $value = $row[$field] ?? null;
                $state["{$kind}_{$field}"] = is_int($value) && $field === 'max' ? (string) $value : $value;
            }
        }

        return $state;
    }

    /** The panel's flat fields put back as the working copy (one row per kind), never validated here. */
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

        $tree['values'] = ['badges' => $rows];

        return $tree;
    }

    public function validate(array $tree): array
    {
        return BadgeSet::validate($tree['values'] ?? []);
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => str_contains($path, '.') ? str_replace('.', '_', $path) : null];
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
            default => ['max'],
        };
    }
}
