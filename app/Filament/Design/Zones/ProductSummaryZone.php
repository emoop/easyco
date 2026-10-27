<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\ProductSummaryAdapter;
use App\Storefront\ProductSummary\ProductSummaryCatalog;
use App\Storefront\ProductSummary\ProductSummaryDrafts;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\ProductSummary\ProductSummaryValidator;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * The «Дясна колона» zone of the Design screen (SC-1): the right column of the product page as ONE ordered list of components (brand, name, SKU, short description, price, stock, purchase, HTML
 * blocks). `title` and `purchase` are locked (exactly one each: the validator refuses a layout without them or with a second one, so removing or duplicating one is refused here with its message).
 * The list is the generic tree with one row of one cell (ProductSummaryAdapter); the screen hides the row and the cell (HasOneCell). Every rule comes from ProductSummaryCatalog and
 * ProductSummaryValidator, which stay the single authority; the draft / publish life is ProductSummaryDrafts. The default layout is also the «Начални настройки».
 */
final class ProductSummaryZone implements DesignZone, HasOneCell
{
    public function key(): string
    {
        return 'summary';
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
        return ProductSummaryAdapter::CELL;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-summary';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.summary');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return ProductSummaryDrafts::hasUnpublishedChanges();
    }

    /** The preview is a real product page: with no visible product there is nothing to show, and the panel says so. */
    public function previewNote(): ?string
    {
        return $this->previewSlug() === null ? (string) __('design.summary.no_product') : null;
    }

    /** The slug of the first visible product of the shop, or null. */
    public function previewSlug(): ?string
    {
        return app(CatalogReader::class)->listing(ListingQuery::fromRequestArray([]))->items[0]->slug ?? null;
    }

    public function load(): array
    {
        return ProductSummaryAdapter::toTree(ProductSummaryDrafts::startingLayout());
    }

    /** The default layout as a tree: «Начални настройки» puts it into the working copy. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ProductSummaryAdapter::toTree(ProductSummaryLayout::default());
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl('product_summary');
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
        return (string) __('design.zones.summary');
    }

    public function cellName(array $cell, string $layout): string
    {
        return (string) __('design.zones.summary');
    }

    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('product_summary')[$type] ?? null;

        return $descriptor === null ? $type : (string) __($descriptor['label']);
    }

    public function summary(array $node): string
    {
        return ProductSummaryCatalog::isLocked($node['type']) ? (string) __('design.summary.locked') : '';
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    /** Only the components that can still be added are offered (a component at its most is not). */
    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];
        $count = array_count_values(array_column($items, 'type'));
        $out = [];

        foreach (ProductSummaryCatalog::all() as $type => $descriptor) {
            if (($count[$type] ?? 0) >= $descriptor['max']) {
                continue;
            }

            $reason = $cellId === null ? (string) __('design.palette.no_cell') : (count($items) >= ProductSummaryCatalog::MAX_COMPONENTS ? (string) __('design.summary.errors.too_many', ['max' => ProductSummaryCatalog::MAX_COMPONENTS]) : null);
            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $reason, 'hint' => null];
        }

        return $out;
    }

    /** Why a component is not in the palette: it is at its most (or does not exist). */
    public function unavailableReason(string $type): ?string
    {
        $descriptor = ProductSummaryCatalog::all()[$type] ?? null;

        if ($descriptor === null) {
            return null;
        }

        return (string) ($descriptor['max'] === 1 ? __('design.summary.errors.only_one') : __('design.summary.errors.too_many_html', ['max' => $descriptor['max']]));
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        return Tree::node($id, $type, ProductSummaryCatalog::defaultsFor($type));
    }

    /** The first rule about the LIST as a whole that the tree breaks (a locked component gone or doubled, too many components or HTML blocks), or null. */
    public function structureProblem(array $tree): ?string
    {
        [, $errors] = ProductSummaryValidator::validate(ProductSummaryAdapter::fromTree($tree));

        foreach ($errors as $path => $message) {
            if (preg_match('/^components(\.\d+)?$/', $path) === 1) {
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

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);
            $fields = $node === null ? [] : $this->fieldsOf($node['type']);

            if ($fields === []) {
                return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([Text::make(__('design.summary.no_props'))])];
            }

            return [Section::make($this->typeLabel($node['type']))->schema([
                \Filament\Schemas\Components\Group::make(array_map(static fn (string $name, array $field): Component => FieldForms::make($name, $field), array_keys($fields), array_values($fields)))->statePath('settings'),
            ])];
        }

        return [Text::make(__('design.summary.pick'))];
    }

    /** @return array<string, array<string, mixed>> the fields of a component type, each with its own label */
    private function fieldsOf(string $type): array
    {
        $fields = [];

        foreach (ComponentRegistry::for('product_summary')[$type]['fields'] ?? [] as $name => $field) {
            $fields[$name] = $field + ['label' => 'design.summary.settings.'.$name];
        }

        return $fields;
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        if ($selected === null || $selected['kind'] !== 'node') {
            return [];
        }

        $node = TreeEditor::node($tree['main'] ?? [], $selected['id']);

        return $node === null ? [] : ['settings' => $node['settings']];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $current = $tree['main'] ?? [];

        if ($selected === null || $selected['kind'] !== 'node' || $current === [] || ($node = TreeEditor::node($current, $selected['id'])) === null) {
            return $tree;
        }

        $in = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        $tree['main'] = TreeEditor::setSettings($current, $selected['id'], FieldValues::settings($this->fieldsOf($node['type']), $in) + $node['settings']);

        return $tree;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return ProductSummaryValidator::validate(ProductSummaryAdapter::fromTree($tree));
    }

    public function locate(array $tree, string $path): ?array
    {
        return ProductSummaryAdapter::locate($tree, $path);
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
        return [];
    }

    public function save(array $stored): void
    {
        ProductSummaryDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        ProductSummaryDrafts::publish($stored);
    }

    public function discard(): void
    {
        ProductSummaryDrafts::discard();
    }
}
