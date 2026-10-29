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
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\ProductSummary\PurchaseStyle;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
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
            // PB-1: the add-to-cart button's settings have their own compact form (two fields per row) with a preview of the button
            if ($node !== null && $node['type'] === 'purchase') {
                return [$this->purchaseSection()];
            }

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

    /**
     * PB-1: the properties of the `purchase` component: texts, colours, border and corners, two per row, and a preview of the button in its three states (an inline style in THIS admin preview only, built from
     * validated values by PurchaseStyle::previewStyle; the storefront never prints one).
     */
    private function purchaseSection(): Section
    {
        $clear = static fn (string $name): Action => Action::make('clear_'.$name)->label(__('design.summary.purchase.none'))->action(static fn (Set $set) => $set($name, null));
        $text = static fn (string $name): TextInput => TextInput::make($name)->label(__('design.summary.settings.'.$name))->maxLength(30)->live(onBlur: true)->extraInputAttributes(['class' => 'text-sm']);
        $color = static fn (string $name): ColorPicker => ColorPicker::make($name)->label(__('design.summary.settings.'.$name))->hex()->live(onBlur: true)->hintAction($clear($name));

        return Section::make($this->typeLabel('purchase'))->compact()->schema([
            Text::make(__('design.summary.purchase.hint'))->color('gray')->size('sm'),
            Grid::make(2)->schema([
                $text('label'), $text('label_choose'), $text('label_sold_out'),
                $color('bg'), $color('color'),
                // NOT `numeric()`: Filament would cast what was typed (the validator must see the text as typed)
                TextInput::make('border_w')->label(__('design.summary.settings.border_w'))->inputMode('numeric')->maxLength(1)->live(onBlur: true),
                $color('border_color'),
                Toggle::make('rounded')->label(__('design.summary.settings.rounded'))->live(),
            ])->statePath('settings'),
            Text::make(static function (Get $get): HtmlString {
                $raw = $get('settings') ?? [];
                $settings = [
                    'bg' => $raw['bg'] ?? null,
                    'color' => $raw['color'] ?? null,
                    'border_w' => is_string($raw['border_w'] ?? null) && preg_match('/\A[0-4]\z/', $raw['border_w']) === 1 ? (int) $raw['border_w'] : (is_int($raw['border_w'] ?? null) ? $raw['border_w'] : 0),
                    'border_color' => $raw['border_color'] ?? null,
                    'rounded' => ! array_key_exists('rounded', $raw) || (bool) $raw['rounded'],
                ];
                $sample = e(is_string($raw['label'] ?? null) && trim($raw['label']) !== '' ? mb_substr($raw['label'], 0, 30) : __('design.summary.purchase.sample'));
                // the layout of this ADMIN preview is inline too (the admin has no stylesheet for it); only the sample's own colours come from validated values
                $out = '<div class="ds-buy-preview" style="display:flex;flex-direction:column;gap:0.5rem"><strong style="display:block">'.e(__('design.summary.purchase.preview')).'</strong>';

                foreach (['normal', 'hover', 'disabled'] as $state) {
                    $out .= '<span class="ds-buy-state" data-state="'.$state.'" style="display:flex;align-items:center;gap:0.75rem"><small style="width:6rem">'.e(__('design.summary.purchase.state_'.$state)).'</small><span class="ds-buy-sample" style="'.e(PurchaseStyle::previewStyle($settings, $state)).';display:inline-block;padding:0.5rem 1rem;font-weight:600">'.$sample.'</span></span>';
                }

                return new HtmlString($out.'</div>');
            }),
        ]);
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
        $settings = FieldValues::settings($this->fieldsOf($node['type']), $in) + $node['settings'];

        // PB-1: an empty border width box is «no border» (the validator still refuses anything that is not a whole number 0..4)
        $node['type'] === 'purchase' && ($settings['border_w'] ?? null) === '' && $settings['border_w'] = 0;

        $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

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

    /** PB-1: a non-blocking warning when the button's text and background (its own, or the shop's for the one it does not set) are below the minimum contrast. */
    public function warnings(array $stored): array
    {
        foreach ($stored['components'] ?? [] as $component) {
            if (($component['type'] ?? null) !== 'purchase') {
                continue;
            }

            $s = $component['settings'] ?? [];

            if (($s['bg'] ?? null) === null && ($s['color'] ?? null) === null) {
                return [];
            }

            $settings = app(SiteSettingsRepository::class);
            $shop = static function (string $short) use ($settings): string {
                [$key, , $default] = AppearanceSettings::COLORS[$short];
                $value = $settings->get($key);

                return is_string($value) && AppearanceSettings::isHex($value) ? strtolower($value) : $default;
            };
            $ratio = AppearanceSettings::contrastRatio($s['color'] ?? $shop('on_primary'), $s['bg'] ?? $shop('primary'));

            return $ratio < AppearanceSettings::MIN_CONTRAST ? [(string) __('design.summary.purchase.low_contrast', ['ratio' => number_format($ratio, 2), 'min' => AppearanceSettings::MIN_CONTRAST])] : [];
        }

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
