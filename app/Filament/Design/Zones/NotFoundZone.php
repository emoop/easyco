<?php

namespace App\Filament\Design\Zones;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Design\FieldForms;
use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\NotFound\NotFoundSet;
use App\Storefront\NotFound\NotFoundStore;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * NF-1: «Страница 404» — the content of the storefront 404 page (same address, status 404, never a redirect): the title, a plain text, an optional search box, ONE block below (the category tiles, a product grid
 * or an HTML block) and the button home. A FORM zone with a draft (the one button model of the screen: «Приложи» writes the draft, «Публикувай» publishes it). The panel is narrow: ONE field per row, every explanation
 * an info icon (admin-ui-standard.md). The product grid's fields are the HOME page product grid's own (its schema and validator); the HTML block is picked the way the other zones pick it.
 *
 * The panel's values (`props`) are flat; the working copy (`tree['values']`) is the validated shape of NotFoundSet. The conversion is applyProps() (never validates) and propsState().
 *
 * @phpstan-import-type Set from NotFoundSet
 */
final class NotFoundZone implements DesignZone
{
    public const KEY = 'notfound';

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
        return 'filament.pages.design.partials.schema-notfound';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.notfound');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return NotFoundStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => NotFoundStore::startingValues()];
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
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => InfoHint::make((string) __('design.notfound.hints.'.$name), 'notfound-'.$name);
        $grid = HomeCatalog::all()['product_grid']['schema'];
        $control = function (string $name, array $field): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.products.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel(InfoHint::make((string) __('design.home.products.hints.'.$name), 'notfound-products-'.$name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $bottom = static fn (Get $get): string => in_array($get('bottom'), NotFoundSet::BOTTOMS, true) ? (string) $get('bottom') : NotFoundSet::DEFAULT_BOTTOM;

        return [
            TextInput::make('title')->label((string) __('design.notfound.fields.title'))->afterLabel($hint('title'))->maxLength(NotFoundSet::TITLE_MAX)->default(''),
            Textarea::make('text')->label((string) __('design.notfound.fields.text'))->afterLabel($hint('text'))->maxLength(NotFoundSet::TEXT_MAX)->rows(3)->default(''),
            Toggle::make('search')->label((string) __('design.notfound.fields.search'))->afterLabel($hint('search'))->default(true),
            Select::make('bottom')->label((string) __('design.notfound.fields.bottom'))->afterLabel($hint('bottom'))->native(false)->selectablePlaceholder(false)->live()->default(NotFoundSet::DEFAULT_BOTTOM)
                ->options(array_combine(NotFoundSet::BOTTOMS, array_map(static fn (string $b): string => (string) __('design.notfound.bottoms.'.$b), NotFoundSet::BOTTOMS))),
            Select::make('tiles')->label((string) __('design.notfound.fields.tiles'))->afterLabel($hint('tiles'))->native(false)->selectablePlaceholder(false)->default(NotFoundSet::DEFAULT_TILES)
                ->options(array_combine(NotFoundSet::TILE_COUNTS, NotFoundSet::TILE_COUNTS))
                ->visible(static fn (Get $get): bool => $bottom($get) === 'tiles'),
            Group::make([
                Select::make('source')->label((string) __('design.home.products.settings.source'))->afterLabel(InfoHint::make((string) __('design.home.products.hints.source'), 'notfound-products-source'))->native(false)->selectablePlaceholder(false)->live()->default('newest')
                    ->options(array_combine(HomeCatalog::offered(HomeCatalog::PRODUCT_SOURCES), array_map(static fn (string $s): string => (string) __('design.home.products.sources.'.$s), HomeCatalog::offered(HomeCatalog::PRODUCT_SOURCES))))
                    ->afterStateUpdated(static fn (Set $set) => $set('entity', null)),
                Select::make('entity')->label((string) __('design.home.products.settings.entity'))->afterLabel(InfoHint::make((string) __('design.home.products.hints.entity'), 'notfound-products-entity'))->native(false)->searchable()->live()->default(null)
                    ->options(fn (Get $get): array => HomeZone::tileEntities((string) $get('source')))
                    ->visible(static fn (Get $get): bool => in_array((string) $get('source'), HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true)),
                $control('count', $grid['count']),
                $control('sort', $grid['sort']),
            ])->statePath('products')->visible(static fn (Get $get): bool => $bottom($get) === 'products'),
            Select::make('block')->label((string) __('design.notfound.fields.block'))->afterLabel($hint('block'))->native(false)->searchable()->default(null)
                ->options(static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all())
                ->visible(static fn (Get $get): bool => $bottom($get) === 'block'),
            Toggle::make('button')->label((string) __('design.notfound.fields.button'))->afterLabel($hint('button'))->default(true),
        ];
    }

    // ---- the working copy <-> the panel ----------------------------------------------------------------------------------------------

    /** @param  Set  $set */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $set = NotFoundSet::read($tree['values'] ?? null);
        $products = $set['products'];

        return [
            'title' => $set['title'],
            'text' => $set['text'],
            'search' => $set['search'],
            'bottom' => $set['bottom'],
            'tiles' => $set['tiles'],
            'products' => ['source' => (string) $products['source'], 'entity' => $products['entity'] ?? null, 'count' => (string) $products['count'], 'sort' => (string) $products['sort']],
            'block' => $set['block'],
            'button' => $set['button'],
        ];
    }

    /** The panel's values put back as the working copy, never validated here (the grid's columns and gap keep the working copy's own value). */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $kept = is_array($tree['values']['products'] ?? null) ? $tree['values']['products'] : NotFoundSet::productDefaults();
        $products = is_array($raw['products'] ?? null) ? $raw['products'] : [];

        $tree['values'] = [
            'title' => is_string($raw['title'] ?? null) ? $raw['title'] : '',
            'text' => is_string($raw['text'] ?? null) ? $raw['text'] : '',
            'search' => (bool) ($raw['search'] ?? false),
            'bottom' => $raw['bottom'] ?? NotFoundSet::DEFAULT_BOTTOM,
            'tiles' => is_int($raw['tiles'] ?? null) ? (string) $raw['tiles'] : ($raw['tiles'] ?? NotFoundSet::DEFAULT_TILES),
            'products' => [
                'source' => $products['source'] ?? 'newest',
                'entity' => FieldValues::id($products['entity'] ?? null),
                'count' => (string) ($products['count'] ?? $kept['count']),
                'sort' => $products['sort'] ?? 'newest',
            ] + $kept,
            'block' => FieldValues::id($raw['block'] ?? null),
            'button' => (bool) ($raw['button'] ?? false),
        ];

        return $tree;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return NotFoundSet::validate($tree['values'] ?? null);
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => $path];
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'];
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        NotFoundStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        NotFoundStore::publish($stored);
    }

    public function discard(): void
    {
        NotFoundStore::discard();
    }

    /** «Начални настройки»: the default 404 page. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => NotFoundSet::defaults()];
    }
}
