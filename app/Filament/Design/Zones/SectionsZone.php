<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\InfoHint;
use App\Filament\Resources\ProductResource;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\ReadModels\CatalogSubjectKind;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use App\Content\HtmlBlocks\HtmlBlockModel;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * P4b: «Секции на продукта» — the DEFINITIONS of the sections of the product page (Description, Individual size, Composition, Delivery and returns …): ONE ordered list for every product, at most 8. A section has a title,
 * a source (a general text for all products / the text of the product / the product's text, else the general one) and, for a source that uses it, the general text (the same rich-text editor as the product description).
 * The sections are shown as the «Акордеон» component of the right column and as the «Табове» slot of «Под галерията»; the product form's «Информация» tab holds the texts of the products. A FORM zone with a draft
 * (the one button model of the screen). The panel is narrow: ONE field per row, info icons, texts in bg and en (admin-ui-standard.md).
 *
 * Each section has a stable key (a uuid, made when the section is added): moving or renaming a section keeps the products' texts. The panel's own values (`props`) are `{sections: list<{key, title, source, text}>}`;
 * the working copy (`tree['values']`) is the validated shape of SectionSet. The icon of a section is not offered: the storefront has no icon catalogue to choose from.
 *
 * @phpstan-import-type Set from SectionSet
 */
final class SectionsZone implements DesignZone
{
    public const KEY = 'sections';

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
        return 'filament.pages.design.partials.schema-sections';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.sections');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return SectionStore::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return ['values' => SectionStore::startingValues()];
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
            Repeater::make('sections')->label((string) __('design.sections.list'))->afterLabel(InfoHint::make((string) __('design.sections.hints.list'), 'sections-list'))
                ->addActionLabel((string) __('design.sections.add'))->maxItems(SectionSet::MAX)->defaultItems(0)->reorderableWithButtons()->collapsible()->live()
                ->deleteAction(static fn ($action) => $action->label((string) __('design.sections.remove'))->tooltip((string) __('design.sections.remove')))
                ->itemLabel(static fn (array $state): string => (string) ($state['title'] ?? ''))
                ->schema($this->sectionFields()),
        ];
    }

    /**
     * ONE section's fields, one per row: its stable key (hidden), the title, the source, and the general text (shown only for a source that uses it).
     *
     * @return list<Component>
     */
    private function sectionFields(): array
    {
        $hint = static fn (string $name): \Illuminate\Support\HtmlString => InfoHint::make((string) __('design.sections.hints.'.$name), 'sections-'.$name);

        return [
            Hidden::make('key')->default(static fn (): string => (string) Str::uuid()),
            TextInput::make('title')->label((string) __('design.sections.title'))->afterLabel($hint('title'))->maxLength(SectionSet::TITLE_MAX)->live(onBlur: true)->default(''),
            Select::make('source')->label((string) __('design.sections.source'))->afterLabel($hint('source'))->native(false)->selectablePlaceholder(false)->live()->default('product_or_global')
                ->options(array_combine(SectionSet::SOURCES, array_map(static fn (string $s): string => (string) __('design.sections.sources.'.$s), SectionSet::SOURCES))),
            RichEditor::make('text')->label((string) __('design.sections.text'))->afterLabel($hint('text'))->toolbarButtons(ProductResource::RICH_TEXT_TOOLBAR)->live(onBlur: true)->columnSpanFull()
                ->visible(static fn (Get $get): bool => SectionSet::usesGlobalText((string) $get('source'))),
            // P4c: «Мета информация» — the two lines
            Toggle::make('show_seasons')->label((string) __('design.sections.show_seasons'))->afterLabel($hint('show_seasons'))->default(true)
                ->visible(static fn (Get $get): bool => $get('source') === 'meta'),
            Toggle::make('show_tags')->label((string) __('design.sections.show_tags'))->afterLabel($hint('show_tags'))->default(true)
                ->visible(static fn (Get $get): bool => $get('source') === 'meta'),
            // P6e-3: the collections line — offered only while the collections are ON; while OFF a Hidden field carries the stored choice through the form
            Toggle::make('show_collections')->label((string) __('design.sections.show_collections'))->afterLabel($hint('show_collections'))->default(true)
                ->visible(static fn (Get $get): bool => $get('source') === 'meta' && CatalogSubjectKind::collectionsEnabled()),
            Hidden::make('show_collections')->visible(static fn (): bool => ! CatalogSubjectKind::collectionsEnabled()),
            // P4c: «HTML блокове» — an ordered list of up to 10 existing blocks (the picker of the HTML-block slots)
            Repeater::make('block_items')->label((string) __('design.sections.blocks'))->afterLabel($hint('blocks'))
                ->maxItems(SectionSet::BLOCKS_MAX)->defaultItems(0)->reorderableWithButtons()->addActionLabel((string) __('design.sections.block_add'))->columnSpanFull()
                ->schema([Select::make('block')->label((string) __('design.sections.block'))->native(false)->searchable()->options(static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all())->required()])
                ->visible(static fn (Get $get): bool => $get('source') === 'blocks'),
        ];
    }

    // ---- the working copy <-> the panel -----------------------------------------------------------------------------------------------

    /** @param  Set  $set */
    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        $set = is_array($tree['values'] ?? null) ? $tree['values'] : SectionSet::blank();
        $sections = [];

        foreach ($set['sections'] ?? [] as $section) {
            $sections[(string) Str::uuid()] = [
                'key' => $section['key'], 'title' => $section['title'], 'source' => $section['source'], 'text' => $section['text'],
                'show_seasons' => $section['meta']['seasons'] ?? true,
                'show_tags' => $section['meta']['tags'] ?? true,
                'show_collections' => $section['meta']['collections'] ?? true,
                'block_items' => array_map(static fn (int $id): array => ['block' => $id], $section['blocks'] ?? []),
            ];
        }

        return ['sections' => $sections];
    }

    /** The panel's values put back as the working copy, never validated here. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $sections = [];

        foreach (is_array($raw['sections'] ?? null) ? array_values($raw['sections']) : [] as $form) {
            if (! is_array($form)) {
                continue;
            }

            $section = [
                'key' => $form['key'] ?? null,
                'title' => is_string($form['title'] ?? '') ? (string) ($form['title'] ?? '') : '',
                'source' => $form['source'] ?? 'product_or_global',
                'text' => self::html($form['text'] ?? ''),
            ];

            // P4c: the settings of the two new sources go only with their own source
            if ($section['source'] === 'meta') {
                $section['meta'] = ['seasons' => ($form['show_seasons'] ?? true) !== false, 'tags' => ($form['show_tags'] ?? true) !== false];

                // P6e-3: written only when the form carries it (the toggle while ON, the Hidden copy while OFF), so a set that never knew the line is saved as it was
                array_key_exists('show_collections', $form) && $section['meta']['collections'] = $form['show_collections'] !== false;
            }

            if ($section['source'] === 'blocks') {
                $section['blocks'] = array_values(array_filter(array_map(static fn (mixed $row): mixed => is_array($row) ? FieldValues::id($row['block'] ?? null) : null, is_array($form['block_items'] ?? null) ? array_values($form['block_items']) : []), static fn (mixed $id): bool => $id !== null));
            }

            $sections[] = $section;
        }

        $tree['values'] = ['sections' => $sections];

        return $tree;
    }

    /**
     * The editor's value as HTML. The panel's state is read as it is (not through the editor's own state cast), so a text the merchant typed arrives as the editor's document (an array): it is rendered to
     * HTML here, the form the product description is stored in. A string is the HTML already.
     */
    private static function html(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_array($value) ? RichContentRenderer::make($value)->toHtml() : '';
    }

    // ---- validation and storage ----------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return SectionSet::validate($tree['values'] ?? []);
    }

    public function locate(array $tree, string $path): ?array
    {
        return ['kind' => 'form', 'id' => self::KEY, 'field' => $path];
    }

    public function fieldPath(array $at): ?string
    {
        // the messages are shown under the list of sections: one place, always on screen
        return 'sections';
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        SectionStore::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        SectionStore::publish($stored);
    }

    public function discard(): void
    {
        SectionStore::discard();
    }

    /** «Начални настройки»: no section, every product page as it always was. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => SectionSet::blank()];
    }
}
