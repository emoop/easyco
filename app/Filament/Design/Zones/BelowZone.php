<?php

namespace App\Filament\Design\Zones;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Design\FieldForms;
use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\Home\CarouselGeometry;
use App\Storefront\Home\HomeCatalog;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * P4: «Под галерията» — the slots BELOW the main area (gallery | right column) of every product page: ONE template for all products, an ordered list of at most 6 slots, each a carousel (the shared HP-5 one,
 * with the extra source «Подобни продукти») or an HTML block, with an optional heading with lines above it. A FORM zone with a draft (the one button model of the screen). The panel is narrow: ONE field per row,
 * info icons, texts in bg and en (admin-ui-standard.md).
 *
 * The panel's own values (`props`) are `{slots: list<{type, heading, block, carousel…}>}`; the working copy (`tree['values']`) is the validated shape of BelowSlots. The conversion is applyProps() (never
 * validates) and propsState(). The panel's slot type has THREE entries: «Карусел», «Подобни продукти» (a carousel whose source is `similar`: a shortcut) and «HTML блок»; what is stored is a carousel or an HTML block.
 *
 * @phpstan-import-type Set from BelowSlots
 */
final class BelowZone implements DesignZone
{
    public const KEY = 'below';

    /** the slot types of the panel */
    private const TYPES = ['carousel', 'similar', 'html_block', 'tabs'];

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
        return 'filament.pages.design.partials.schema-below';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.below');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return BelowStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => BelowStore::startingValues()];
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
        return [
            Repeater::make('slots')->label((string) __('design.below.slots'))->afterLabel(InfoHint::make((string) __('design.below.hints.slots'), 'below-slots'))
                ->addActionLabel((string) __('design.below.add'))->maxItems(BelowSlots::MAX)->defaultItems(0)->reorderableWithButtons()->collapsible()->live()
                ->deleteAction(static fn ($action) => $action->label((string) __('design.below.remove'))->tooltip((string) __('design.below.remove')))
                ->itemLabel(static fn (array $state): string => trim((string) __('design.below.types.'.(in_array($state['type'] ?? null, self::TYPES, true) ? $state['type'] : 'carousel')).($state['heading'] ?? '' ? ' — '.mb_substr((string) $state['heading'], 0, 30) : '')))
                ->schema($this->slotFields()),
        ];
    }

    /**
     * ONE slot's fields, one per row: its type, the optional heading, then the HTML block, or the carousel's own settings (the home carousel's controls; «Подобни продукти» shows only the count, the order,
     * the arrows and the label).
     *
     * @return list<Component>
     */
    private function slotFields(): array
    {
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => InfoHint::make((string) __('design.below.hints.'.$name), 'below-'.$name);
        $carousel = HomeCatalog::all()['carousel']['schema'];
        $blocks = static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all();
        $type = static fn (Get $get): string => in_array($get('type'), self::TYPES, true) ? (string) $get('type') : 'carousel';
        // (read INSIDE the carousel group, whose state path is `carousel`: the slot's type is one level up)
        $kind = static fn (Get $get): string => in_array($get('kind'), HomeCatalog::CONTENT_CAROUSEL_KINDS, true) ? (string) $get('kind') : 'products';
        $control = function (string $name, array $field): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.carousel.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.'.$name), 'below-carousel-'.$name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };

        return [
            Select::make('type')->label((string) __('design.below.type'))->afterLabel($hint('type'))->native(false)->selectablePlaceholder(false)->live()->default('similar')
                ->options(array_combine(self::TYPES, array_map(static fn (string $t): string => (string) __('design.below.types.'.$t), self::TYPES))),
            TextInput::make('heading')->label((string) __('design.below.heading'))->afterLabel($hint('heading'))->maxLength(\App\Storefront\Content\ContentTemplate::HEADING_MAX)->default(''),
            Select::make('sections')->label((string) __('design.below.sections'))->afterLabel($hint('sections'))->native(false)->multiple()->maxItems(\App\Storefront\Sections\SectionSet::MAX)->default([])
                ->options(static fn (): array => array_column(\App\Storefront\Sections\SectionStore::startingValues()['sections'], 'title', 'key'))
                ->visible(static fn (Get $get): bool => $type($get) === 'tabs'),
            Select::make('block')->label((string) __('design.below.block'))->afterLabel($hint('block'))->native(false)->searchable()->placeholder((string) __('design.below.none'))->options($blocks)->default(null)
                ->visible(static fn (Get $get): bool => $type($get) === 'html_block'),
            Group::make([
                Select::make('kind')->label((string) __('design.home.carousel.settings.kind'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.kind'), 'below-carousel-kind'))->native(false)->selectablePlaceholder(false)->live()->default('products')
                    ->options(array_combine(HomeCatalog::CONTENT_CAROUSEL_KINDS, array_map(static fn (string $k): string => (string) __('design.home.carousel.kinds.'.$k), HomeCatalog::CONTENT_CAROUSEL_KINDS)))
                    ->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'carousel'),
                Select::make('source')->label((string) __('design.home.carousel.settings.source'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.source'), 'below-carousel-source'))->native(false)->selectablePlaceholder(false)->live()->default('newest')
                    ->options(array_combine(HomeCatalog::PRODUCT_SOURCES, array_map(static fn (string $s): string => (string) __('design.home.products.sources.'.$s), HomeCatalog::PRODUCT_SOURCES)))
                    ->afterStateUpdated(static fn (Set $set) => $set('entity', null))
                    ->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'carousel' && $kind($get) === 'products'),
                Select::make('entity')->label((string) __('design.home.carousel.settings.entity'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.entity'), 'below-carousel-entity'))->native(false)->searchable()->live()->default(null)
                    ->options(fn (Get $get): array => HomeZone::tileEntities((string) $get('source')))
                    ->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'carousel' && $kind($get) === 'products' && in_array((string) $get('source'), HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true)),
                $control('count', $carousel['count'])->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'similar' || $kind($get) === 'products'),
                $control('sort', $carousel['sort'])->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'similar' || $kind($get) === 'products'),
                $control('arrows', $carousel['arrows']),
                $control('label', $carousel['label'])->columnSpanFull(),
                Repeater::make('block_items')->label((string) __('design.home.carousel.settings.blocks'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.blocks'), 'below-carousel-blocks'))
                    ->maxItems(HomeCatalog::CAROUSEL_BLOCKS_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.carousel.block_add'))->columnSpanFull()
                    ->schema([Select::make('block')->label((string) __('design.home.carousel.settings.block'))->native(false)->options($blocks)->required()])
                    ->visible(static fn (Get $get): bool => ($get('../type') ?? 'carousel') === 'carousel' && $kind($get) === 'blocks'),
            ])->statePath('carousel')->visible(static fn (Get $get): bool => in_array($type($get), ['carousel', 'similar'], true)),
        ];
    }

    // ---- the working copy <-> the panel -----------------------------------------------------------------------------------------------

    /** @param  Set  $set */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $set = is_array($tree['values'] ?? null) ? $tree['values'] : BelowSlots::blank();
        $slots = [];

        foreach ($set['slots'] ?? [] as $slot) {
            $slots[(string) \Illuminate\Support\Str::uuid()] = self::formOf($slot);
        }

        return ['slots' => $slots];
    }

    /** The panel's values put back as the working copy, never validated here. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $slots = [];

        foreach (is_array($raw['slots'] ?? null) ? array_values($raw['slots']) : [] as $form) {
            is_array($form) && $slots[] = self::slotOf($form);
        }

        $tree['values'] = ['slots' => $slots];

        return $tree;
    }

    /**
     * A slot as the panel's fields.
     *
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private static function formOf(array $slot): array
    {
        $settings = is_array($slot['settings'] ?? null) ? $slot['settings'] : [];
        $similar = ($slot['type'] ?? '') === 'carousel' && ($settings['source'] ?? null) === HomeCatalog::SIMILAR_SOURCE && ($settings['kind'] ?? 'products') === 'products';

        return [
            'type' => match (true) {
                ($slot['type'] ?? '') === 'html_block' => 'html_block',
                ($slot['type'] ?? '') === 'tabs' => 'tabs',
                $similar => 'similar',
                default => 'carousel',
            },
            'heading' => (string) ($slot['heading'] ?? ''),
            'block' => ($slot['type'] ?? '') === 'html_block' ? ($slot['html_block'] ?? null) : null,
            'sections' => ($slot['type'] ?? '') === 'tabs' && is_array($slot['sections'] ?? null) ? array_values($slot['sections']) : [],
            'carousel' => [
                'kind' => in_array($settings['kind'] ?? null, HomeCatalog::CONTENT_CAROUSEL_KINDS, true) ? $settings['kind'] : 'products',
                'source' => in_array($settings['source'] ?? null, HomeCatalog::PRODUCT_SOURCES, true) ? $settings['source'] : 'newest',
                'entity' => $settings['entity'] ?? null,
                'count' => (string) ($settings['count'] ?? CarouselGeometry::DEFAULT_COUNT),
                'sort' => $settings['sort'] ?? 'newest',
                'arrows' => ($settings['arrows'] ?? true) !== false,
                'label' => (string) ($settings['label'] ?? ''),
                'block_items' => array_map(static fn (mixed $id): array => ['block' => $id], array_values(is_array($settings['blocks'] ?? null) ? $settings['blocks'] : [])),
            ],
        ];
    }

    /**
     * The panel's fields as a slot (not validated: that is validate()'s job).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function slotOf(array $form): array
    {
        $type = $form['type'] ?? 'similar';
        $heading = is_string($form['heading'] ?? '') ? (string) ($form['heading'] ?? '') : '';

        if ($type === 'html_block') {
            return ['type' => 'html_block', 'heading' => $heading, 'html_block' => FieldValues::id($form['block'] ?? null)];
        }

        if ($type === 'tabs') {
            return ['type' => 'tabs', 'heading' => $heading, 'sections' => is_array($form['sections'] ?? null) ? array_values($form['sections']) : []];
        }

        $carousel = is_array($form['carousel'] ?? null) ? $form['carousel'] : [];
        $similar = $type === 'similar';

        return ['type' => 'carousel', 'heading' => $heading, 'settings' => [
            'kind' => $similar ? 'products' : ($carousel['kind'] ?? 'products'),
            'source' => $similar ? HomeCatalog::SIMILAR_SOURCE : ($carousel['source'] ?? 'newest'),
            'entity' => $similar ? null : FieldValues::id($carousel['entity'] ?? null),
            'count' => (string) ($carousel['count'] ?? CarouselGeometry::DEFAULT_COUNT),
            'sort' => $carousel['sort'] ?? 'newest',
            'arrows' => ($carousel['arrows'] ?? true) !== false,
            'label' => (string) ($carousel['label'] ?? ''),
            'blocks' => $similar ? [] : array_values(array_filter(array_map(static fn (mixed $row): mixed => is_array($row) ? FieldValues::id($row['block'] ?? null) : null, is_array($carousel['block_items'] ?? null) ? array_values($carousel['block_items']) : []), static fn (mixed $id): bool => $id !== null)),
        ]];
    }

    // ---- validation and storage ----------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return BelowSlots::validate($tree['values'] ?? []);
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => $path];
    }

    public function fieldPath(array $at): ?string
    {
        // the messages are shown under the list of slots: one place, always on screen
        return 'slots';
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        BelowStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        BelowStore::publish($stored);
    }

    public function discard(): void
    {
        BelowStore::discard();
    }

    /** «Начални настройки»: no slot, every product page as it always was. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => BelowSlots::blank()];
    }
}
