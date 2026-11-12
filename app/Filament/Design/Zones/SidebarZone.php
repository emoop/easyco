<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Sidebar\SidebarFilters;
use App\Storefront\Sidebar\SidebarStore;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * P1: «Сайдбар» — the shop's filters as ONE ordered list: each row is the filter's name, a switch «Показвай» and ↑ ↓ to reorder; a built-in (brand, season, tag) also has its kind (list / links / buttons). The list
 * drives the side panel AND the top filter bar. A FORM zone with a draft (the one button model of the screen). The panel is narrow: one field per row (admin-ui-standard.md). The preview is the first shown category
 * with the APPLIED working copy (the layout, side and widths are the published «Каталог» ones).
 */
final class SidebarZone implements DesignZone
{
    public const KEY = 'sidebar';

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
        return 'filament.pages.design.partials.schema-sidebar';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.sidebar');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return SidebarStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => SidebarStore::startingValues()];
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

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $names = app(FilterCatalog::class)->names();
        $kinds = array_combine(SidebarFilters::KINDS, array_map(static fn (string $kind): string => (string) __('design.sidebar.kinds.'.$kind), SidebarFilters::KINDS));

        return [Section::make((string) __('design.sidebar.heading'))->columns(1)->schema([
            Repeater::make('filters')
                ->label((string) __('design.sidebar.fields.filters'))
                ->afterLabel(InfoHint::make((string) __('design.sidebar.hints.filters'), 'filters'))
                ->addable(false)->deletable(false)->reorderableWithButtons()->collapsible(false)->defaultItems(0)
                ->itemLabel(static fn (array $state): string => (string) ($names[(string) ($state['key'] ?? '')] ?? ''))
                ->schema([
                    Hidden::make('key'),
                    Toggle::make('show')->label((string) __('design.sidebar.fields.show'))
                        ->afterLabel(InfoHint::make((string) __('design.sidebar.hints.show'), 'show')),
                    Select::make('kind')->label((string) __('design.sidebar.fields.kind'))->native(false)->selectablePlaceholder(false)->options($kinds)->default('list')
                        ->afterLabel(InfoHint::make((string) __('design.sidebar.hints.kind'), 'kind'))
                        ->visible(static fn (Get $get): bool => in_array($get('key'), SidebarFilters::BUILTIN, true)),
                ]),
        ])];
    }

    /** The working copy as the panel's rows: the list, a built-in's row carrying its kind. */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $values = $tree['values'] ?? [];
        $rows = [];

        foreach (is_array($values['filters'] ?? null) ? $values['filters'] : [] as $row) {
            $key = (string) ($row['key'] ?? '');
            $kind = SidebarFilters::kindToPrint($row['kind'] ?? ($values['kinds'][$key] ?? null));
            $rows[] = ['key' => $key, 'show' => (bool) ($row['show'] ?? true)] + (in_array($key, SidebarFilters::BUILTIN, true) ? ['kind' => $kind] : []);
        }

        return ['filters' => $rows];
    }

    /** The panel's rows (in the order the merchant left them) put back as the working copy, never validated here. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $rows = [];

        foreach (is_array($raw['filters'] ?? null) ? array_values($raw['filters']) : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['key'] ?? null;
            $rows[] = ['key' => is_string($key) || is_int($key) ? (string) $key : '', 'show' => $row['show'] ?? null] + (array_key_exists('kind', $row) && $row['kind'] !== null ? ['kind' => $row['kind']] : []);
        }

        $tree['values'] = ['filters' => $rows];

        return $tree;
    }

    public function validate(array $tree): array
    {
        return SidebarFilters::validate($tree['values'] ?? [], app(FilterCatalog::class)->knownKeys(), app(FilterCatalog::class)->hiddenByDefault());
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => 'filters'];
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

    /** «Начални настройки»: today's filters in today's order, the older defaults (brand and season shown, tag hidden). @return array<string, mixed> */
    public function defaultTree(): array
    {
        $defaults = PageDesign::defaults(PageDesign::CATALOG);
        $known = app(FilterCatalog::class)->knownKeys();
        $kindKeys = SidebarFilters::kindKeys($known);

        return ['values' => [
            'filters' => SidebarFilters::defaults($known, array_combine($kindKeys, array_map(static fn (string $key): string => (string) $defaults[CatalogSubjectKind::from($key)->catalogField()], $kindKeys)), app(FilterCatalog::class)->hiddenByDefault()),
            'kinds' => array_fill_keys($kindKeys, 'list'),
        ]];
    }

    public function save(array $stored): void
    {
        SidebarStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        SidebarStore::publish($stored);
    }

    public function discard(): void
    {
        SidebarStore::discard();
    }
}
