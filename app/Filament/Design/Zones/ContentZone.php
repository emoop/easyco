<?php

namespace App\Filament\Design\Zones;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Design\FieldForms;
use App\Filament\Design\InfoHint;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use App\Storefront\Content\ContentTemplate;
use App\Storefront\Home\HomeCatalog;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * P3: «Съдържание» — the templates of the LISTING pages: category, brand, season and tag pages. For each kind ONE default template (the «Общ шаблон»: the title's quick-navigation cloud, a block above the list, a
 * block or the shared carousel below it) and, for a specific page found by name, ITS OWN template, which replaces the default completely (nothing is mixed). «Върни към общия» removes only that page's template.
 * A FORM zone with a draft (the one button model of the screen). The panel is narrow: ONE field per row, info icons, texts in bg and en (admin-ui-standard.md). The product page's «Съдържание» (part 4) will
 * join this zone later: this class holds the listing pages only.
 *
 * The panel's own values (`props`) are the form of the set, `{kind, <kind>: {default: Form, own: list<{subject, label, …Form}>, add}}`; the working copy (`tree['values']`) is the validated shape of ContentSet. The
 * conversion is applyProps() (never validates) and propsState().
 *
 * @phpstan-import-type Set from ContentSet
 */
final class ContentZone implements DesignZone
{
    public const KEY = 'content';

    /** kind => the source a subject of that kind is looked up with (HomeZone::tileEntities) */
    private const SOURCE_OF = ['categories' => 'category', 'brands' => 'brand', 'seasons' => 'season', 'tags' => 'tag'];

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
        return 'filament.pages.design.partials.schema-content';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.content');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return ContentStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => ContentStore::startingValues(), 'kind' => 'categories'];
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
        $sections = [
            Select::make('cloud_shape')->label((string) __('design.content.cloud_shape'))->afterLabel(InfoHint::make((string) __('design.content.hints.cloud_shape'), 'content-cloud-shape'))->native(false)->selectablePlaceholder(false)->live()
                ->options(array_combine(ContentSet::CLOUD_SHAPES, array_map(static fn (string $s): string => (string) __('design.content.cloud_shapes.'.$s), ContentSet::CLOUD_SHAPES))),
            Select::make('title_style')->label((string) __('design.content.title_style'))->afterLabel(InfoHint::make((string) __('design.content.hints.title_style'), 'content-title-style'))->native(false)->selectablePlaceholder(false)->live()
                ->options(array_combine(ContentSet::TITLE_STYLES, array_map(static fn (string $s): string => (string) __('design.content.title_styles.'.$s), ContentSet::TITLE_STYLES))),
            // DRIBNI-5: the cloud's button colours; empty = the shop's look (a valid hex only, checked on save)
            ColorPicker::make('cloud_bg')->label((string) __('design.content.cloud_bg'))->afterLabel(InfoHint::make((string) __('design.content.hints.cloud_bg'), 'content-cloud-bg'))->hex()->live(),
            ColorPicker::make('cloud_color')->label((string) __('design.content.cloud_color'))->afterLabel(InfoHint::make((string) __('design.content.hints.cloud_color'), 'content-cloud-color'))->hex()->live(),
            Select::make('kind')->label((string) __('design.content.kind'))->afterLabel(InfoHint::make((string) __('design.content.hints.kind'), 'content-kind'))->native(false)->selectablePlaceholder(false)->live()
            ->options(array_combine(array_keys(ContentTemplate::KINDS), array_map(static fn (string $kind): string => (string) __('design.content.kinds.'.$kind), array_keys(ContentTemplate::KINDS))))];

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $sections[] = Group::make([
                Section::make((string) __('design.content.default'))->columns(1)->schema([Group::make($this->templateFields())->statePath('default')]),
                Section::make((string) __('design.content.own_title'))->columns(1)->schema([
                    Select::make('add')->label((string) __('design.content.add.'.$kind))->afterLabel(InfoHint::make((string) __('design.content.hints.add'), 'content-add-'.$kind))->native(false)->searchable()->live()->default(null)
                        ->getSearchResultsUsing(fn (string $search): array => $this->search($kind, $search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => ContentZone::names($kind)[(int) $value] ?? null)
                        ->afterStateUpdated(function (mixed $state, Set $set, Get $get) use ($kind): void {
                            $id = FieldValues::id($state);
                            $set('add', null);

                            if (! is_int($id) || ! array_key_exists($id, self::names($kind))) {
                                return;
                            }

                            $own = is_array($get('own')) ? $get('own') : [];

                            foreach ($own as $item) {
                                if ((int) ($item['subject'] ?? 0) === $id) {
                                    return;
                                }
                            }

                            $set('own', $own + [(string) Str::uuid() => ['subject' => $id, 'label' => self::names($kind)[$id]] + self::formOf(ContentTemplate::blank())]);
                        }),
                    Repeater::make('own')->label((string) __('design.content.own_list'))->afterLabel(InfoHint::make((string) __('design.content.hints.own_list'), 'content-own-'.$kind))
                        ->addable(false)->reorderable(false)->collapsible()->collapsed()->defaultItems(0)->live()
                        ->itemLabel(static fn (array $state): string => (string) ($state['label'] ?? ''))
                        ->deleteAction(static fn ($action) => $action->label((string) __('design.content.revert'))->tooltip((string) __('design.content.revert'))->requiresConfirmation()
                            ->modalHeading((string) __('design.content.revert_heading'))->modalDescription((string) __('design.content.revert_text'))->modalSubmitActionLabel((string) __('design.content.revert')))
                        ->schema([Hidden::make('subject'), Hidden::make('label'), ...$this->templateFields()]),
                ]),
            ])->statePath($kind)->visible(static fn (Get $get): bool => $get('kind') === $kind);
        }

        return $sections;
    }

    /**
     * ONE template's fields, one per row: the cloud switch, the top block, what is below (nothing | an HTML block | a carousel) and the carousel's own settings (the home carousel's controls: its source list has
     * «Тази страница»). The same fields serve the kind's default and every own template.
     *
     * @return list<Component>
     */
    private function templateFields(): array
    {
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => InfoHint::make((string) __('design.content.hints.'.$name), 'content-'.$name);
        $carousel = HomeCatalog::all()['carousel']['schema'];
        $blocks = static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all();
        $bottom = static fn (Get $get): string => in_array($get('bottom_type'), ['html_block', 'carousel'], true) ? (string) $get('bottom_type') : '';
        // (read INSIDE the carousel group, whose state path is `carousel`)
        $kind = static fn (Get $get): string => in_array($get('kind'), HomeCatalog::CONTENT_CAROUSEL_KINDS, true) ? (string) $get('kind') : 'products';
        $control = function (string $name, array $field) use ($hint): Component {
            $control = FieldForms::make($name, $field + ['label' => 'design.home.carousel.settings.'.$name]);
            method_exists($control, 'afterLabel') && $control->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.'.$name), 'content-carousel-'.$name));
            method_exists($control, 'live') && $control->live();

            return $control;
        };
        $sources = array_combine([...HomeCatalog::PRODUCT_SOURCES, HomeCatalog::CURRENT_SOURCE], [...array_map(static fn (string $s): string => (string) __('design.home.products.sources.'.$s), HomeCatalog::PRODUCT_SOURCES), (string) __('design.content.sources.current')]);

        return [
            Toggle::make('cloud')->label((string) __('design.content.fields.cloud'))->afterLabel($hint('cloud'))->default(false),
            Toggle::make('title')->label((string) __('design.content.fields.title'))->afterLabel($hint('title'))->default(true),
            Select::make('top')->label((string) __('design.content.fields.top'))->afterLabel($hint('top'))->native(false)->searchable()->placeholder((string) __('design.content.none'))->options($blocks)->default(null),
            Select::make('bottom_type')->label((string) __('design.content.fields.bottom'))->afterLabel($hint('bottom'))->native(false)->selectablePlaceholder(false)->live()->default('')
                ->options(['' => (string) __('design.content.none'), 'html_block' => (string) __('design.content.bottom_types.html_block'), 'carousel' => (string) __('design.content.bottom_types.carousel')]),
            TextInput::make('bottom_heading')->label((string) __('design.content.fields.bottom_heading'))->afterLabel($hint('bottom_heading'))->maxLength(ContentTemplate::HEADING_MAX)->default('')
                ->visible(static fn (Get $get): bool => $bottom($get) !== ''),
            Select::make('bottom_block')->label((string) __('design.content.fields.bottom_block'))->afterLabel($hint('bottom_block'))->native(false)->searchable()->placeholder((string) __('design.content.none'))->options($blocks)->default(null)
                ->visible(static fn (Get $get): bool => $bottom($get) === 'html_block'),
            Group::make([
                Select::make('kind')->label((string) __('design.home.carousel.settings.kind'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.kind'), 'content-carousel-kind'))->native(false)->selectablePlaceholder(false)->live()->default('products')
                    ->options(array_combine(HomeCatalog::CONTENT_CAROUSEL_KINDS, array_map(static fn (string $k): string => (string) __('design.home.carousel.kinds.'.$k), HomeCatalog::CONTENT_CAROUSEL_KINDS))),
                Select::make('source')->label((string) __('design.home.carousel.settings.source'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.source'), 'content-carousel-source'))->native(false)->selectablePlaceholder(false)->live()->default('current')
                    ->options($sources)->afterStateUpdated(static fn (Set $set) => $set('entity', null))->visible(static fn (Get $get): bool => $kind($get) === 'products'),
                Select::make('entity')->label((string) __('design.home.carousel.settings.entity'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.entity'), 'content-carousel-entity'))->native(false)->searchable()->live()->default(null)
                    ->options(fn (Get $get): array => HomeZone::tileEntities((string) $get('source')))
                    ->visible(static fn (Get $get): bool => $kind($get) === 'products' && in_array((string) $get('source'), HomeCatalog::PRODUCT_SOURCES_WITH_ENTITY, true)),
                $control('count', $carousel['count'])->visible(static fn (Get $get): bool => $kind($get) === 'products'),
                $control('sort', $carousel['sort'])->visible(static fn (Get $get): bool => $kind($get) === 'products'),
                $control('arrows', $carousel['arrows']),
                $control('label', $carousel['label'])->columnSpanFull(),
                Repeater::make('block_items')->label((string) __('design.home.carousel.settings.blocks'))->afterLabel(InfoHint::make((string) __('design.home.carousel.hints.blocks'), 'content-carousel-blocks'))
                    ->maxItems(HomeCatalog::CAROUSEL_BLOCKS_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.home.carousel.block_add'))->columnSpanFull()
                    ->schema([Select::make('block')->label((string) __('design.home.carousel.settings.block'))->native(false)->options($blocks)->required()])
                    ->visible(static fn (Get $get): bool => $kind($get) === 'blocks'),
            ])->statePath('carousel')->visible(static fn (Get $get): bool => $bottom($get) === 'carousel'),
        ];
    }

    // ---- the working copy <-> the panel -----------------------------------------------------------------------------------------------

    /** @param  Set  $set */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $set = is_array($tree['values'] ?? null) ? $tree['values'] : ContentSet::blank();
        $state = ['kind' => in_array($tree['kind'] ?? null, array_keys(ContentTemplate::KINDS), true) ? $tree['kind'] : 'categories'] + ContentSet::style($set['style'] ?? null);

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $names = self::names($kind);
            $own = [];

            foreach ($set[$kind]['own'] ?? [] as $id => $template) {
                // a subject that is gone is not listed (the store already drops it on read)
                isset($names[(int) $id]) && $own[(string) Str::uuid()] = ['subject' => (int) $id, 'label' => $names[(int) $id]] + self::formOf($template);
            }

            $state[$kind] = ['default' => self::formOf($set[$kind]['default'] ?? null), 'own' => $own, 'add' => null];
        }

        return $state;
    }

    /** The panel's values put back as the working copy, never validated here. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        // the shop-wide style as typed (a value that is not in its list is validate()'s to refuse)
        $values = ['style' => ['cloud_shape' => $raw['cloud_shape'] ?? ContentSet::CLOUD_SHAPES[0], 'title_style' => $raw['title_style'] ?? ContentSet::TITLE_STYLES[0], 'cloud_bg' => $raw['cloud_bg'] ?? null, 'cloud_color' => $raw['cloud_color'] ?? null]];

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $entry = is_array($raw[$kind] ?? null) ? $raw[$kind] : [];
            $default = self::templateOf(is_array($entry['default'] ?? null) ? $entry['default'] : []);
            $own = [];

            foreach (is_array($entry['own'] ?? null) ? $entry['own'] : [] as $item) {
                $id = is_array($item) ? FieldValues::id($item['subject'] ?? null) : null;
                is_int($id) && $own[$id] = self::templateOf($item);
            }

            // a default that says nothing is no default: today's page
            $values[$kind] = ['default' => ContentTemplate::isBlank($default) ? null : $default, 'own' => $own];
        }

        $tree['values'] = $values;
        $tree['kind'] = in_array($raw['kind'] ?? null, array_keys(ContentTemplate::KINDS), true) ? $raw['kind'] : 'categories';

        return $tree;
    }

    /**
     * The template as the panel's fields.
     *
     * @param  array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    private static function formOf(?array $template): array
    {
        $template ??= ContentTemplate::blank();
        $bottom = is_array($template['bottom'] ?? null) ? $template['bottom'] : null;
        $settings = is_array($bottom['settings'] ?? null) ? $bottom['settings'] : [];

        return [
            'cloud' => ($template['cloud'] ?? false) === true,
            'title' => ($template['title'] ?? true) !== false,
            'bottom_heading' => (string) ($template['bottom_heading'] ?? ''),
            'top' => $template['top'] ?? null,
            'bottom_type' => $bottom === null ? '' : (string) $bottom['type'],
            'bottom_block' => $bottom !== null && $bottom['type'] === 'html_block' ? ($bottom['html_block'] ?? null) : null,
            'carousel' => [
                'kind' => in_array($settings['kind'] ?? null, HomeCatalog::CONTENT_CAROUSEL_KINDS, true) ? $settings['kind'] : 'products',
                'source' => in_array($settings['source'] ?? null, [...HomeCatalog::PRODUCT_SOURCES, HomeCatalog::CURRENT_SOURCE], true) ? $settings['source'] : HomeCatalog::CURRENT_SOURCE,
                'entity' => $settings['entity'] ?? null,
                'count' => (string) ($settings['count'] ?? \App\Storefront\Home\CarouselGeometry::DEFAULT_COUNT),
                'sort' => $settings['sort'] ?? 'newest',
                'arrows' => ($settings['arrows'] ?? true) !== false,
                'label' => (string) ($settings['label'] ?? ''),
                'block_items' => array_map(static fn (mixed $id): array => ['block' => $id], array_values(is_array($settings['blocks'] ?? null) ? $settings['blocks'] : [])),
            ],
        ];
    }

    /**
     * The panel's fields as a template (not validated: that is validate()'s job).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function templateOf(array $form): array
    {
        $type = $form['bottom_type'] ?? '';
        $carousel = is_array($form['carousel'] ?? null) ? $form['carousel'] : [];
        $bottom = null;

        if ($type === 'html_block') {
            $bottom = ['type' => 'html_block', 'html_block' => FieldValues::id($form['bottom_block'] ?? null)];
        } elseif ($type === 'carousel') {
            $kind = $carousel['kind'] ?? 'products';
            $bottom = ['type' => 'carousel', 'settings' => [
                'kind' => $kind,
                'source' => $carousel['source'] ?? HomeCatalog::CURRENT_SOURCE,
                'entity' => FieldValues::id($carousel['entity'] ?? null),
                'count' => (string) ($carousel['count'] ?? \App\Storefront\Home\CarouselGeometry::DEFAULT_COUNT),
                'sort' => $carousel['sort'] ?? 'newest',
                'arrows' => ($carousel['arrows'] ?? true) !== false,
                'label' => (string) ($carousel['label'] ?? ''),
                'blocks' => array_values(array_filter(array_map(static fn (mixed $row): mixed => is_array($row) ? FieldValues::id($row['block'] ?? null) : null, is_array($carousel['block_items'] ?? null) ? array_values($carousel['block_items']) : []), static fn (mixed $id): bool => $id !== null)),
            ]];
        }

        return ['cloud' => ($form['cloud'] ?? false) === true, 'title' => ($form['title'] ?? true) !== false, 'top' => FieldValues::id($form['top'] ?? null), 'bottom' => $bottom, 'bottom_heading' => is_string($form['bottom_heading'] ?? '') ? ($form['bottom_heading'] ?? '') : ''];
    }

    // ---- subjects --------------------------------------------------------------------------------------------------------------------

    /** The pages of a kind by name (a category as «Parent › Child»), closed to those that exist. @return array<int, string> */
    public static function names(string $kind): array
    {
        return HomeZone::tileEntities(self::SOURCE_OF[$kind] ?? '');
    }

    /** The search of the «own settings» field: by name, at most 20. @return array<int, string> */
    private function search(string $kind, string $search): array
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');

        return array_slice(array_filter(self::names($kind), static fn (string $name): bool => $needle === '' || str_contains(mb_strtolower($name, 'UTF-8'), $needle)), 0, 20, true);
    }

    // ---- validation and storage ----------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return ContentSet::validate($tree['values'] ?? [], ContentStore::existing());
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => $path];
    }

    public function fieldPath(array $at): ?string
    {
        // the messages are shown under the kind selector (a path such as `brands.own.12.top` is a page's own template): one place, always on screen
        return 'kind';
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        ContentStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        ContentStore::publish($stored);
    }

    public function discard(): void
    {
        ContentStore::discard();
    }

    /** «Начални настройки»: no template at all, every page as it always was. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => ContentSet::blank(), 'kind' => 'categories'];
    }
}
