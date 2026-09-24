<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ProductActivityLog;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Services\ActivityLogger;
use App\Services\DuplicateProduct;
use App\Services\PriceDisplayFormatter;
use App\Services\ProductPricingAndStock;
use App\Services\ProductPriceRangeProvider;
use App\Services\ProductTimelinePromoter;
use DateTimeImmutable;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use EasyCo\Pricing\PriceRange;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * SIMPLE-product management — admin-panel-design.md §10 Part 5. VARIABLE
 * product creation (the multi-step axis/combination wizard) and
 * pricing/stock are explicitly separate, later phases — not built here.
 *
 * Gated by PRODUCT_VIEW/PRODUCT_MANAGE (routes/api.php's own existing
 * decision, reused here, NOT TAXONOMY_MANAGE) — this is the entire
 * reason Permission::PRODUCT_MANAGE and the Product Entry role exist
 * (§4.1).
 *
 * No delete action anywhere on this Resource — Product has no delete()
 * domain method by design; archive() is the real "remove from active
 * use" operation, mirroring Staff::deactivate() rather than deletion.
 */
class ProductResource extends Resource
{
    use AuthorizesViaStaffPermission;

    /**
     * The Variations tab's stable id on EditVariableProduct — the one
     * place this string is written, because TWO different classes depend
     * on them agreeing: EditVariableProduct's own Tab::make(...)->id()
     * (what Tabs::getActiveTab() matches the query string against) and
     * CreateVariableProduct::getRedirectUrl() (which lands the merchant
     * there straight after creation). A typo or a rename in only one of
     * them would silently open the wrong tab, so neither re-writes it.
     */
    public const VARIATIONS_TAB_ID = 'variations';

    protected static ?string $model = ProductModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    public static function getModelLabel(): string
    {
        return __('products.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('products.plural_label');
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    protected static function viewPermission(): ?Permission
    {
        return static::viewAnyPermission();
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::PRODUCT_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::PRODUCT_MANAGE;
    }

    /**
     * A real, public wrapper around AuthorizesViaStaffPermission's own
     * private staffCanForAction() — needed because Create/EditProduct
     * (separate classes, neither `use`s that trait itself) must
     * server-side-recheck PRICE_MANAGE/COST_MANAGE before writing
     * price/cost, independent of the form's own ->disabled() state —
     * see priceStockTabComponents()'s own docblock for why ->disabled()
     * alone is not the real enforcement. staffCanForAction() itself
     * stays private on the trait (unrelated call sites should keep
     * using the five named canX() methods); this is the one, deliberate,
     * arbitrary-permission escape hatch for this Resource's own writer
     * pages.
     */
    public static function staffHasPermission(Permission $permission): bool
    {
        return static::staffCanForAction($permission);
    }

    /**
     * Two columns, per admin-panel-design.md's own "the user scrolls a
     * lot" complaint about the old single-column, three-tab layout:
     * Media/Categories/Tags/Season sat in an otherwise-empty Media tab
     * or buried at the bottom of General, both well below the fold.
     * Main column (2/3): the same Tabs as before, minus the Media tab
     * (General/Attributes only — General itself also lost
     * categories/tags/season_id, now in the sidebar). Sidebar (1/3):
     * Media/Categories/Tags/Season as their own Sections, always
     * visible without a tab click or a scroll — the real fix for the
     * "empty space, lots of scrolling" complaint.
     *
     * ->columns(1) ON THE ROOT $schema ITSELF — real, confirmed cause of
     * a second bug found while checking this in a real browser: Filament
     * EditRecord/CreateRecord's own EditRecord::defaultForm() calls
     * $schema->columns(2) automatically whenever hasCustomColumns() is
     * still false (vendor/filament/filament/src/Resources/Pages/
     * EditRecord.php), i.e. whenever nothing has called ->columns() on
     * the ROOT schema. That silently wrapped this whole Grid(3) —
     * despite Grid(3) itself being correct — inside ONE half of an
     * outer, framework-imposed 2-column grid, leaving the other half
     * empty (confirmed in real rendered HTML: an extra "fi-grid
     * lg:fi-grid-cols" ancestor with --cols-lg: repeat(2, minmax(0,
     * 1fr)) sitting above Grid(3)'s own). Declaring ->columns(1) here
     * makes hasCustomColumns() true and suppresses that default, so
     * Grid(3) is the outermost column split and gets the full page
     * width.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)
                ->schema([
                    Group::make()
                        ->columnSpan(2)
                        ->schema([
                            Tabs::make('Product')
                                ->tabs([
                                    Tab::make(__('products.tabs.general'))
                                        ->schema(static::generalTabComponents()),
                                    Tab::make(__('products.tabs.attributes'))
                                        ->schema(static::attributesTabComponents()),
                                    Tab::make(__('products.tabs.price_stock'))
                                        ->schema(static::priceStockTabComponents()),
                                ]),
                        ]),
                    Group::make()
                        ->columnSpan(1)
                        ->schema(static::sidebarComponents()),
                ]),
        ]);
    }

    /**
     * Catalog settings' four field-visibility toggles (admin-panel-design.md
     * §13.4's own follow-up) — whether Season/Brand/Tags/Product group are
     * offered at all when creating/editing a product, independent of
     * product_group_id's existing catalog.product_group_required
     * (still "shown, but is it mandatory" — this new set is "shown at
     * all"). Read fresh on each render, same reasoning as
     * product_group_id's own ->required() closure just below — never
     * cached at class-load time.
     *
     * PUBLIC (not protected): CreateVariableProduct/EditVariableProduct
     * both author their own brand_id/product_group_id fields fresh
     * (documented precedent — see EditVariableProduct::generalTabComponents()'s
     * own docblock for why they don't call ProductResource's methods
     * directly), so those two classes call these four helpers directly
     * to stay in sync with SIMPLE's own gating, exactly like
     * staffHasPermission() is already reused the same way.
     *
     * Default TRUE (missing key, or any value other than the literal
     * '0', counts as enabled) — an upgraded installation that never
     * visited CatalogSettings keeps today's exact behavior (every field
     * shown) rather than silently hiding anything.
     */
    public static function seasonFieldEnabled(): bool
    {
        return app(SiteSettingsRepository::class)->get('catalog.season_field_enabled') !== '0';
    }

    public static function brandFieldEnabled(): bool
    {
        return app(SiteSettingsRepository::class)->get('catalog.brand_field_enabled') !== '0';
    }

    public static function tagsFieldEnabled(): bool
    {
        return app(SiteSettingsRepository::class)->get('catalog.tags_field_enabled') !== '0';
    }

    public static function productGroupFieldEnabled(): bool
    {
        return app(SiteSettingsRepository::class)->get('catalog.product_group_field_enabled') !== '0';
    }

    /** @return array<int, Component> */
    protected static function generalTabComponents(): array
    {
        return [
            TextInput::make('name')
                ->label(__('products.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('products.fields.slug'))
                ->helperText(__('products.fields.slug_help')),
            TextInput::make('base_sku')
                ->label(__('products.fields.base_sku'))
                ->helperText(fn (string $operation): string => $operation === 'edit'
                    ? __('products.base_sku_change_warning')
                    : __('products.fields.base_sku_help')),
            TextInput::make('barcode')
                ->label(__('products.fields.barcode')),
            // Native RichEditor (Filament v5.8.1), no third-party
            // package — confirmed sufficient by the domain owner over
            // a plain Textarea (headings for size, bold, no px-level
            // font-size control needed). h1 deliberately excluded: a
            // product description shouldn't contain a page-level
            // heading; h2/h3 already give real size differentiation.
            // Output is a plain HTML string, not JSON — confirmed
            // against RichEditorStateCast::get(): it returns
            // getHtml() unless ->json() is called or the field is
            // bound to a Model implementing HasRichContent (neither
            // applies here), and description is already a nullable
            // TEXT column (catalog-domain-design.md §3.14), wide
            // enough for real HTML content.
            RichEditor::make('description')
                ->label(__('products.fields.description'))
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['h2', 'h3'],
                    ['bulletList', 'orderedList'],
                    ['textColor'],
                    ['undo', 'redo'],
                ]),
            Select::make('status')
                ->label(__('products.fields.status'))
                ->options([
                    ProductStatus::DRAFT->value => __('products.status_options.draft'),
                    ProductStatus::ACTIVE->value => __('products.status_options.active'),
                    ProductStatus::ARCHIVED->value => __('products.status_options.archived'),
                ])
                ->default(ProductStatus::DRAFT->value)
                // Always visible, not a reactive/conditional helperText
                // tied to ->live() — a real photo-deletion warning
                // (App\Services\ArchiveProductMediaCleaner, wired into
                // EditProduct's own status-change block) is not worth
                // the extra debounced round-trip a live-reactive field
                // would add just to hide this text while 'archived'
                // isn't selected; the domain owner's own requirement is
                // that the merchant SEES the warning before deciding,
                // not that it stays hidden otherwise.
                ->helperText(__('products.fields.status_archive_warning'))
                ->required(),
            Select::make('catalog_visibility')
                ->label(__('products.fields.catalog_visibility'))
                ->options([
                    CatalogVisibility::VISIBLE->value => __('products.visibility_options.visible'),
                    CatalogVisibility::HIDDEN->value => __('products.visibility_options.hidden'),
                ])
                ->default(CatalogVisibility::HIDDEN->value)
                ->required(),
            Toggle::make('is_purchasable')
                ->label(__('products.fields.is_purchasable'))
                ->default(true),
            Select::make('brand_id')
                ->label(__('products.fields.brand_id'))
                ->options(fn (): array => BrandModel::pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => static::brandFieldEnabled()),
            Select::make('product_group_id')
                ->label(__('products.fields.product_group_id'))
                ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => static::productGroupFieldEnabled())
                // Read fresh on each render, not cached at class-load
                // time — mirrors why getModelLabel() etc. are methods,
                // not static properties (admin-panel-design.md §13.4).
                ->required(fn (): bool => (bool) (app(SiteSettingsRepository::class)->get('catalog.product_group_required') ?? false)),
        ];
    }

    /**
     * The sidebar column — see form()'s own docblock. Order (Categories,
     * Tags, Season, Main Photo, Gallery Photos, Video) is this task's
     * own explicit requirement. Each taxonomy field keeps its own real
     * name (categories/tags/season_id) exactly as before; only where it
     * renders moved, not the underlying form data shape
     * CreateProduct/EditProduct already read.
     *
     * PUBLIC (not protected): widened for EditVariableProduct, which
     * reuses this exact PRODUCT-level media/taxonomy section as-is (no
     * VARIABLE-specific branching needed here) — a pure visibility
     * change, same body, zero behavior change for the existing SIMPLE
     * flow. Duplicating ~150 lines of already-tested FileUpload/guard
     * logic across two classes was judged the worse tradeoff.
     *
     * @return array<int, Component>
     */
    public static function sidebarComponents(): array
    {
        return [
            Section::make(__('products.fields.categories'))
                ->schema([
                    Select::make('categories')
                        ->hiddenLabel()
                        ->multiple()
                        // Tree order/indentation, not a flat pluck() —
                        // reuses CategoryResource's own
                        // hierarchicalOptions() rather than duplicating
                        // the tree walk here.
                        ->options(fn (): array => CategoryResource::hierarchicalOptions())
                        ->searchable(),
                ]),
            Section::make(__('products.fields.tags'))
                ->visible(fn (): bool => static::tagsFieldEnabled())
                ->schema([
                    Select::make('tags')
                        ->hiddenLabel()
                        ->multiple()
                        ->options(fn (): array => TagModel::pluck('name', 'id')->all())
                        ->searchable(),
                ]),
            Section::make(__('products.fields.season_id'))
                ->visible(fn (): bool => static::seasonFieldEnabled())
                ->schema([
                    Select::make('season_id')
                        ->hiddenLabel()
                        ->options(fn (): array => SeasonModel::pluck('name', 'id')->all())
                        ->searchable(),
                ]),
            Section::make(__('products.fields.main_photo'))
                ->schema(static::mainPhotoComponents()),
            Section::make(__('products.fields.gallery_photos'))
                ->schema(static::galleryPhotoComponents()),
            Section::make(__('products.fields.video'))
                ->schema(static::videoComponents()),
        ];
    }

    /**
     * The dynamic descriptive-attributes form (admin-panel-design.md
     * §7) — a Repeater-based picker: the merchant chooses WHICH
     * AttributeDefinitions apply to this product, one Select+value pair
     * per row, mirroring CreateVariableProduct's own Axes step
     * Select→dependent-value pattern exactly (attribute_definition_id
     * ->live()->afterStateUpdated() clearing all three stale value
     * fields, a value field's own ->visible()/->options() scoped via
     * Get() to the picked definition — same proven mechanism, not a new
     * one). Delegates to descriptiveAttributesPickerComponents() below,
     * with no exclusions — SIMPLE and a brand-new Create never have
     * declared variation axes to guard against (only a VARIABLE
     * product, mid-edit, can have any).
     *
     * PUBLIC (not protected): kept public — EditVariableProduct does
     * NOT call this method (it needs its own axis exclusions, which
     * this method's own empty-exclusions call can never provide; see
     * that page's own attributesTabComponents() instead), but
     * CreateProduct/EditProduct still reach the picker only through
     * this SIMPLE-appropriate entry point, and nothing prevents calling
     * it directly if ever needed elsewhere.
     *
     * @return array<int, Component>
     */
    public static function attributesTabComponents(): array
    {
        return static::descriptiveAttributesPickerComponents();
    }

    /**
     * The real, shared picker schema — one Repeater, 'descriptive_
     * attributes_picker'. $excludedDefinitionIds is how EditVariableProduct
     * keeps this product's own declared variation axis definitions out
     * of the pickable options: Product::setDescriptiveAttribute() throws
     * InvalidArgumentException if $definition is currently one of this
     * Product's declared axes (§4.3's own "descriptive OR axis, never
     * both" schema comment) — offering an axis definition as a pickable
     * descriptive-attribute option would make that real, live exception
     * newly reachable from this form.
     *
     * The type filter is inlined directly (type != MULTISELECT) rather
     * than querying descriptiveAttributeDefinitions() a second time and
     * pluck()ing its ids for a whereIn() — simpler, one query, and does
     * not depend on Eloquent's Arrayable-coercion behavior for
     * whereIn() against a Collection (confirmed that DOES work against
     * the installed Laravel version's real Builder::whereIn() source,
     * but there was no reason to rely on it when a single inline
     * condition does the same job more plainly).
     *
     * Cross-row "exclude an attribute already picked in another row"
     * is DELIBERATELY NOT implemented — same discretionary call the
     * Axes step already made for its own attribute_definition_id
     * options (CreateVariableProduct's own docblock explains why: no
     * confirmed, tested-in-this-codebase Get() syntax for reaching a
     * SIBLING repeater item's own state, only a sibling field within
     * the SAME item). The real backend backstop here is even stronger
     * than the Axes step's: catalog_product_attributes has a genuine
     * UNIQUE(product_id, attribute_definition_id) database constraint
     * (catalog-domain-design.md §7), so a merchant who does pick the
     * same definition twice hits a real, if unfriendly, DB error on
     * save — not a silently-accepted duplicate.
     *
     * @return array<int, Component>
     */
    public static function descriptiveAttributesPickerComponents(array $excludedDefinitionIds = []): array
    {
        return [
            Repeater::make('descriptive_attributes_picker')
                ->hiddenLabel()
                // Filament's own Repeater::setUp() defaults to
                // ->defaultItems(1) — a real, three-times-now-confirmed
                // regression in this codebase (CreateVariableProduct's
                // own Axes and Variations Repeaters hit the identical
                // bug): a phantom, empty row appears on every fresh
                // mount, tripping this row's own attribute_definition_id
                // ->required() on submit even when the merchant never
                // touched this Repeater at all. Confirmed here again by
                // running the full pre-existing suite against this
                // change — every SIMPLE-flow Create/Edit test failed on
                // exactly this until ->defaultItems(0) was added.
                ->defaultItems(0)
                ->addActionLabel(__('products.attributes_picker.add_attribute'))
                ->schema([
                    Select::make('attribute_definition_id')
                        ->label(__('products.attributes_picker.attribute_label'))
                        ->options(fn (): array => AttributeDefinitionModel::where('type', '!=', AttributeType::MULTISELECT->value)
                            ->whereNotIn('id', $excludedDefinitionIds)
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        // Same "stale value from the previous definition"
                        // correctness requirement CreateVariableProduct's
                        // own Axes step already established for its
                        // value_ids field — all three value fields
                        // cleared, not just whichever one happened to be
                        // visible before the switch.
                        ->afterStateUpdated(function (Set $set): void {
                            $set('text_value', null);
                            $set('boolean_value', false);
                            $set('select_value', null);
                        }),
                    TextInput::make('text_value')
                        ->label(__('products.attributes_picker.value_label'))
                        ->visible(fn (Get $get): bool => static::descriptiveAttributeValueFieldType($get('attribute_definition_id')) === 'text'),
                    Toggle::make('boolean_value')
                        ->label(__('products.attributes_picker.value_label'))
                        ->visible(fn (Get $get): bool => static::descriptiveAttributeValueFieldType($get('attribute_definition_id')) === 'boolean'),
                    Select::make('select_value')
                        ->label(__('products.attributes_picker.value_label'))
                        ->searchable()
                        ->options(fn (Get $get): array => AttributeValueModel::where('attribute_definition_id', $get('attribute_definition_id'))
                            ->pluck('value', 'id')
                            ->all())
                        ->visible(fn (Get $get): bool => static::descriptiveAttributeValueFieldType($get('attribute_definition_id')) === 'select'),
                ]),
        ];
    }

    /**
     * Shared by the three value fields above — avoids repeating the
     * AttributeDefinitionModel::find() lookup four times. Returns null
     * for "no definition picked yet" (a brand-new, still-empty row) —
     * every visible() closure above then correctly hides all three
     * value fields until a definition is actually chosen.
     */
    private static function descriptiveAttributeValueFieldType(mixed $definitionId): ?string
    {
        if (blank($definitionId)) {
            return null;
        }

        $definitionModel = AttributeDefinitionModel::find($definitionId);

        if ($definitionModel === null) {
            return null;
        }

        return match (AttributeType::from($definitionModel->type)) {
            AttributeType::TEXT, AttributeType::NUMBER => 'text',
            AttributeType::BOOLEAN => 'boolean',
            AttributeType::SELECT => 'select',
            AttributeType::MULTISELECT => null,
        };
    }

    /**
     * Builds 'descriptive_attributes_picker' Repeater rows from a real
     * Product's current descriptiveAttributes() — the read-side
     * counterpart of syncDescriptiveAttributesFromPickerRows() below,
     * shared by EditProduct/EditVariableProduct's own
     * mutateFormDataBeforeFill() (CreateProduct has no read side at
     * all — a brand-new product starts with zero descriptive
     * attributes, so an empty Repeater is already correct with no
     * seeding call needed).
     *
     * A definitionId with no matching AttributeDefinitionModel any more
     * is skipped silently, not thrown — a genuinely stale, defensive
     * case (the definition was deleted after this value was set), not
     * expected in normal use; surfacing a real Filament error over a
     * data-integrity edge case this far removed from the merchant's own
     * action would be worse than simply omitting that one row.
     *
     * @return array<int, array{attribute_definition_id: string, text_value: ?string, boolean_value: bool, select_value: ?string}>
     */
    public static function seedDescriptiveAttributesPickerRows(Product $product): array
    {
        $rows = [];

        foreach ($product->descriptiveAttributes() as $definitionId => $value) {
            $definitionModel = AttributeDefinitionModel::find($definitionId);

            if ($definitionModel === null) {
                continue;
            }

            $type = AttributeType::from($definitionModel->type);

            $textValue = null;
            $booleanValue = false;
            $selectValue = null;

            if ($type === AttributeType::TEXT || $type === AttributeType::NUMBER) {
                $textValue = (string) $value;
            } elseif ($type === AttributeType::BOOLEAN) {
                $booleanValue = $value === '1';
            } elseif ($type === AttributeType::SELECT && $value instanceof AttributeValue) {
                $selectValue = (string) $value->id();
            }

            $rows[] = [
                'attribute_definition_id' => (string) $definitionId,
                'text_value' => $textValue,
                'boolean_value' => $booleanValue,
                'select_value' => $selectValue,
            ];
        }

        return $rows;
    }

    /**
     * The write-side counterpart, shared by all three of Create/Edit/
     * EditVariableProduct's own write paths. For each submitted row:
     * resolves the real definition, extracts the type-appropriate raw
     * value, diffs via normalizeSubmittedDescriptiveValue()/
     * normalizeCurrentDescriptiveValue() exactly as the old per-
     * definition loops already did, applies via applyDescriptiveAttribute()
     * (unchanged) when different, logs via the same
     * ActivityLogger::logFieldChanged() pattern every other field in
     * these pages already uses (field name = $definitionModel->code,
     * unchanged).
     *
     * THEN — the real, new-with-this-redesign requirement: any
     * definitionId currently in $product->descriptiveAttributes() that
     * is NOT present in ANY submitted row at all is removed via
     * removeDescriptiveAttribute(). A deleted Repeater row means "this
     * attribute no longer applies to this product," not "leave it
     * untouched" — the old per-definition-field design had no
     * equivalent case (every definition always had its own field,
     * always submitted, never simply absent).
     *
     * A row with no resolvable attribute_definition_id (blank, or a
     * definition since deleted) is skipped silently, same defensive
     * posture as seedDescriptiveAttributesPickerRows() above — but
     * importantly is NOT counted as "submitted" for the removal pass
     * below, so it cannot accidentally protect some OTHER, unrelated
     * definitionId from removal.
     *
     * ON CreateProduct SPECIFICALLY — A REAL BUG FOUND WHILE TESTING
     * THIS METHOD, NOT ANTICIPATED IN ADVANCE: CreateProduct's own
     * "single save()" design (see that class's own docblock) requires
     * every descriptiveAttributes() mutation to happen entirely
     * in-memory BEFORE the one real save() call — $product->id() is
     * still null at that point (Product::assignId() only runs inside
     * EloquentProductRepository::save()). Calling logFieldChanged()
     * unconditionally there threw a real TypeError
     * (logFieldChanged()'s own $entityId parameter is a non-nullable
     * string) — caught by running the full pre-existing suite against
     * this change, not assumed safe. Every logFieldChanged() call below
     * is therefore skipped when $product->id() is null, which has a
     * second, correct consequence: it exactly PRESERVES CreateProduct's
     * own original behavior (that old per-definition loop never logged
     * descriptive attributes at all — only logCreated('product', ...)
     * at the very end), rather than introducing new log entries on
     * creation as an unplanned side effect of sharing one method across
     * all three pages. EditProduct/EditVariableProduct are unaffected —
     * their own $product is always already-persisted, with a real id,
     * by the time this method runs.
     */
    public static function syncDescriptiveAttributesFromPickerRows(Product $product, array $rows, ActivityLogger $logger): void
    {
        $submittedDefinitionIds = [];

        foreach ($rows as $row) {
            $definitionId = (string) ($row['attribute_definition_id'] ?? '');

            if ($definitionId === '') {
                continue;
            }

            $definitionModel = AttributeDefinitionModel::find($definitionId);

            if ($definitionModel === null) {
                continue;
            }

            $submittedDefinitionIds[] = $definitionId;

            $type = AttributeType::from($definitionModel->type);

            $rawValue = match (true) {
                $type === AttributeType::TEXT || $type === AttributeType::NUMBER => $row['text_value'] ?? null,
                $type === AttributeType::BOOLEAN => $row['boolean_value'] ?? false,
                $type === AttributeType::SELECT => $row['select_value'] ?? null,
                default => null,
            };

            $currentRaw = $product->descriptiveAttributes()[$definitionId] ?? null;
            $currentNormalized = static::normalizeCurrentDescriptiveValue($currentRaw);
            $submittedNormalized = static::normalizeSubmittedDescriptiveValue($definitionModel, $rawValue);

            if ($submittedNormalized === $currentNormalized) {
                continue;
            }

            if ($product->id() !== null) {
                $logger->logFieldChanged('product', $product->id(), $definitionModel->code, $currentNormalized, $submittedNormalized);
            }

            $definition = static::toDomainAttributeDefinition($definitionModel);

            if ($submittedNormalized === null) {
                $product->removeDescriptiveAttribute($definition);

                continue;
            }

            static::applyDescriptiveAttribute($product, $definitionModel, $rawValue);
        }

        foreach (array_keys($product->descriptiveAttributes()) as $definitionId) {
            $definitionId = (string) $definitionId;

            if (in_array($definitionId, $submittedDefinitionIds, true)) {
                continue;
            }

            $definitionModel = AttributeDefinitionModel::find($definitionId);

            if ($definitionModel === null) {
                continue;
            }

            if ($product->id() !== null) {
                $currentNormalized = static::normalizeCurrentDescriptiveValue($product->descriptiveAttributes()[$definitionId] ?? null);
                $logger->logFieldChanged('product', $product->id(), $definitionModel->code, $currentNormalized, null);
            }

            $product->removeDescriptiveAttribute(static::toDomainAttributeDefinition($definitionModel));
        }
    }

    /**
     * @return Collection<int, AttributeDefinitionModel>
     */
    public static function descriptiveAttributeDefinitions(): Collection
    {
        return AttributeDefinitionModel::where('type', '!=', AttributeType::MULTISELECT->value)->get();
    }

    /**
     * Phase 2 — Price + Stock for SIMPLE products (this task). Values
     * are read/written entirely through App\Services\
     * ProductPricingAndStock (CreateProduct/EditProduct/infolist all
     * share it) — this method only builds the form fields themselves.
     *
     * PERMISSION GATING, CONFIRMED PER-FIELD (the domain owner's own
     * explicit split): Regular/Sale Price are ordinary catalog info any
     * PRODUCT_VIEW holder should SEE (visible, just not editable
     * without PRICE_MANAGE) — ->disabled() only, never ->visible().
     * Cost is hidden ENTIRELY without COST_VIEW (->visible()), editable
     * only with COST_MANAGE (->disabled()) — two separate gates on the
     * same field, not one. Stock has no dedicated inventory permission
     * in Permission's enum (confirmed against its full case list) —
     * PRODUCT_MANAGE is the closest real fit, already this whole page's
     * own base edit permission.
     *
     * static::staffCanForAction() — AuthorizesViaStaffPermission's own
     * private trait method — is still callable here: a trait's private
     * methods become genuinely private members OF the consuming class
     * once `use`d (confirmed against real PHP trait semantics, not
     * merely inherited-and-restricted), so ProductResource's own
     * methods (this one included) can call it directly, exactly as
     * that trait's own docblock already establishes for canAccess()
     * overrides elsewhere in this codebase.
     *
     * ->disabled() ALONE IS NOT THE REAL ENFORCEMENT — a REAL, CONFIRMED
     * GAP found while building this: Filament's own isDehydrated()
     * (Components\Concerns\HasState, installed v5.8.1 source) checks
     * hidden-state, never isDisabled() — a merely-disabled field's
     * value DOES still dehydrate into getState() on submit, unlike a
     * genuinely hidden one (already-confirmed precedent elsewhere in
     * this codebase, which does NOT extend to "disabled"). EditProduct's
     * own updateProduct() therefore re-checks PRICE_MANAGE/COST_MANAGE
     * again, server-side, before ever writing — this form-level
     * ->disabled() is a real UX aid (a staff member literally cannot
     * type into the field), not the security boundary.
     *
     * @return array<int, Component>
     */
    protected static function priceStockTabComponents(): array
    {
        return [
            TextInput::make('regular_price')
                ->label(__('products.fields.regular_price'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->disabled(fn (): bool => ! static::staffCanForAction(Permission::PRICE_MANAGE)),
            TextInput::make('sale_price')
                ->label(__('products.fields.sale_price'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->disabled(fn (): bool => ! static::staffCanForAction(Permission::PRICE_MANAGE)),
            TextInput::make('cost')
                ->label(__('products.fields.cost'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->visible(fn (): bool => static::staffCanForAction(Permission::COST_VIEW))
                ->disabled(fn (): bool => ! static::staffCanForAction(Permission::COST_MANAGE)),
            TextInput::make('stock_quantity')
                ->label(__('products.fields.stock_quantity'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->disabled(fn (): bool => ! static::staffCanForAction(Permission::PRODUCT_MANAGE)),
        ];
    }

    /**
     * The single featured/representative photo — a real, separate
     * FileUpload field for this task's own WooCommerce-style split, but
     * NOT a separate underlying concept: ProductMedia's own class
     * docblock records a deliberate "NO is_primary field — the item at
     * sortOrder = 0 is implicitly the primary photo" decision. This
     * field is edited independently for a cleaner admin UI, but is
     * merged with galleryPhotoComponents()'s own array into ONE ordered
     * MediaType::IMAGE collection before ever reaching
     * CreateProduct::attachMedia()/EditProduct::syncMedia() — this
     * field's value always becomes sort_order 0, never a second,
     * competing "is primary" concept.
     *
     * @return array<int, Component>
     */
    protected static function mainPhotoComponents(): array
    {
        return [
            FileUpload::make('main_photo')
                ->hiddenLabel()
                ->image()
                // extraAttributes() class hook — admin-product-media.css
                // targets `.ec-product-main-photo`/`.ec-product-video`
                // to add horizontal padding around this single large
                // preview tile, which Filament's own FileUpload panel
                // otherwise renders edge-to-edge.
                ->extraAttributes(['class' => 'ec-product-main-photo'])
                ->placeholder(static::mediaUploadPlaceholder('services.media.max_image_size_kb', 10240))
                // Real enforcement, not just informational placeholder
                // text — unlike ->maxFiles() on gallery_photos below,
                // ->maxSize() has no MediaLimitExceededException-style
                // domain message it could shadow; Filament's own
                // "must not be greater than X KB" error is the real,
                // only enforcement here, same config key as the
                // placeholder text above so the two can never disagree.
                ->maxSize((int) config('services.media.max_image_size_kb', 10240))
                ->disk(config('services.media.default_disk', 'public'))
                // Mirrors BrandResource's own createLogoMediaAsset()
                // sequence exactly — routes every uploaded file through
                // the real MediaStorageAdapter::store() call, not
                // Filament's own storeAs()/directory() generation.
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    return app(MediaStorageAdapter::class)
                        ->store($file->get(), $file->getClientOriginalName())
                        ->path;
                }),
        ];
    }

    /**
     * The rest of the product's photos — small thumbnails, 3 per row
     * (Filament's own 'grid' panelLayout already renders 3 columns at
     * the 'lg' breakpoint, confirmed against its shipped CSS; no custom
     * grid math needed). See mainPhotoComponents()'s own docblock for
     * why this is still just the SAME underlying MediaType::IMAGE
     * collection as the main photo, split at the UI layer only.
     *
     * @return array<int, Component>
     */
    protected static function galleryPhotoComponents(): array
    {
        return [
            FileUpload::make('gallery_photos')
                ->hiddenLabel()
                ->multiple()
                ->reorderable()
                ->image()
                ->panelLayout('grid')
                ->placeholder(static::mediaUploadPlaceholder('services.media.max_image_size_kb', 10240))
                ->maxSize((int) config('services.media.max_image_size_kb', 10240))
                // Deliberately NO ->maxFiles(): confirmed against a real
                // failing test that Filament's own maxFiles() is hard,
                // blocking Livewire field validation — it would reject
                // an over-limit submission with its own generic message
                // ("must not have more than N items") before
                // handleRecordCreation() ever runs, making
                // ProductMediaCountGuard's real, specific
                // MediaLimitExceededException message (what this task
                // explicitly requires the user to see) unreachable. The
                // guard inside CreateProduct::attachMedia()/
                // EditProduct::syncMedia() is the one and only real
                // enforcement point now, matching the API's own
                // ProductMediaController::store() behavior exactly.
                ->disk(config('services.media.default_disk', 'public'))
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    return app(MediaStorageAdapter::class)
                        ->store($file->get(), $file->getClientOriginalName())
                        ->path;
                }),
        ];
    }

    /**
     * media-domain-design.md §4/§8: VIDEO is a real, supported
     * MediaAsset type — stored exactly as uploaded, no processing
     * pipeline (§4, a deliberate v1 scope decision, not a gap). Shares
     * the SAME catalog_product_media pivot as photos (§2.1/§8 — the
     * pivot isn't photo-specific), only ever created as
     * MediaType::VIDEO — see EditProduct::syncMedia()/
     * CreateProduct::attachMedia(). Single video per product (this
     * task's own explicit scope, matching real usage — see this
     * class's docblock/media-domain-design.md §4's "video is used
     * sparingly" note) — video_autoplay below applies to this one
     * attachment, stored on ITS OWN ProductMedia pivot row (§2.1: the
     * per-attachment record, not MediaAsset itself — see that class's
     * own docblock).
     *
     * @return array<int, Component>
     */
    protected static function videoComponents(): array
    {
        return [
            FileUpload::make('video')
                ->hiddenLabel()
                ->acceptedFileTypes(['video/*'])
                ->extraAttributes(['class' => 'ec-product-video'])
                ->placeholder(static::mediaUploadPlaceholder('services.media.max_video_size_kb', 102400))
                ->maxSize((int) config('services.media.max_video_size_kb', 102400))
                ->disk(config('services.media.default_disk', 'public'))
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    return app(MediaStorageAdapter::class)
                        ->store($file->get(), $file->getClientOriginalName())
                        ->path;
                }),
            Toggle::make('video_autoplay')
                ->label(__('products.fields.video_autoplay'))
                ->default(false),
        ];
    }

    /**
     * "Drag & Drop your files or Browse (max N MB)" — Filament's own
     * FileUpload ->placeholder() maps directly to FilePond's own
     * labelIdle option (confirmed against the installed JS source,
     * vendor/filament/forms/resources/js/components/file-upload.js),
     * the text shown ABOVE the drag/drop button only while the field is
     * empty — exactly this task's own request, and FilePond's default
     * labelIdle already embeds the "Browse" action as this same
     * `filepond--label-action`-classed span, replicated here so
     * providing a custom placeholder doesn't silently lose that
     * click-to-browse behavior.
     *
     * public (not protected): EditVariableProduct's own per-variation
     * photo field (variation_photos, existingVariationsComponents())
     * reuses this SAME real config-driven text verbatim rather than
     * duplicating the logic — that class is a Page, not a subclass of
     * this Resource, so `protected` would be unreachable from it.
     */
    public static function mediaUploadPlaceholder(string $configKey, int $defaultKb): string
    {
        $maxMb = round(config($configKey, $defaultKb) / 1024, 1);

        // number_format(), not a bare (string) cast, before trimming
        // trailing zeros — a real, confirmed bug caught in a live
        // browser check: rtrim(..., '0') on a plain "10"/"100" string
        // (no decimal point at all, since PHP casts a whole float like
        // 10.0 to "10") strips a trailing zero from the INTEGER part
        // itself, silently turning 10 MB / 100 MB into "1". Forcing one
        // decimal place first (number_format($maxMb, 1, '.', '')
        // -> "10.0"/"100.0") gives rtrim() an actual fractional zero to
        // trim, protecting the integer digits.
        $formatted = rtrim(rtrim(number_format($maxMb, 1, '.', ''), '0'), '.');

        return __('products.fields.media_upload_hint', ['max' => $formatted]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // DEFAULT SORT: the product timeline (D5) — newest/most-
            // recently-promoted first. Deliberately NOT added inside
            // modifyQueryUsing() above/below (that closure only ever
            // handles the archived-only filter's own query
            // pre-conditioning, never ordering) — ->defaultSort() is
            // Filament's own real mechanism for this, confirmed against
            // the installed v5.8.1 source
            // (Filament\Tables\Concerns\CanSortRecords::
            // applySortingToTableQuery()): a user's own column sort is
            // applied FIRST (line ~96, "$column->applySort($query,
            // $sortDirection)"), and this default sort is then always
            // ALSO applied (line ~112, guarded only by
            // "$defaultSort !== $tableSortColumn") — so clicking a
            // sortable column becomes the PRIMARY sort key, with
            // timeline_at reduced to a secondary tie-break, never
            // silently overridden or suppressed. The stable `id desc`
            // tie-break is NOT added manually here: Filament's own
            // Table::$hasDefaultKeySort defaults to true (confirmed:
            // Filament\Tables\Table\Concerns\CanSortRecords::
            // hasDefaultKeySort()), which the SAME method (lines
            // ~119-140) uses to automatically append
            // "ORDER BY catalog_products.id DESC" whenever the query
            // doesn't already sort by the key column — matching the
            // active sort direction, exactly the (timeline_at, id)
            // pair the new composite index backs.
            ->defaultSort('timeline_at', 'desc')
            // Single subquery-select, not a formal Eloquent relation on
            // Catalog's own ProductModel: EasyCo\Catalog has no
            // composer/package dependency on EasyCo\Media anywhere
            // (confirmed — grep found zero existing cross-references),
            // and a HasOne relation to EasyCo\Media\Persistence\Eloquent\
            // ProductMediaModel would be the first one, a genuine new
            // package-boundary coupling CLAUDE.md rule 9 warns against
            // ("cross-domain references are always by id/string
            // contract, never a direct package dependency"). This raw,
            // correlated subquery lives entirely in the app/ layer
            // (already a legitimate composition point spanning both
            // packages) and resolves every row's thumbnail in the
            // table's one query, avoiding N+1 without the coupling.
            //
            // BOTH SIMPLE and VARIABLE products are shown here by
            // default — this Resource's list is no longer SIMPLE-only
            // by scope. EditAction::make()->url() and recordUrl() below
            // route each type to its own real edit page (EditProduct /
            // EditVariableProduct) — a VARIABLE row is no longer routed
            // away from editing entirely, now that
            // EditVariableProduct's own parent-fields-only scaffold
            // exists (per-variation price/cost/stock/barcode/
            // is_purchasable and adding new variations are still
            // separate, later steps — see that page's own docblock).
            //
            // ARCHIVED PRODUCTS HIDDEN BY DEFAULT: modifyQueryUsing()
            // runs BEFORE filters in Filament's own query pipeline
            // (confirmed against HasRecords::getFilteredTableQuery() —
            // getTable()->getQuery() applies this closure first, THEN
            // filterTableQuery() applies the "archived_only" Filter
            // below on top of it), so this closure cannot simply add an
            // unconditional `status != archived` — the "archived_only"
            // filter's own `status = archived` would then always
            // contradict it, returning zero rows even when that filter
            // is active. $livewire is injected BY NAME, not type — a
            // real, confirmed mechanism (Table::
            // resolveDefaultClosureDependencyForEvaluationByName()
            // resolves the 'livewire' parameter name specifically,
            // checked directly against the installed v5.8.1 source),
            // giving this closure the one thing it needs:
            // getTableFilterState() to see whether "archived_only" is
            // currently active and skip its own exclusion when it is.
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $showArchivedOnly = (bool) ($livewire?->getTableFilterState('archived_only')['isActive'] ?? false);

                if (! $showArchivedOnly) {
                    $query->where('status', '!=', ProductStatus::ARCHIVED->value);
                }

                return $query
                    ->addSelect([
                        'thumbnail_path' => DB::table('catalog_product_media')
                            ->join('catalog_media', 'catalog_media.id', '=', 'catalog_product_media.media_id')
                            ->whereColumn('catalog_product_media.product_id', 'catalog_products.id')
                            ->orderBy('catalog_product_media.sort_order')
                            ->limit(1)
                            ->select('catalog_media.path'),
                    ]);
            })
            ->columns([
                ImageColumn::make('thumbnail_path')
                    ->label(__('products.fields.thumbnail'))
                    ->disk(config('services.media.default_disk', 'public'))
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price_display')
                    ->label(__('products.fields.price_display'))
                    ->html()
                    // NOT sortable — a PriceRange has no single scalar
                    // column to ORDER BY (its "lowest final" is computed
                    // per-page, in memory, from a batched resolve — see
                    // priceRangeHtml()'s own docblock); sorting by price
                    // would need a real, indexed price-range column this
                    // Resource does not maintain, a separate future task
                    // if ever needed.
                    ->getStateUsing(fn (ProductModel $record, $livewire): string => static::priceRangeHtml(
                        static::priceRangeForRecord($record, $livewire)
                    )),
                TextColumn::make('base_sku')
                    ->label(__('products.fields.base_sku'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('products.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("products.status_options.{$state}"))
                    ->color(fn (string $state): string => match (ProductStatus::from($state)) {
                        ProductStatus::ACTIVE => 'success',
                        ProductStatus::DRAFT => 'gray',
                        ProductStatus::ARCHIVED => 'danger',
                    }),
                TextColumn::make('catalog_visibility')
                    // Shorter, table-only label — the full
                    // "products.fields.catalog_visibility" label stays as
                    // it is everywhere else (form, infolist, the
                    // TernaryFilter below); this column alone is tight on
                    // horizontal space next to the new price column, and
                    // the badge's own color already makes the visible/
                    // hidden meaning clear without the longer wording.
                    ->label(__('products.fields.catalog_visibility_column'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("products.visibility_options.{$state}"))
                    ->color(fn (string $state): string => $state === CatalogVisibility::VISIBLE->value ? 'success' : 'gray'),
                TextColumn::make('brand.name')
                    ->label(__('products.fields.brand_id'))
                    ->visible(fn (): bool => static::brandFieldEnabled()),
                // ProductModel::categories() — a real, read-only
                // BelongsToMany added specifically so Filament's table
                // columns/filters have something to query against (see
                // that relation's own docblock); the domain layer's own
                // category writes still go exclusively through
                // ProductCategoryRepository.
                TextColumn::make('categories.name')
                    ->label(__('products.fields.categories'))
                    ->bulleted()
                    ->limitList(3),
            ])
            ->filters([
                // A dedicated "archived only" view, not a toggle that
                // ADDS archived into the normal list — confirmed
                // requirement. Inactive by default (matches
                // modifyQueryUsing()'s own default exclusion above);
                // when active, ->query() below is the ONLY status
                // constraint applied (modifyQueryUsing() detects this
                // via getTableFilterState() and skips its own exclusion
                // — see that closure's own comment for why).
                Filter::make('archived_only')
                    ->label(__('products.filters.archived_only'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', ProductStatus::ARCHIVED->value)),
                TernaryFilter::make('catalog_visibility')
                    ->label(__('products.fields.catalog_visibility'))
                    ->queries(
                        true: fn ($query) => $query->where('catalog_visibility', CatalogVisibility::VISIBLE->value),
                        false: fn ($query) => $query->where('catalog_visibility', CatalogVisibility::HIDDEN->value),
                    ),
                // is_purchasable lives on the universal Variation, not on
                // Product itself at the DB level — confirmed via
                // ProductModel::variations() (HasMany). Filtered via that
                // real relationship, not a nonexistent Product column.
                TernaryFilter::make('is_purchasable')
                    ->label(__('products.fields.is_purchasable'))
                    ->queries(
                        true: fn ($query) => $query->whereHas('variations', fn ($q) => $q->where('is_purchasable', true)),
                        false: fn ($query) => $query->whereHas('variations', fn ($q) => $q->where('is_purchasable', false)),
                    ),
                SelectFilter::make('brand_id')
                    ->label(__('products.fields.brand_id'))
                    ->options(fn (): array => BrandModel::pluck('name', 'id')->all())
                    ->visible(fn (): bool => static::brandFieldEnabled()),
                SelectFilter::make('season_id')
                    ->label(__('products.fields.season_id'))
                    ->options(fn (): array => SeasonModel::pluck('name', 'id')->all())
                    ->visible(fn (): bool => static::seasonFieldEnabled()),
                SelectFilter::make('product_group_id')
                    ->label(__('products.fields.product_group_id'))
                    ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                    ->visible(fn (): bool => static::productGroupFieldEnabled()),
                // NOT ->relationship(): a real, confirmed gap found in
                // a live check — SelectFilter::relationship() builds its
                // OWN options from the relationship internally and
                // silently ignores any ->options() also set (confirmed
                // against the installed v5.8.1 source,
                // SelectFilter::getFormField()'s query-vs-plain branch),
                // and without ->preload() that relationship-driven
                // Select shows NOTHING until the user types a search
                // term — exactly the "empty dropdown" this replaces. A
                // plain ->options() (this task's own real, tree-ordered
                // hierarchicalOptions(), eagerly evaluated, no
                // preload/search-typing gap) plus a manual ->query()
                // whereHas() against the same read-only
                // ProductModel::categories() the column above displays.
                SelectFilter::make('categories')
                    ->label(__('products.fields.categories'))
                    ->options(fn (): array => CategoryResource::hierarchicalOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'categories',
                            fn (Builder $categoriesQuery): Builder => $categoriesQuery->where('catalog_categories.id', $data['value'])
                        );
                    }),
                // Mirrors the 'categories' filter above exactly, against
                // the same kind of read-only many-to-many
                // (ProductModel::tags(), catalog_product_tags) — and, like
                // 'brand_id'/'season_id'/'product_group_id'/'categories',
                // it is also the deep-link target of TagResource's
                // products_count column (?filters[tags][value]=<id>), so
                // it is a real, visible, manually-usable filter rather
                // than a hidden-fields-only one like 'attribute_usage'.
                SelectFilter::make('tags')
                    ->label(__('products.fields.tags'))
                    ->options(fn (): array => TagModel::pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (): bool => static::tagsFieldEnabled())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'tags',
                            fn (Builder $tagsQuery): Builder => $tagsQuery->where('catalog_tags.id', $data['value'])
                        );
                    }),
                // Deep-link-only, driven entirely by AttributeDefinition
                // Resource/AttributeValueResource's own descriptive_count
                // column ->url() callbacks via Filament's real
                // ListRecords::$tableFilters URL binding (#[Url(as:
                // 'filters')], confirmed against the installed v5.8.1
                // source — vendor/filament/filament/src/Resources/Pages/
                // ListRecords.php).
                //
                // DELIBERATELY NOT ->hidden() — a real, confirmed gap
                // found while testing: Table\Concerns\HasFilters::
                // getFilters() defaults to $withHidden = false, and
                // Livewire\Concerns\HasFilters::applyFiltersToTableQuery()
                // calls that SAME no-args getFilters() for BOTH building
                // the Filters panel AND applying every filter's ->query()
                // to the table's query — so ->hidden() would have
                // silently excluded this filter from ever actually
                // running, not just from the UI (this was tried first;
                // a real test confirmed it filtered nothing). This
                // Filter therefore stays registered normally — it DOES
                // appear in the FiltersAction panel, but with no visible
                // controls (->schema() is Hidden fields only, and
                // ->label('') suppresses its heading), so the practical
                // footprint is minimal. Flagged as a known, minor,
                // accepted UI quirk rather than silently worked around.
                //
                // Replaces RelatedProductsDescriptive on BOTH resources
                // ONLY — the two DESCRIPTIVE drill-down pages, not the
                // two AXIS ones. RelatedProductsAxis stays untouched: it
                // is currently the ONLY place in this admin panel a
                // VARIABLE product's row is viewable at all (this
                // Resource's own query below is hard-scoped to
                // type=SIMPLE, and axis usage — confirmed against
                // Product::setVariationAxes(), which throws for any
                // non-VARIABLE product — can only ever exist on a
                // VARIABLE product). Redirecting axis_count here would
                // make that link always show zero results. See this
                // task's own report for the flagged follow-up: axis
                // usage gets its own proper redirect once a real
                // VARIABLE-product admin view exists.
                //
                // Query mirrors the two retired pages' own real,
                // proven subqueries EXACTLY, not re-derived: when
                // attribute_value_id is set (AttributeValueResource's
                // own link), filter by attribute_value_id alone — no
                // explicit is_variation_axis check, because that column
                // is only ever populated on a descriptive
                // (is_variation_axis=false) row in the first place (see
                // catalog_product_attributes' own migration comment).
                // When only attribute_definition_id is set
                // (AttributeDefinitionResource's own link), filter by
                // attribute_definition_id AND is_variation_axis=false
                // explicitly, since a definition can have BOTH
                // descriptive and axis rows across different products.
                Filter::make('attribute_usage')
                    ->label('')
                    ->schema([
                        Hidden::make('attribute_definition_id'),
                        Hidden::make('attribute_value_id'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $definitionId = $data['attribute_definition_id'] ?? null;
                        $valueId = $data['attribute_value_id'] ?? null;

                        if (blank($definitionId) && blank($valueId)) {
                            return $query;
                        }

                        return $query->whereIn('id', function ($subQuery) use ($definitionId, $valueId): void {
                            $subQuery->select('product_id')->from('catalog_product_attributes');

                            if (filled($valueId)) {
                                $subQuery->where('attribute_value_id', $valueId);

                                return;
                            }

                            $subQuery->where('attribute_definition_id', $definitionId)
                                ->where('is_variation_axis', false);
                        });
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        // Both types visible now — EditVariableProduct
                        // exists, so the type guard this ->visible()
                        // used to need (excluding VARIABLE entirely,
                        // back when only EditProduct/SIMPLE existed) is
                        // gone. ->url() below is what actually routes
                        // each type correctly; Filament's own default
                        // action URL (Page::getDefaultActionUrl())
                        // always resolves an EditAction to the 'edit'
                        // page unconditionally, so leaving ->url()
                        // unset here would send a VARIABLE row to
                        // EditProduct and crash it — confirmed against
                        // that real source, not assumed.
                        ->visible(fn (ProductModel $record): bool => static::canEdit($record))
                        ->url(fn (ProductModel $record): string => $record->type === ProductType::SIMPLE->value
                            ? static::getUrl('edit', ['record' => $record])
                            : static::getUrl('edit-variable', ['record' => $record])),
                    static::promoteAction(),
                    static::unpromoteAction(),
                    static::duplicateAction(),
                ]),
            ])
            // Edit by default on row click — the most-used action on
            // this list — falling back to View only for a staff member
            // without edit rights. canEdit() is the same real
            // Staff::can(Permission) check EditAction's own ->visible()
            // above already uses, so this never routes a click
            // somewhere the three-dot menu itself would refuse. A
            // VARIABLE row with edit rights now routes to
            // 'edit-variable' — EditVariableProduct exists — matching
            // EditAction's own ->url() above exactly.
            ->recordUrl(fn (ProductModel $record): string => match (true) {
                ! static::canEdit($record) => static::getUrl('view', ['record' => $record]),
                $record->type === ProductType::SIMPLE->value => static::getUrl('edit', ['record' => $record]),
                default => static::getUrl('edit-variable', ['record' => $record]),
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('products.fields.name')),
            TextEntry::make('slug')
                ->label(__('products.fields.slug')),
            TextEntry::make('base_sku')
                ->label(__('products.fields.base_sku')),
            TextEntry::make('status')
                ->label(__('products.fields.status'))
                ->formatStateUsing(fn (string $state): string => __("products.status_options.{$state}")),
            TextEntry::make('catalog_visibility')
                ->label(__('products.fields.catalog_visibility'))
                ->formatStateUsing(fn (string $state): string => __("products.visibility_options.{$state}")),
            TextEntry::make('brand.name')
                ->label(__('products.fields.brand_id')),
            TextEntry::make('season.name')
                ->label(__('products.fields.season_id')),
            TextEntry::make('productGroup.name')
                ->label(__('products.fields.product_group_id')),
            // regular_price/sale_price are now TWO DIMENSIONS OF THE
            // SAME PriceRange, NOT a combined "struck-through +
            // sale" rendering the way the list column's price_display
            // is (see priceRangeHtml()'s own docblock for that
            // distinction) — each reads its own PriceRange accessor and
            // is prefixed independently.
            //
            // regular_price -> lowestRegularPrice(): the minimum regular
            // (pre-discount) dimension across this product's priced,
            // non-archived variations, prefixed with
            // __('products.price_from').' ' whenever
            // hasUniformRegularPrice() is false (i.e. the variations'
            // regular prices genuinely differ). Blank when the product
            // has no priced variation at all.
            //
            // sale_price -> lowestDiscountedFinalQuote()?->final: the
            // minimum FINAL price among quotes that are currently
            // discounted, prefixed the same way when
            // hasUniformDiscountedFinalPrice() is false. BLANK WHEN
            // NOTHING IS DISCOUNTED — this is what keeps the SIMPLE
            // no-sale case identical to today apart from the symbol.
            //
            // ACCEPTED CONSEQUENCE, deliberate, not a bug: this field
            // now shows the effective discounted price, which may come
            // from a percentage-off campaign (PERCENTAGE_OFF_REGULAR)
            // rather than specifically the "Manual Sale" system list —
            // the same "show what the customer actually pays" principle
            // already governing the list column's own price_display, no
            // second, alternative definition kept in parallel.
            //
            // Same COST_VIEW visibility gate as the form's own cost
            // field — confirmed explicitly by the domain owner as a
            // both-surfaces requirement, not form-only.
            TextEntry::make('regular_price')
                ->label(__('products.fields.regular_price'))
                ->getStateUsing(function (ProductModel $record): ?string {
                    $priceRange = app(ProductPriceRangeProvider::class)->forProduct((string) $record->id);
                    $lowestRegular = $priceRange->lowestRegularPrice();

                    if ($lowestRegular === null) {
                        return null;
                    }

                    $formatted = app(PriceDisplayFormatter::class)->format(
                        $lowestRegular->gross()->decimalValue(),
                        $lowestRegular->gross()->currency()
                    );

                    return $priceRange->hasUniformRegularPrice() ? $formatted : __('products.price_from').' '.$formatted;
                }),
            TextEntry::make('sale_price')
                ->label(__('products.fields.sale_price'))
                ->getStateUsing(function (ProductModel $record): ?string {
                    $priceRange = app(ProductPriceRangeProvider::class)->forProduct((string) $record->id);
                    $lowestDiscountedFinal = $priceRange->lowestDiscountedFinalQuote();

                    if ($lowestDiscountedFinal === null) {
                        return null;
                    }

                    $formatted = app(PriceDisplayFormatter::class)->format(
                        $lowestDiscountedFinal->final->gross()->decimalValue(),
                        $lowestDiscountedFinal->final->gross()->currency()
                    );

                    return $priceRange->hasUniformDiscountedFinalPrice() ? $formatted : __('products.price_from').' '.$formatted;
                }),
            TextEntry::make('cost')
                ->label(__('products.fields.cost'))
                ->visible(fn (): bool => static::staffCanForAction(Permission::COST_VIEW))
                ->getStateUsing(fn (ProductModel $record): ?string => app(ProductPricingAndStock::class)->costDisplay(static::universalVariationId($record))),
            TextEntry::make('stock_quantity')
                ->label(__('products.fields.stock_quantity'))
                ->getStateUsing(fn (ProductModel $record): int => app(ProductPricingAndStock::class)->stockQuantity(static::universalVariationId($record))),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    /**
     * Shared by infolist()'s four price/stock entries above — the
     * SIMPLE product's one universal Variation, by its own priceableId()
     * (== id(), per that accessor's own docblock). A single, named
     * lookup rather than repeating `$record->variations()->value('id')`
     * four times inline.
     */
    protected static function universalVariationId(ProductModel $record): string
    {
        return (string) $record->variations()->value('id');
    }

    /**
     * Resolves one record's PriceRange for the 'price_display' column,
     * BATCHING THE WHOLE CURRENT PAGE, ONCE, NOT ONE CALL PER ROW when
     * $livewire is a real table-bearing component — two real, confirmed
     * Filament internals make this safe (installed v5.8.1 source):
     * 1. Filament\Tables\Columns\Column::
     *    resolveDefaultClosureDependencyForEvaluationByName():
     *    "'livewire' => [$this->getLivewire()]" — a column closure's
     *    $livewire parameter is injected BY NAME, giving this method the
     *    live table/page component itself, not a per-column value.
     * 2. Filament\Tables\Concerns\HasRecords::getTableRecords():
     *    "if ($this->cachedTableRecords) { return $this->cachedTableRecords; }"
     *    — the page's own records are memoized on FIRST call and simply
     *    returned again on every subsequent one, so calling it here on
     *    every row of the SAME render never re-runs the table's own
     *    query.
     * Combined with ProductPriceRangeProvider's own per-instance
     * memoization (a scoped() binding), the FIRST row to reach this
     * method triggers one real batched resolve for the whole page;
     * every later row on that same page is a pure in-memory cache hit.
     *
     * FALLS BACK TO A SINGLE-PRODUCT RESOLVE when $livewire is not
     * table-bearing (no getTableRecords() method) — keeps this method
     * correct if the column is ever reused OUTSIDE ListProducts (a
     * relation manager, an export) where $livewire would not carry this
     * column's own page. Extracted as its own testable method rather
     * than inlined in the column closure specifically so this fallback
     * branch has a real, direct test independent of Filament's table
     * rendering pipeline.
     */
    public static function priceRangeForRecord(ProductModel $record, mixed $livewire): ?PriceRange
    {
        $isTableBearing = $livewire instanceof HasTable
            || method_exists($livewire, 'getTableRecords');

        if (! $isTableBearing) {
            return app(ProductPriceRangeProvider::class)->forProduct((string) $record->id);
        }

        $productIds = $livewire->getTableRecords()
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return app(ProductPriceRangeProvider::class)->forProducts($productIds)[(string) $record->id] ?? null;
    }

    /**
     * The table's own price-range display — replaces the old
     * priceDisplayHtml()/priceMinorSubquery() pair (a correlated,
     * un-ordered LIMIT 1 subquery over the FIRST variation's
     * VARIATION-level item only, which is exactly why a VARIABLE
     * product — and any product priced at PRODUCT level rather than
     * VARIATION level — always showed "—", a real, confirmed defect,
     * not a deliberate SIMPLE-only scope). Now backed by
     * EasyCo\Pricing\PriceRange, resolved for the whole page in one
     * batch via App\Services\ProductPriceRangeProvider (see the
     * 'price_display' column's own ->getStateUsing() closure) — the
     * same resolver-backed range both a VARIABLE and a SIMPLE product
     * now render through identically, no more special-cased column.
     *
     * DISPLAY RULES (D1), exact:
     * - an empty range (nothing resolvable) → '—';
     * - otherwise render $priceRange->lowestFinalQuote(): the plain
     *   amount, or struck-through regular + final when that same quote
     *   isDiscounted();
     * - prefixed with __('products.price_from').' ' whenever
     *   hasUniformFinalPrice() is false — i.e. this product's
     *   variations do NOT all currently resolve to the same final
     *   price, so the rendered amount is only a "starting from" figure,
     *   not necessarily what every variation costs.
     * - no min-max range, no "Sale!" badge, no extra colouring — all
     *   explicitly deferred (see admin-panel-design.md's own §13.x
     *   entry for this pass).
     *
     * Every amount is escaped (e()) exactly like the old
     * priceDisplayHtml() did — ->html() on the column means this
     * string is rendered unescaped by Filament, so anything
     * interpolated into it must already be safe.
     */
    public static function priceRangeHtml(?PriceRange $priceRange): string
    {
        if ($priceRange === null || $priceRange->isEmpty()) {
            return '—';
        }

        $lowestFinalQuote = $priceRange->lowestFinalQuote();
        $formatter = app(PriceDisplayFormatter::class);

        $regular = e($formatter->format($lowestFinalQuote->regular->gross()->decimalValue(), $lowestFinalQuote->regular->gross()->currency()));
        $final = e($formatter->format($lowestFinalQuote->final->gross()->decimalValue(), $lowestFinalQuote->final->gross()->currency()));

        $html = $lowestFinalQuote->isDiscounted() ? "<s>{$regular}</s> {$final}" : $final;

        if (! $priceRange->hasUniformFinalPrice()) {
            $html = e(__('products.price_from')).' '.$html;
        }

        return $html;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            // Positioned before 'view' for the same reason 'create'
            // already is: 'view' is registered as the wildcard
            // '/{record}', and Laravel's router matches routes in
            // registration order — a static '/create-variable' segment
            // registered AFTER '/{record}' would never be reached
            // (it would match '/{record}' first, with record =
            // "create-variable").
            'create-variable' => CreateVariableProduct::route('/create-variable'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
            // Registered AFTER the 'view' wildcard, unlike
            // 'create-variable' above — safe here (and for 'edit'/
            // 'activity-log' too) because Laravel's router matches on
            // full segment count, not just registration order: '/
            // {record}' only matches a ONE-segment path, so it can
            // never swallow a TWO-segment path like
            // '/{record}/edit-variable' regardless of which is
            // registered first. Only a route that is itself a bare,
            // single dynamic/static segment (like '/create-variable')
            // risks being shadowed by '/{record}'.
            'edit-variable' => EditVariableProduct::route('/{record}/edit-variable'),
            'activity-log' => ProductActivityLog::route('/{record}/activity-log'),
        ];
    }

    /**
     * "Duplicate" — admin-panel-design.md §13.2, extended to VARIABLE
     * per product-duplication-and-templates-note.md (DuplicateProduct's
     * own docblock has the full reasoning: a VARIABLE duplicate always
     * gets zero declared axes/variations, by permanent domain-owner
     * decision, not a temporary limitation). Gated by createPermission(),
     * not editPermission() — duplicating is really "creating with
     * prefilled values," the same permission a plain Create already
     * requires. On success, redirects straight into the new product's
     * real Edit page (§13.2: "not a prefilled Create form awaiting a
     * first save") — a VARIABLE duplicate lands on EditVariableProduct's
     * own first ("General") tab, exactly like landing on this page any
     * other way: no ?tab= query param is passed, since Tabs::getActiveTab()
     * already falls back to the first declared tab when the query string
     * carries none (confirmed against the installed source). $livewire is
     * a real, named-parameter-injectable closure argument on
     * Filament\Actions\Action (confirmed against the installed source),
     * giving access to Livewire's own redirect().
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('products.duplicate_action'))
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (): bool => static::canCreate())
            ->action(function (ProductModel $record, $livewire): void {
                $duplicate = app(DuplicateProduct::class)->duplicate((string) $record->id);

                $editPage = $duplicate->type() === ProductType::SIMPLE ? 'edit' : 'edit-variable';

                $livewire->redirect(static::getUrl($editPage, ['record' => $duplicate->id()]));
            });
    }

    /**
     * "Избутай напред" ("Move to front") — the product timeline
     * promotion row action, App\Services\ProductTimelinePromoter's real
     * admin-panel entry point. $at is `new DateTimeImmutable()`, called
     * HERE at the outermost Livewire-action edge (never inside the
     * service — see that class's own "explicit required parameter,
     * never internal now()" docblock).
     *
     * ->icon('heroicon-o-bars-arrow-up') reads as "move up/promote" —
     * deliberately not an upload icon (heroicon-o-arrow-up-tray, easy
     * to reach for by mistake, means something completely different).
     *
     * Gated by the SAME permission as EditAction (canEdit(), not a new
     * dedicated permission) and hidden for an ARCHIVED product — an
     * archived product is never shown in any merchant-facing timeline,
     * so the action would be meaningless there; this is the real UX
     * guard, ProductTimelinePromoter::promote()'s own
     * CannotPromoteArchivedProductException is the defense-in-depth
     * behind it, same "never trust the button's own visibility alone"
     * posture already established elsewhere in this Resource
     * (historyAction()'s own docblock).
     */
    public static function promoteAction(): Action
    {
        return Action::make('promote')
            ->label(__('products.actions.promote'))
            ->icon('heroicon-o-bars-arrow-up')
            ->requiresConfirmation()
            ->modalHeading(__('products.actions.promote_confirm_heading'))
            ->modalDescription(fn (ProductModel $record): string => __('products.actions.promote_confirm_description', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('products.actions.promote_confirm_submit'))
            ->modalCancelActionLabel(__('products.actions.promote_confirm_cancel'))
            ->visible(fn (ProductModel $record): bool => static::canEdit($record) && $record->status !== ProductStatus::ARCHIVED->value)
            ->action(function (ProductModel $record): void {
                app(ProductTimelinePromoter::class)->promote((string) $record->id, new DateTimeImmutable());

                Notification::make()
                    ->title(__('products.actions.promote_done'))
                    ->success()
                    ->send();
            });
    }

    /**
     * "Undo move to front" — the counterpart of promoteAction() above.
     * Visible only when the row is ACTUALLY promoted
     * ($record->timeline_at > $record->created_at, the exact same
     * strict comparison Product::isPromoted() itself uses — read
     * directly off the already-loaded table row's own cast Carbon
     * columns, not a fresh domain reload per row) — an un-promoted
     * product has nothing for this action to undo.
     */
    public static function unpromoteAction(): Action
    {
        return Action::make('unpromote')
            ->label(__('products.actions.unpromote'))
            ->icon('heroicon-o-bars-arrow-down')
            ->requiresConfirmation()
            ->modalHeading(__('products.actions.unpromote_confirm_heading'))
            ->modalDescription(fn (ProductModel $record): string => __('products.actions.unpromote_confirm_description', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('products.actions.unpromote_confirm_submit'))
            ->modalCancelActionLabel(__('products.actions.unpromote_confirm_cancel'))
            ->visible(fn (ProductModel $record): bool => static::canEdit($record)
                && $record->status !== ProductStatus::ARCHIVED->value
                && $record->timeline_at->gt($record->created_at))
            ->action(function (ProductModel $record): void {
                app(ProductTimelinePromoter::class)->unpromote((string) $record->id);

                Notification::make()
                    ->title(__('products.actions.unpromote_done'))
                    ->success()
                    ->send();
            });
    }

    /**
     * "History" — links to ProductActivityLog, a real page navigation
     * (->url(), not ->action()) mirroring the "products" count column's
     * own ->url(fn (...$record...) => static::getUrl(...)) pattern used
     * throughout the other Resources' drill-down links. Gated by
     * viewPermission()/PRODUCT_VIEW (browsing history is a read
     * operation, same as this Resource's own canView(), not
     * canEdit()/createPermission() like duplicateAction() above) AND
     * COST_VIEW — a real gap closed by this check: the activity log
     * records every changed field, cost included, so a staff member
     * without COST_VIEW could read past cost values through History even
     * though the live Cost field is correctly hidden from them
     * everywhere else (priceStockTabComponents()'s own COST_VIEW gate).
     * ProductActivityLog::mount() re-checks the same combined condition
     * server-side — this ->visible() alone is a UX aid, not the real
     * enforcement, same "never trust nav/action visibility alone"
     * posture as ActivityLogJournal's own isAccessible().
     */
    public static function historyAction(): Action
    {
        return Action::make('history')
            ->label(__('products.activity_log.history_button'))
            ->icon('heroicon-o-clock')
            ->visible(fn (ProductModel $record): bool => static::canView($record) && static::staffHasPermission(Permission::COST_VIEW))
            ->url(fn (ProductModel $record): string => static::getUrl('activity-log', ['record' => $record]));
    }

    /**
     * Shared by Create/EditProduct — resolves the real domain
     * AttributeDefinition (and, for SELECT, the real AttributeValue)
     * from a submitted form value and calls
     * Product::setDescriptiveAttribute(). Fail-loud throughout: the
     * form only ever offers real ids as options, so a missing id at
     * this point is a genuine bug, not something to silently skip.
     */
    public static function applyDescriptiveAttribute(Product $product, AttributeDefinitionModel $definitionModel, mixed $rawValue): void
    {
        $definition = static::toDomainAttributeDefinition($definitionModel);
        $type = AttributeType::from($definitionModel->type);

        if ($type === AttributeType::SELECT) {
            $valueModel = AttributeValueModel::findOrFail($rawValue);
            $product->setDescriptiveAttribute($definition, new AttributeValue(
                id: (string) $valueModel->id,
                attributeDefinitionId: (string) $valueModel->attribute_definition_id,
                value: $valueModel->value,
                sortOrder: $valueModel->sort_order,
            ));

            return;
        }

        if ($type === AttributeType::BOOLEAN) {
            $product->setDescriptiveAttribute($definition, $rawValue ? '1' : '0');

            return;
        }

        $product->setDescriptiveAttribute($definition, (string) $rawValue);
    }

    public static function toDomainAttributeDefinition(AttributeDefinitionModel $model): AttributeDefinition
    {
        return new AttributeDefinition(
            id: (string) $model->id,
            code: $model->code,
            name: $model->name,
            type: AttributeType::from($model->type),
        );
    }

    /**
     * The single call site resolving a submitted "descriptive_attributes"
     * form value back to a comparable, normalized value for the
     * Create/Edit diff logic — a SELECT's normalized form is the
     * AttributeValue id string; every other type is its own string
     * (BOOLEAN as '1'/'0', matching Product::setDescriptiveAttribute()'s
     * own established convention — see ProductDescriptiveAttributeTest).
     * Returns null for "nothing meaningfully submitted" (blank
     * TEXT/NUMBER, no SELECT choice) — BOOLEAN is never null, since an
     * unchecked Toggle is itself a meaningful "No", not "unset".
     */
    public static function normalizeSubmittedDescriptiveValue(AttributeDefinitionModel $definitionModel, mixed $rawValue): ?string
    {
        $type = AttributeType::from($definitionModel->type);

        if ($type === AttributeType::BOOLEAN) {
            return $rawValue ? '1' : '0';
        }

        if (! filled($rawValue)) {
            return null;
        }

        return (string) $rawValue;
    }

    /**
     * The inverse of applyDescriptiveAttribute() — normalizes a
     * Product's CURRENT descriptive-attribute value (as returned by
     * descriptiveAttributes(), string|AttributeValue) to the same
     * comparable shape normalizeSubmittedDescriptiveValue() produces,
     * so Edit's diff logic can compare old vs new directly.
     */
    public static function normalizeCurrentDescriptiveValue(string|AttributeValue|null $value): ?string
    {
        if ($value instanceof AttributeValue) {
            return (string) $value->id();
        }

        return $value;
    }

    /**
     * Creates a real MediaAsset for an already-stored file path — mirrors
     * BrandResource::createLogoMediaAsset()'s exact sequence
     * (MediaAsset::create() -> MediaAssetRepository::save() ->
     * ProcessMediaAssetJob::dispatch()), reused here per uploaded
     * photo/video instead of once for a single logo.
     *
     * $type defaults to IMAGE (every existing photo call site is
     * unaffected). Dispatch is gated to IMAGE only — mirrors
     * MediaController::store()'s identical guard exactly
     * (media-domain-design.md §3.6): VIDEO/SOCIAL_VIDEO have no
     * processing pipeline at all (§4), and ProcessMediaAssetJob's own
     * markProcessing() unconditionally rejects them
     * (InvalidMediaStateTransitionException, uncaught by the job) — a
     * dispatch for video would crash a queue worker, not merely waste
     * one.
     */
    public static function createMediaAsset(string $storedPath, MediaType $type = MediaType::IMAGE): MediaAsset
    {
        $disk = config('services.media.default_disk', 'public');

        $asset = MediaAsset::create($type, $disk, $storedPath);
        app(MediaAssetRepository::class)->save($asset);

        if ($type === MediaType::IMAGE) {
            ProcessMediaAssetJob::dispatch($asset->id());
        }

        return $asset;
    }

    /**
     * Extracted from CreateVariableProduct::buildVariationAxes() — the
     * raw-input-to-VariationAxis[] construction loop, now shared
     * verbatim between CreateVariableProduct (declareAxes()/
     * generateVariationPreview()) and EditVariableProduct's own Axes
     * tab write path, so there is exactly one copy of this loop in the
     * codebase, not two drifting independently. Throws
     * InvalidVariationAxisException uncaught by design — every caller
     * is responsible for its own Notification+Halt handling, since the
     * two pages catch it differently (see each page's own call site).
     *
     * @param array<int, array{attribute_definition_id?: mixed, value_ids?: array<int, mixed>}> $axesInput
     * @return VariationAxis[]
     */
    public static function buildVariationAxesFromInput(array $axesInput): array
    {
        $axes = [];

        foreach ($axesInput as $axisInput) {
            $attributeDefinitionId = (string) ($axisInput['attribute_definition_id'] ?? '');

            $definition = app(AttributeDefinitionRepository::class)->findById($attributeDefinitionId);
            $values = array_map(
                fn ($valueId) => app(AttributeValueRepository::class)->findById((string) $valueId),
                $axisInput['value_ids'] ?? []
            );

            $axes[] = new VariationAxis($definition, $values);
        }

        return $axes;
    }

    /**
     * The real "create one STANDARD variation" core shared by
     * CreateVariableProduct::addStandardVariations() and
     * EditVariableProduct's own "Add variation" write path — both
     * pages need the exact same addStandardVariation() +
     * 'catalog.variation.barcode' Hook wrapping + optional activate()
     * sequence; this is that one shared core.
     *
     * Uniqueness/validity exceptions (InvalidVariationAxisException,
     * DuplicateVariationCombinationException — both real,
     * uncaught-by-design here) are NOT caught in this method: each
     * caller wraps its own call in its own Notification+Halt, because
     * the two pages catch a genuinely different exception set —
     * CreateVariableProduct's own combinations already came from
     * generateVariationPreview()'s own upfront validation, so only a
     * duplicate is realistically reachable there; EditVariableProduct's
     * own combination is built directly from per-axis Select input, so
     * it catches more broadly (see that page's own call site).
     *
     * @param array<int|string, int|string> $combination
     */
    public static function writeStandardVariation(Product $product, array $combination, string $sku, mixed $barcodeInput, bool $activate): Variation
    {
        $variation = $product->addStandardVariation($combination, $sku);

        $barcode = Hook::apply('catalog.variation.barcode', filled($barcodeInput) ? $barcodeInput : '', $variation);
        if ($barcode !== '') {
            $variation->setBarcode($barcode);
        }

        if ($activate) {
            $variation->activate();
        }

        return $variation;
    }
}
