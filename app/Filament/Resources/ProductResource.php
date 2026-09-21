<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ProductActivityLog;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Services\DuplicateProduct;
use App\Services\ProductPricingAndStock;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
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
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
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

    /** @return array<int, Component> */
    protected static function generalTabComponents(): array
    {
        return [
            TextInput::make('name')
                ->label(__('products.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('products.fields.slug'))
                ->helperText(__('products.fields.slug_help'))
                ->required(fn (string $operation): bool => $operation === 'edit'),
            TextInput::make('base_sku')
                ->label(__('products.fields.base_sku'))
                ->helperText(fn (string $operation): string => $operation === 'edit'
                    ? __('products.base_sku_change_warning')
                    : __('products.fields.base_sku_help'))
                ->required(fn (string $operation): bool => $operation === 'edit'),
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
                ->searchable(),
            Select::make('product_group_id')
                ->label(__('products.fields.product_group_id'))
                ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                ->searchable()
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
     * @return array<int, Component>
     */
    protected static function sidebarComponents(): array
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
                ->schema([
                    Select::make('tags')
                        ->hiddenLabel()
                        ->multiple()
                        ->options(fn (): array => TagModel::pluck('name', 'id')->all())
                        ->searchable(),
                ]),
            Section::make(__('products.fields.season_id'))
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
     * §7): one field per real AttributeDefinition not used as this
     * product's variation axis — trivially every definition for a
     * SIMPLE product, since one never has a variation axis at all.
     * MULTISELECT is skipped entirely — Product::setDescriptiveAttribute()
     * itself throws for that type, so rendering a field that could never
     * successfully submit would be worse than not offering it.
     *
     * @return array<int, Component>
     */
    protected static function attributesTabComponents(): array
    {
        $components = [];

        foreach (static::descriptiveAttributeDefinitions() as $definitionModel) {
            $key = "descriptive_attributes.{$definitionModel->id}";
            $type = AttributeType::from($definitionModel->type);

            $components[] = match ($type) {
                AttributeType::TEXT, AttributeType::NUMBER => TextInput::make($key)
                    ->label($definitionModel->name),
                AttributeType::BOOLEAN => Toggle::make($key)
                    ->label($definitionModel->name),
                AttributeType::SELECT => Select::make($key)
                    ->label($definitionModel->name)
                    ->options(
                        fn (): array => AttributeValueModel::where('attribute_definition_id', $definitionModel->id)
                            ->pluck('value', 'id')
                            ->all()
                    )
                    ->searchable(),
                AttributeType::MULTISELECT => null,
            };
        }

        return array_values(array_filter($components));
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
     */
    protected static function mediaUploadPlaceholder(string $configKey, int $defaultKb): string
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
            // by scope. A VARIABLE row's real Edit-page crash
            // ($product->universalVariation()->barcode() on null — a
            // VARIABLE Product genuinely has no universal Variation) is
            // instead kept unreachable at the action/routing level:
            // EditAction::make()->visible() and recordUrl() below both
            // additionally require type===SIMPLE, so a VARIABLE row is
            // always routed to (and only ever offers) View, never Edit.
            // This Resource still does not offer a real VARIABLE edit
            // experience — that remains separate, undesigned scope.
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

                // Both system list ids resolved ONCE here, outside the
                // per-row subqueries below — findSystemListByName() is a
                // real query itself, and this closure runs once per table
                // render, not once per row (see priceMinorSubquery()'s own
                // docblock for the null-list, unseeded-store case).
                $priceLists = app(PriceListRepository::class);
                $regularPricesListId = $priceLists->findSystemListByName('Regular Prices')?->id();
                $manualSaleListId = $priceLists->findSystemListByName('Manual Sale')?->id();

                return $query
                    ->addSelect([
                        'thumbnail_path' => DB::table('catalog_product_media')
                            ->join('catalog_media', 'catalog_media.id', '=', 'catalog_product_media.media_id')
                            ->whereColumn('catalog_product_media.product_id', 'catalog_products.id')
                            ->orderBy('catalog_product_media.sort_order')
                            ->limit(1)
                            ->select('catalog_media.path'),
                        'regular_price_minor' => static::priceMinorSubquery($regularPricesListId),
                        'sale_price_minor' => static::priceMinorSubquery($manualSaleListId),
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
                    ->getStateUsing(fn (ProductModel $record): string => static::priceDisplayHtml($record)),
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
                    ->label(__('products.fields.brand_id')),
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
                    ->options(fn (): array => BrandModel::pluck('name', 'id')->all()),
                SelectFilter::make('season_id')
                    ->label(__('products.fields.season_id'))
                    ->options(fn (): array => SeasonModel::pluck('name', 'id')->all()),
                SelectFilter::make('product_group_id')
                    ->label(__('products.fields.product_group_id'))
                    ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all()),
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
                        ->visible(fn (ProductModel $record): bool => static::canEdit($record)
                            && $record->type === ProductType::SIMPLE->value),
                    static::duplicateAction(),
                ]),
            ])
            // Edit by default on row click — the most-used action on
            // this list — falling back to View only for a staff member
            // without edit rights, OR for a VARIABLE row regardless of
            // edit rights (same reasoning as EditAction's own
            // ->visible() above: EditProduct has no real VARIABLE
            // support yet and would crash on
            // universalVariation()->barcode() — a VARIABLE Product has
            // no universal Variation, by design). canEdit() is the same
            // real Staff::can(Permission) check EditAction's own
            // ->visible() above already uses, so this never routes a
            // click somewhere the three-dot menu itself would refuse.
            ->recordUrl(fn (ProductModel $record): string => static::canEdit($record) && $record->type === ProductType::SIMPLE->value
                ? static::getUrl('edit', ['record' => $record])
                : static::getUrl('view', ['record' => $record]));
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
            // Same COST_VIEW visibility gate as the form's own cost
            // field — confirmed explicitly by the domain owner as a
            // both-surfaces requirement, not form-only.
            TextEntry::make('regular_price')
                ->label(__('products.fields.regular_price'))
                ->getStateUsing(fn (ProductModel $record): ?string => app(ProductPricingAndStock::class)->regularPriceDisplay(static::universalVariationId($record))),
            TextEntry::make('sale_price')
                ->label(__('products.fields.sale_price'))
                ->getStateUsing(fn (ProductModel $record): ?string => app(ProductPricingAndStock::class)->salePriceDisplay(static::universalVariationId($record))),
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
     * The table's own combined price display — reads regular_price_minor/
     * sale_price_minor, the two raw minor-unit ints modifyQueryUsing()
     * already resolved via ONE correlated subquery per row (no N+1
     * ProductPricingAndStock lookup here — that service is right for a
     * single-record Edit/View page, wrong for a many-row list). Same
     * Money::fromMinorUnits()->decimalValue() conversion Phase 2 already
     * established, no currency symbol — matches
     * ProductPricingAndStock::regularPriceDisplay()'s own plain-decimal
     * convention exactly, just read from the pre-selected column instead
     * of a fresh repository call.
     */
    public static function priceDisplayHtml(ProductModel $record): string
    {
        $regularMinor = $record->getAttribute('regular_price_minor');

        if ($regularMinor === null) {
            return '—';
        }

        $currency = DefaultCurrency::get();
        $regular = e(Money::fromMinorUnits((int) $regularMinor, $currency)->decimalValue());

        $saleMinor = $record->getAttribute('sale_price_minor');

        if ($saleMinor === null) {
            return $regular;
        }

        $sale = e(Money::fromMinorUnits((int) $saleMinor, $currency)->decimalValue());

        return "<s>{$regular}</s> {$sale}";
    }

    /**
     * ONE correlated subquery per system list, resolved against the
     * already-resolved list id (never a per-row findSystemListByName()
     * call — see modifyQueryUsing()'s own comment). $priceListId is null
     * on a fresh/unseeded store (neither reserved system list exists
     * yet, per pricing-persistence-domain-design.md §4.5/§4.6) — that is
     * a legitimate "nothing priced yet" state on READ, not a setup
     * error (see ProductPricingAndStock's own identical read-side
     * posture), so this returns a raw NULL expression rather than
     * building a subquery against a list id that doesn't exist.
     *
     * pricing_price_list_items.target_id is a plain string column
     * (never a foreign key, by design — see that table's own migration
     * comment), holding a Catalog Variation's id as a string;
     * catalog_variations.id is the real integer PK. This is a SIMPLE-
     * product-only Resource (the query is already filtered to
     * type=SIMPLE), so resolving the correlated variation without a
     * further "type=universal" filter is safe — a SIMPLE product has
     * exactly one Variation, always universal, by Catalog's own
     * invariant.
     *
     * DELIBERATELY NOT a plain join on
     * catalog_variations.id = pricing_price_list_items.target_id: that
     * compares an int column against a varchar column, and MySQL's
     * numeric-vs-string comparison rule casts target_id's VALUES to
     * numbers for the comparison, which defeats pp_items_lookup_index's
     * (price_list_id, target_type, target_id, min_quantity) usability
     * for target_id — confirmed via a real EXPLAIN ANALYZE at ~17,000+
     * SIMPLE product scale: the join form only used the index's first
     * two columns (key_len 1030) and scanned ~5,900 rows via a cast
     * index condition. Instead, the correlated variation id is resolved
     * first (still using catalog_variations' own (product_id, status)
     * index) and compared against target_id CAST to CHAR — a same-type
     * comparison MySQL can seek on normally, confirmed via the same
     * EXPLAIN ANALYZE to use all three leading index columns (key_len
     * 2052, ~1 row). Same "compare target_id as a string" shape
     * EloquentPriceListItemRepository (the real Cart/Checkout/
     * Storefront pricing path) already uses — this fix brings the
     * admin-list convenience query in line with it, not a new approach.
     */
    protected static function priceMinorSubquery(?string $priceListId): mixed
    {
        if ($priceListId === null) {
            return DB::raw('NULL');
        }

        return DB::table('pricing_price_list_items')
            ->where('pricing_price_list_items.price_list_id', $priceListId)
            ->where('pricing_price_list_items.target_type', PriceListItemTargetType::VARIATION->value)
            ->whereRaw(
                'pricing_price_list_items.target_id = CAST((SELECT catalog_variations.id FROM catalog_variations WHERE catalog_variations.product_id = catalog_products.id LIMIT 1) AS CHAR)'
            )
            ->limit(1)
            ->select('pricing_price_list_items.price_amount_minor');
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
            'activity-log' => ProductActivityLog::route('/{record}/activity-log'),
        ];
    }

    /**
     * "Duplicate" — admin-panel-design.md §13.2. SIMPLE products only:
     * hidden entirely for a VARIABLE row (the VARIABLE creation wizard
     * this would need to feed into doesn't exist yet — see
     * DuplicateProduct's own docblock). Gated by createPermission(),
     * not editPermission() — duplicating is really "creating with
     * prefilled values," the same permission a plain Create already
     * requires. On success, redirects straight into the new product's
     * real Edit page (§13.2: "not a prefilled Create form awaiting a
     * first save") — $livewire is a real, named-parameter-injectable
     * closure argument on Filament\Actions\Action (confirmed against
     * the installed source), giving access to Livewire's own
     * redirect().
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('products.duplicate_action'))
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (ProductModel $record): bool => static::canCreate() && $record->type === ProductType::SIMPLE->value)
            ->action(function (ProductModel $record, $livewire): void {
                $duplicate = app(DuplicateProduct::class)->duplicate((string) $record->id);

                $livewire->redirect(static::getUrl('edit', ['record' => $duplicate->id()]));
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
}
