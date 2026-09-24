<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use App\Services\ActivityLogger;
use App\Services\ArchiveProductMediaCleaner;
use App\Services\ProductPricingAndStock;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Exceptions\VariationNotRestorableException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use EasyCo\Media\VideoCountGuard;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * VARIABLE-product edit scaffold — Step 1 ("real VARIABLE editing"
 * series, admin-panel-design.md §13.1's own follow-on): parent fields,
 * mirroring EditProduct.php's General+Attributes tabs and sidebar. Step
 * 2a extended the "Variations" tab from read-only to genuinely
 * editable per-row: sku/barcode/is_purchasable/cost/stock_quantity,
 * plus a bulk-set convenience for cost and stock. Step 2b adds
 * regular_price/sale_price, BOTH at the PRODUCT level ("Product
 * Regular/Sale Price" fields, writing a real PRODUCT-level
 * PriceListItem) AND per row (an explicit VARIATION-level override,
 * empty by default, falling back to the PRODUCT-level value per
 * §4.3's own real item-level resolution order). A later revision
 * groups all four mass-edit fields — product_regular_price,
 * product_sale_price, bulk_cost, bulk_stock_quantity — behind a
 * single "Edit all" Toggle, hidden by default; see
 * existingVariationsComponents()'s own docblock for the full shape
 * (and for the ->dehydratedWhenHidden() a hidden product-level price
 * field genuinely needs), and updateProduct()'s own for how each is
 * diff-written.
 *
 * Step 3 (per-variation media) and Step 4 (a new "Axes" tab for
 * extending declared axes, "Generate missing variations"/"Add
 * variation" write paths, and an archived-variations Restore list —
 * catalog-domain-design.md §3.17's directional axis guard and
 * Product::restoreArchivedVariation() are what finally made Step 4
 * possible) are now BOTH real — see axesTabComponents()/
 * archivedVariationRows()/generateMissingVariationsAction()'s own
 * docblocks. admin-panel-design.md §13.6 has the full design.
 *
 * STILL EXPLICITLY NOT HERE, deliberate limits, not oversights: moving
 * a definition from descriptive-attribute to variation-axis (or back)
 * in one submission — a merchant must remove it from the descriptive
 * picker and save first, then declare it as an axis in a later,
 * separate save (see axesTabComponents()'s own comment for why); a
 * bulk variation-edit spreadsheet-style UI; size_guide_id (still not
 * wired into SIMPLE's own admin UI either).
 *
 * form() IS OVERRIDDEN (unlike EditProduct.php, which relies on
 * EditRecord's own default `form() => static::getResource()::form()`
 * — confirmed via the installed source, vendor/filament/filament/src/
 * Resources/Pages/EditRecord.php): ProductResource::form()'s own field
 * set includes barcode/is_purchasable/price/stock, none of which apply
 * to a VARIABLE product (they live on a universal Variation a VARIABLE
 * product never has). Reuses that SAME Grid(3)+Tabs+sidebar Group
 * layout structure directly, confirmed reusable as-is, with a
 * different field set: General (ProductResource::generalTabComponents()
 * minus barcode/is_purchasable — authored fresh here, same as
 * CreateVariableProduct's own General step, since that source method is
 * `protected` and already excludes-then-reincludes those two fields
 * inline rather than as a separable chunk), Attributes (this class's
 * own attributesTabComponents(), NOT ProductResource::
 * attributesTabComponents() — see that private method's own docblock
 * for why this page needs its own axis-exclusion wrapper around
 * ProductResource::descriptiveAttributesPickerComponents() instead), a
 * third "Variations" tab (this class's own
 * existingVariationsComponents()), and the sidebar
 * (ProductResource::sidebarComponents(), unmodified — same public
 * widening, see that method's own docblock).
 *
 * WRITE-INTERCEPTION LOGIC (mutateFormDataBeforeFill()/
 * handleRecordUpdate()/syncCategories()/syncTags()/syncMedia()) IS
 * DUPLICATED FROM EditProduct.php, NOT REFACTORED OUT OF IT — these
 * sync methods are already type-agnostic (product_id-keyed, no
 * SIMPLE-specific assumption), so extracting a shared base/trait is
 * plausible, but EditProduct.php is large, already tested, and touching
 * it risks a real SIMPLE-flow regression for a DRY savings this task
 * judged not worth that risk. Flagged here, not done silently — a
 * future task can revisit the extraction if wanted.
 *
 * WRAPPED IN ITS OWN DB::transaction() — same established reasoning as
 * every other Create/Edit page in this panel (Filament's own
 * Halt-triggered rollback is a real no-op here; Panel::
 * hasDatabaseTransactions() defaults to false and AdminPanelProvider
 * never opts in — see CreateProduct's own docblock for the full
 * argument, unchanged here).
 *
 * CannotPublishEmptyVariableProductException IS HANDLED (Notification +
 * Halt, same pattern CreateVariableProduct's own createProduct()
 * established) — less likely to fire on an edit than a create (real
 * variations already exist from the product's own creation), but still
 * real and reachable (e.g. every variation later archived, then status
 * changed to Active here), so it is not left as a new unhandled gap on
 * this page.
 */
class EditVariableProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Captured in updateProduct(), read in afterSave() — this page's
     * own "here's what actually changed" notification compares each
     * row's ORIGINALLY-SUBMITTED sku against that same variation's
     * REAL, final sku after save() (covers both sku-collision-retry
     * suffixing and the base_sku cascade below with ONE comparison).
     * afterSave() has no parameters of its own (confirmed against
     * CanCallHooks::callHook()'s installed source — it invokes
     * $this->afterSave() by name, with none), so this state has to
     * live on the instance between the two calls. Keyed by
     * variation_id.
     *
     * @var array<string, string>
     */
    private array $submittedVariationSkus = [];

    /** @var array<string, string> */
    private array $finalVariationSkus = [];

    /**
     * ->requiresConfirmation() takes bool|Closure (confirmed against
     * the installed CanRequireConfirmation trait source) — the closure
     * reads $this->data['base_sku'] directly rather than relying on
     * Get() injection into an Action's own closure, which — unlike a
     * Field's own closures (->options()/->afterStateUpdated() etc.,
     * used throughout this codebase) — was never confirmed working
     * here; a plain instance-method closure capturing $this is simpler
     * and equally correct for reading the CURRENT (unsaved) form state
     * at the moment Save is clicked. $this->data is a real, public
     * property on EditRecord itself (confirmed against its installed
     * source) kept live by Livewire's own two-way binding to the
     * form's statePath('data') — the same $data this page's own tests
     * already reach via ->set('data.xxx', ...).
     *
     * Cancel is Filament's own real modal-cancel behavior — confirmed
     * it simply closes the modal without submitting anything; no
     * custom handling needed or added here.
     */
    public function hasFormWrapper(): bool
    {
        return false;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->baseSkuIsChanging())
            // A REAL BUG FOUND WHILE TESTING THIS, NOT ANTICIPATED IN
            // ADVANCE: CanOpenModal::shouldOpenModal() does NOT check
            // isConfirmationRequired() directly — it falls back to
            // hasCustomModalHeading() || hasModalDescription() || ... ,
            // and BOTH of those are satisfied unconditionally the
            // moment ->modalHeading()/->modalDescription() are ever
            // set to a non-blank value, regardless of
            // requiresConfirmation()'s own boolean (confirmed against
            // the installed CanOpenModal source, and reproduced via a
            // direct reflection test: shouldOpenModal() returned true
            // even with isConfirmationRequired() false). A static or
            // always-truthy modalHeading()/modalDescription() would
            // therefore show this modal on EVERY save, not just a real
            // base_sku change. Fixed by making all four modal-text
            // closures themselves return blank when
            // baseSkuIsChanging() is false — verified via the same
            // reflection test afterward: shouldOpenModal() now
            // correctly tracks isConfirmationRequired().
            ->modalHeading(fn (): ?string => $this->baseSkuIsChanging()
                ? __('products.base_sku_cascade.confirm_heading')
                : null)
            ->modalDescription(fn (): ?string => $this->baseSkuIsChanging()
                ? __('products.base_sku_cascade.confirm_description', [
                    'old' => $this->record->base_sku,
                    'new' => $this->data['base_sku'] ?? $this->record->base_sku,
                ])
                : null)
            ->modalSubmitActionLabel(fn (): ?string => $this->baseSkuIsChanging()
                ? __('products.base_sku_cascade.confirm_continue')
                : null)
            ->modalCancelActionLabel(fn (): ?string => $this->baseSkuIsChanging()
                ? __('products.base_sku_cascade.confirm_cancel')
                : null);
    }

    /**
     * True only when there is something real to confirm: a genuinely
     * different base_sku (or one about to be auto-generated from a
     * blank submission — see updateProduct()'s own comment for why a
     * blank field counts as "this will change" here too, not just a
     * literal string difference) on a product that actually HAS
     * variations to cascade to. $this->record->variations() is the
     * real Eloquent HasMany relation on ProductModel (confirmed against
     * its installed source — the same relation this whole Resource
     * already uses elsewhere) — ->count() runs a real query, not a
     * loaded-collection count, so this stays correct even though
     * $this->record itself is never refreshed mid-request.
     */
    private function baseSkuIsChanging(): bool
    {
        if ($this->record->variations()->count() === 0) {
            return false;
        }

        $submittedBaseSku = $this->data['base_sku'] ?? null;

        if (blank($submittedBaseSku)) {
            return true;
        }

        return $submittedBaseSku !== $this->record->base_sku;
    }

    /**
     * Reuses ProductResource::form()'s own real Grid(3)/Tabs/sidebar
     * layout structure — same shape, different field set (see class
     * docblock for exactly which methods are reused vs. authored
     * fresh). ->columns(1) on the root $schema for the identical real
     * reason ProductResource::form()'s own docblock documents: without
     * it, EditRecord::defaultForm() wraps this in Filament's own
     * framework-imposed 2-column grid, since hasCustomColumns() stays
     * false until something calls ->columns() on the root schema
     * itself.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)
                ->schema([
                    Group::make()
                        ->columnSpan(2)
                        ->schema([
                            Tabs::make('Product')
                                // Reads ?tab=<id> and activates the tab
                                // whose own ->id() matches (confirmed
                                // against the installed source:
                                // Tabs::getActiveTab() compares
                                // $tab->getId() against
                                // request()->query('tab'), and Tab::getId()
                                // is getCustomId() ?? getKey()) — which is
                                // exactly why every tab below carries an
                                // EXPLICIT id: left unset, getId() falls
                                // back to a label-derived key
                                // (Str::slug(Str::transliterate($label))),
                                // so the deep link would change with the
                                // merchant's language. The component also
                                // WRITES the query string when the merchant
                                // switches tabs (Filament's own tabs.js
                                // updateQueryString(), writing a tab KEY,
                                // not this id) — harmless here: the client
                                // reads its own keys back, and the only
                                // link this page is deep-linked FROM (the
                                // creation wizard's redirect) builds its
                                // URL from the id, which is what the
                                // server matches for a correct first paint.
                                ->persistTabInQueryString('tab')
                                ->tabs([
                                    Tab::make(__('products.tabs.general'))
                                        ->id('general')
                                        ->schema($this->generalTabComponents()),
                                    Tab::make(__('products.tabs.attributes'))
                                        ->id('attributes')
                                        ->schema($this->attributesTabComponents()),
                                    // PRODUCT_MANAGE-gated at ->visible()
                                    // level (not just per-field
                                    // ->disabled()) — this whole tab is
                                    // a write surface (declaring axes),
                                    // unlike the Attributes tab's own
                                    // descriptive-attribute picker,
                                    // which stays visible to any
                                    // PRODUCT_VIEW holder. In practice
                                    // this is defense-in-depth, not the
                                    // real enforcement boundary: every
                                    // staff member who can reach this
                                    // PAGE AT ALL already holds
                                    // PRODUCT_MANAGE (it is this page's
                                    // own editPermission(), enforced by
                                    // EditRecord::authorizeAccess() at
                                    // mount() before the form is ever
                                    // filled — confirmed against its
                                    // installed source, same finding
                                    // already documented on
                                    // syncVariationMedia()'s own
                                    // docblock).
                                    Tab::make(__('products.tabs.axes'))
                                        ->id('axes')
                                        ->schema($this->axesTabComponents())
                                        ->visible(fn (): bool => ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)),
                                    // THE id CreateVariableProduct's own
                                    // getRedirectUrl() sends the merchant
                                    // to, straight after the creation
                                    // wizard — hence the shared constant
                                    // rather than a literal here (see
                                    // ProductResource::VARIATIONS_TAB_ID).
                                    Tab::make(__('products.tabs.variations'))
                                        ->id(ProductResource::VARIATIONS_TAB_ID)
                                        ->schema($this->existingVariationsComponents()),
                                ]),
                        ]),
                    Group::make()
                        ->columnSpan(1)
                        ->schema(ProductResource::sidebarComponents()),
                ]),
        ]);
    }

    /**
     * ProductResource::generalTabComponents() MINUS barcode/
     * is_purchasable — authored fresh here rather than calling that
     * method and stripping two entries back out, matching
     * CreateVariableProduct's own established precedent for its General
     * step exactly (same real field definitions/labels/helpers, field
     * for field). Both excluded fields live on a universal Variation a
     * VARIABLE product genuinely never has — reading/writing them here
     * would crash, not just be irrelevant.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function generalTabComponents(): array
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
            Select::make('brand_id')
                ->label(__('products.fields.brand_id'))
                ->options(fn (): array => BrandModel::pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => ProductResource::brandFieldEnabled()),
            Select::make('product_group_id')
                ->label(__('products.fields.product_group_id'))
                ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => ProductResource::productGroupFieldEnabled())
                ->required(fn (): bool => (bool) (app(SiteSettingsRepository::class)->get('catalog.product_group_required') ?? false)),
        ];
    }

    /**
     * NOT ProductResource::attributesTabComponents() — that method
     * calls descriptiveAttributesPickerComponents() with NO exclusions
     * (correct for SIMPLE/Create, which never have declared variation
     * axes), and a VARIABLE product genuinely can. This product's own
     * declared axis definitions are excluded from the picker's own
     * options here — offering one as a pickable descriptive attribute
     * would make Product::setDescriptiveAttribute()'s real
     * InvalidArgumentException ("...currently declared as a variation
     * axis...") newly reachable from this form otherwise.
     *
     * $product->variationAxes() returns VariationAxis[] — a plain,
     * reindexed LIST (array_values() internally, confirmed against
     * Product's own real source), NOT keyed by attribute_definition_id.
     * array_keys() on it would therefore give sequential integers
     * (0, 1, 2...), not real definition ids — each axis's own real id
     * is read via VariationAxis::attributeDefinitionId() instead.
     */
    private function attributesTabComponents(): array
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);

        $excludedDefinitionIds = array_map(
            fn (VariationAxis $axis): string => $axis->attributeDefinitionId(),
            $product->variationAxes()
        );

        return ProductResource::descriptiveAttributesPickerComponents($excludedDefinitionIds);
    }

    /**
     * Mirrors CreateVariableProduct's own Axes step component-for-
     * component (same field keys/labels/->defaultItems(0)/->live()+
     * ->afterStateUpdated() value-clearing) — see that class's own
     * docblock for the ->defaultItems(0) phantom-row fix this reuses
     * unchanged. The one real difference: attribute_definition_id's
     * own ->options() here EXCLUDE this product's current descriptive-
     * attribute definitions — the mirror image of
     * attributesTabComponents()'s own $excludedDefinitionIds (which
     * excludes axis definitions from the descriptive picker). The same
     * DB UNIQUE(product_id, attribute_definition_id) on
     * catalog_product_attributes that motivates that exclusion applies
     * here too: a definition cannot be both a descriptive attribute AND
     * an axis on the same product at once.
     *
     * MOVING A DEFINITION FROM DESCRIPTIVE TO AXIS IN ONE SUBMIT IS
     * DELIBERATELY NOT SUPPORTED — both pickers are independent
     * Repeaters on independent tabs, each excluding the other's
     * CURRENTLY PERSISTED set (read fresh from the domain Product at
     * render time), not each other's UNSAVED in-progress edits. A
     * merchant who removes a definition from the descriptive picker AND
     * adds it as an axis in the SAME submission would still see it
     * excluded from this tab's own options at render time (since
     * nothing has been saved yet) — the real fix is to remove it from
     * the picker and save first, then declare it as an axis in a
     * separate, later save. This mirrors admin-panel-design.md §13.6's
     * own explicit "deliberate limit" for exactly this reason.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function axesTabComponents(): array
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);

        $excludedDefinitionIds = array_keys($product->descriptiveAttributes());

        return [
            Repeater::make('axes')
                ->hiddenLabel()
                ->defaultItems(0)
                ->addActionLabel(__('products.axes.add_axis'))
                ->reorderable(false)
                ->collapsible()
                ->schema([
                    Select::make('attribute_definition_id')
                        ->label(__('products.axes.attribute_label'))
                        ->options(fn (): array => AttributeDefinitionModel::where('type', AttributeType::SELECT->value)
                            ->whereNotIn('id', $excludedDefinitionIds)
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('value_ids', [])),
                    CheckboxList::make('value_ids')
                        ->label(__('products.axes.values_label'))
                        ->searchable()
                        ->columns(3)
                        ->bulkToggleable()
                        ->required()
                        ->options(fn (Get $get): array => AttributeValueModel::where('attribute_definition_id', $get('attribute_definition_id'))
                            ->pluck('value', 'id')
                            ->all()),
                ])
                ->itemLabel(fn (array $state): ?string => filled($state['attribute_definition_id'] ?? null)
                    ? AttributeDefinitionModel::find($state['attribute_definition_id'])?->name
                    : null),
        ];
    }

    /**
     * A per-row editable display of this product's real, persisted
     * Variations — Step 2a: sku/barcode/is_purchasable/cost/
     * stock_quantity, editable; regular/sale price and the PRODUCT/
     * VARIATION pricing toggle remain out of scope (Step 2b, needs new
     * domain-service work). 'variation_id' stays a Hidden, real
     * persisted id (never recomputed); 'label' stays disabled and
     * ->dehydrated(false) — still purely informational, not writable,
     * not read anywhere on submit.
     *
     * THE REPEATER ITSELF IS NO LONGER ->disabled()/->dehydrated(false)
     * — unlike Step 1, this row's own data now genuinely needs to reach
     * $data on submit for updateProduct() to diff-write. Only 'label'
     * keeps its own ->dehydrated(false); 'variation_id' stays a real,
     * dehydrated Hidden field — updateProduct() below uses it to
     * resolve which real Variation each submitted row belongs to.
     *
     * cost/stock_quantity mirror ProductResource::priceStockTabComponents()'s
     * own real permission-gating shape exactly (per-field ->visible()/
     * ->disabled(), not a single page-level gate) — see that method's
     * own docblock for the full reasoning, including the "->disabled()
     * alone is not the real enforcement, a merely-disabled field's
     * value still dehydrates" gap already found and fixed there; the
     * same real re-check happens server-side in updateProduct() below.
     * ProductResource::staffHasPermission() (public), NOT
     * staffCanForAction() — that trait method is `private static` on
     * AuthorizesViaStaffPermission (confirmed against its own real
     * source), genuinely private to consuming classes once `use`d, so
     * it is not callable from this class at all, unlike from
     * ProductResource's own methods.
     *
     * BULK-SET FIELDS (bulk_cost/bulk_stock_quantity): pure UI
     * convenience, never part of $data themselves
     * (->dehydrated(false), same reasoning as CreateVariableProduct's
     * own activate_all toggle) — ->live()->afterStateUpdated() writes
     * into every row's own cost/stock_quantity via $set() immediately
     * on every change, the same proven live()+Set mechanism already
     * established twice in this codebase (CreateVariableProduct's
     * activate_all, and its own generateVariationPreview()), not a new,
     * unverified Action-based one. Each gated by the SAME permission as
     * its target column — a staff member who cannot edit cost/stock
     * per-row must not be able to bulk-set it either.
     *
     * EDIT_ALL TOGGLE (this revision): the four bulk/mass fields in
     * this block — product_regular_price, product_sale_price,
     * bulk_cost, bulk_stock_quantity — are hidden behind a single
     * "Edit all" Toggle, ->default(false), so the tab opens compact
     * and only expands into the mass-edit controls when the merchant
     * explicitly opts in. This is NOT the §4.5 "mode switch" the PRICE
     * paragraph below deliberately leaves out: turning it on reveals
     * the exact same always-both-levels fields, never a different
     * write path. ->dehydrated(false) on the toggle matches
     * CreateVariableProduct's own activate_all precedent — it is pure
     * UI state, never a real submitted field.
     *
     * product_regular_price/product_sale_price NEED
     * ->dehydratedWhenHidden() — a REAL, CONFIRMED data-loss hazard
     * found while planning this, not assumed: Filament's own
     * isDehydrated() (HasState::isHiddenAndNotDehydratedWhenHidden(),
     * installed v5.8.1 source) defaults dehydratedWhenHidden to FALSE,
     * so a merely-hidden field does NOT dehydrate. Without this,
     * saving while the toggle is off would submit null for both,
     * updateProductLevelPricing()'s own diff would read null as a real
     * change, and writeRegularPriceForProduct()/
     * writeSalePriceForProduct() would DELETE the existing
     * PRODUCT-level PriceListItem on an entirely unrelated save.
     * bulk_cost/bulk_stock_quantity are already ->dehydrated(false),
     * so hiding them carries no such risk.
     *
     * PRICE (Step 2b) — no toggle/checkbox between "one price" and
     * "per-variation pricing": product_regular_price/product_sale_price
     * are always-editable REAL fields (unlike
     * bulk_cost/bulk_stock_quantity, these two dehydrate into $data —
     * they write a real PRODUCT-level PriceListItem every submission,
     * not a per-row broadcast convenience). Each row's own
     * regular_price/sale_price stays empty by default (no
     * VARIATION-level override — pricing-persistence-domain-design.md
     * §4.3's own real fallback then resolves the PRODUCT-level value at
     * read time) or gets filled in for an explicit per-row override.
     * §4.5 explicitly leaves this exact UX choice — mode-switch vs.
     * always-both-levels-available — to the Admin UI, not the domain;
     * this is the simpler of the two.
     *
     * regular_price/sale_price mirror
     * ProductResource::priceStockTabComponents()'s own real price
     * posture exactly: ->disabled() only when lacking PRICE_MANAGE,
     * NEVER ->visible()-gated — ordinary catalog info any PRODUCT_VIEW
     * holder should see, distinct from cost's own two-gate posture
     * above. ->placeholder() takes string|Closure (confirmed against
     * the installed HasPlaceholder trait source) and resolves a
     * closure's named Get $get parameter the same way ->options()/
     * ->afterStateUpdated() already do elsewhere in this codebase — an
     * empty row's placeholder shows the real, currently-resolved
     * PRODUCT-level price, not a generic hint, so the merchant can see
     * what price is actually in effect before typing an override.
     * regular_price_placeholder/sale_price_placeholder are seeded per
     * row in mutateFormDataBeforeFill() (same "seeded once, read via
     * Get(), never itself submitted" posture as 'label') —
     * ->dehydrated(false), since they are a display value only, never
     * a real field this step writes anywhere.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function existingVariationsComponents(): array
    {
        return [
            // Pure UI state — never a real submitted field, exactly like
            // CreateVariableProduct's own activate_all. ->live() so the
            // four mass-edit fields below react to it immediately; the
            // visibility closures read its own 'edit_all' path via Get().
            Toggle::make('edit_all')
                ->label(__('products.wizard.variations.edit_all'))
                ->default(false)
                ->dehydrated(false)
                ->live(),
            TextInput::make('product_regular_price')
                ->label(__('products.fields.regular_price'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->visible(fn (Get $get): bool => (bool) $get('edit_all'))
                // See this method's own docblock: without this, hiding
                // the field drops it from $data entirely and
                // updateProductLevelPricing()'s diff would read the
                // null as "clear the price" on every unrelated save.
                ->dehydratedWhenHidden()
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)),
            TextInput::make('product_sale_price')
                ->label(__('products.fields.sale_price'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->visible(fn (Get $get): bool => (bool) $get('edit_all'))
                ->dehydratedWhenHidden()
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)),
            // Pure display state, seeded once in mutateFormDataBeforeFill()
            // — never itself submitted, same posture as label/the two
            // placeholder fields inside the Repeater below. Read via
            // Get() by the two "clear overrides" toggles' own
            // ->visible()/->helperText() closures.
            Hidden::make('regular_price_override_count')
                ->dehydrated(false),
            Hidden::make('sale_price_override_count')
                ->dehydrated(false),
            // Explicit, opt-in flatten of every VARIATION-level override
            // back to the PRODUCT-level price — §4.3's own structural
            // priority otherwise keeps a variation's own override
            // regardless of what product_regular_price above is changed
            // to. Only shown when there is real work for it to do
            // (regular_price_override_count > 0) — an always-visible,
            // always-a-no-op checkbox would be noise. Written in
            // updateProductLevelPricing() — see that method's own
            // docblock for the write-side and the ordering decision
            // relative to the per-row regular_price/sale_price diff loop.
            Toggle::make('clear_regular_price_overrides')
                ->label(__('products.price_overrides.clear_regular_label'))
                ->helperText(fn (Get $get): string => __('products.price_overrides.clear_regular_help', [
                    'count' => (int) $get('regular_price_override_count'),
                ]))
                ->default(false)
                ->visible(fn (Get $get): bool => ((int) $get('regular_price_override_count')) > 0)
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)),
            Toggle::make('clear_sale_price_overrides')
                ->label(__('products.price_overrides.clear_sale_label'))
                ->helperText(fn (Get $get): string => __('products.price_overrides.clear_sale_help', [
                    'count' => (int) $get('sale_price_override_count'),
                ]))
                ->default(false)
                ->visible(fn (Get $get): bool => ((int) $get('sale_price_override_count')) > 0)
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)),
            TextInput::make('bulk_cost')
                ->label(__('products.wizard.variations.bulk_cost'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->dehydrated(false)
                // AND, not OR — both the merchant's own edit_all opt-in
                // AND the real COST_VIEW permission must hold for this
                // field to appear; a staff member without COST_VIEW must
                // never see it even with edit_all on.
                ->visible(fn (Get $get): bool => (bool) $get('edit_all') && ProductResource::staffHasPermission(Permission::COST_VIEW))
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::COST_MANAGE))
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    foreach (array_keys($get('existing_variations') ?? []) as $key) {
                        $set("existing_variations.{$key}.cost", $state);
                    }
                }),
            TextInput::make('bulk_stock_quantity')
                ->label(__('products.wizard.variations.bulk_stock_quantity'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->dehydrated(false)
                ->visible(fn (Get $get): bool => (bool) $get('edit_all'))
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE))
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    foreach (array_keys($get('existing_variations') ?? []) as $key) {
                        $set("existing_variations.{$key}.stock_quantity", $state);
                    }
                }),
            // Section, not a bare Repeater — "Generate missing
            // variations" (generateMissingVariationsAction()'s own
            // docblock) needs a REAL Filament header-action mechanism
            // to attach to. CONFIRMED AGAINST THE INSTALLED SOURCE,
            // NOT ASSUMED: Repeater does NOT implement
            // Filament\Schemas\Components\Contracts\HasHeaderActions —
            // only Filament\Schemas\Components\Section does (via
            // Concerns\HasHeaderActions). A literal "header action on
            // the Repeater itself" is not a real, reachable Filament
            // mechanism — wrapping the Repeater in a Section is the
            // faithful adaptation that keeps the exact same visual
            // result (the button sits directly above the variations
            // list) without inventing a different UX.
            Section::make()
                ->headerActions([$this->generateMissingVariationsAction()])
                ->schema([
                    Repeater::make('existing_variations')
                        ->hiddenLabel()
                        ->addable(false)
                // Re-enabled (was ->deletable(false) through Step 1) —
                // this IS the real archive mechanism now: Repeater::
                // getDeleteAction()'s own real ->action() closure
                // (confirmed against its installed source) simply
                // unset()s the row from the Repeater's own live raw
                // state and re-renders — it never touches the domain
                // layer itself. That is exactly the detection signal
                // updateProduct() below needs: a variation absent from
                // THIS submission's own existing_variations is the one
                // and only trigger for archiving it server-side (see
                // that method's own docblock) — never an in-browser-
                // only deletion. No hasFormWrapper()/canSubmitForm()
                // workaround needed here, unlike the Save button:
                // confirmed against CanSubmitForm's installed source
                // that isLivewireClickHandlerEnabled() only ever
                // returns false for an action that called ->submit(),
                // and getDeleteAction() never does — it is already a
                // genuine ->action() click handler.
                ->deletable()
                ->deleteAction(function (Action $action): Action {
                    return $action
                        // Honest wording — this is an archive, not a
                        // destructive delete (CLAUDE.md rule 4: no hard
                        // delete of anything another domain might
                        // reference by id). Filament's own default
                        // "Delete" label/icon would overstate what
                        // actually happens.
                        ->label(__('products.variation_archive.button_label'))
                        ->requiresConfirmation()
                        ->modalHeading(__('products.variation_archive.confirm_heading'))
                        // array $arguments ('item' => the real Repeater
                        // item key — confirmed against
                        // HasMountableArguments::__invoke(), which is
                        // how Repeater's own blade-embedded view binds
                        // $deleteAction(['item' => $itemKey]) per row)
                        // + Repeater $component (named 'component',
                        // confirmed bound via HasActions::
                        // prepareAction() -> ->schemaComponent($this)
                        // at cacheActions() time, where $this is THIS
                        // Repeater) — together the same real, working
                        // mechanism itemLabel() already uses
                        // (->getChildSchema($key)->getRawState()), NOT
                        // Get $get (which — confirmed the identical way
                        // itemLabel()'s own docblock already
                        // documents — would resolve relative to the
                        // REPEATER's own state path, not this specific
                        // item's, and silently return blank).
                        ->modalDescription(function (array $arguments, Repeater $component): string {
                            $itemKey = $arguments['item'] ?? null;
                            $label = $itemKey !== null
                                ? ($component->getChildSchema($itemKey)?->getRawState()['label'] ?? '')
                                : '';

                            return __('products.variation_archive.confirm_description', ['label' => $label]);
                        })
                        ->modalSubmitActionLabel(__('products.variation_archive.confirm_submit'))
                        ->modalCancelActionLabel(__('products.variation_archive.confirm_cancel'));
                })
                // Genuinely drag-and-drop reorderable — Filament's own
                // Repeater default (confirmed against its installed
                // source: $isReorderable = true,
                // $isReorderableWithDragAndDrop = true), which this page
                // deliberately disabled while variations had no persisted
                // order at all. The submitted row ORDER is the merchant's
                // intent: it is now written through
                // applyVariationRowOrder() below (array index ->
                // catalog_variations.sort_order, exactly how the media
                // pivots' own reorder already works), so the order
                // survives a save and a reload.
                ->reorderable()
                ->collapsible()
                // NEITHER Get $get NOR array $state — both tried and
                // BOTH confirmed broken by a real, failing test before
                // landing on this:
                //
                // Get $get: Repeater::getItemLabel() calls
                // $this->evaluate($this->itemLabel, [...]) where $this
                // is the REPEATER component itself, so a Get $get
                // parameter resolves via Component::makeGetUtility()
                // bound to the REPEATER's own statePath, not this
                // specific item's — it searches for a sibling field at
                // "existing_variations.label", not
                // "existing_variations.{itemKey}.label", and silently
                // returns null for every row.
                //
                // array $state (the 'state' named injection,
                // $container->getStateSnapshot()): this DOES scope to
                // the right item, but getStateSnapshot() itself calls
                // dehydrateState() before returning (confirmed against
                // its installed source), which STRIPS any component
                // whose own isDehydrated() is false — exactly 'label'
                // here (->dehydrated(false), deliberately never
                // submitted). $state['label'] is therefore always
                // absent, not merely falsy — reproduced by a real
                // failing test asserting the real "Color: Black" label
                // (got null instead) before this fix.
                //
                // Schema $container (the SAME object as 'item'/
                // 'state' were built from, named 'container' to match
                // getItemLabel()'s own named injection): its own
                // ->getRawState() (HasState, confirmed used by Schema)
                // reads directly off the live Livewire state via
                // statePath, with NO dehydration filtering at all —
                // 'label' is still genuinely present in the FORM's own
                // live state (dehydrated(false) only ever affects what
                // reaches $data on SUBMIT, never what Livewire tracks
                // for display). Confirmed correct by the same
                // real test, now passing.
                ->itemLabel(fn (Schema $container): ?string => $container->getRawState()['label'] ?? null)
                // Expanded by default (->collapsed() left unset,
                // per-item ->isCollapsed() defaults to false) — a
                // merchant editing THIS product's variations right
                // after opening the tab should see every row's fields
                // immediately, same as before this revision; collapsing
                // is now available (and each row's own label is shown
                // while collapsed) but never forced.
                ->schema([
                    Hidden::make('variation_id'),
                    // Purely a layout grouping — label/is_purchasable's
                    // own ->disabled()/->dehydrated(false)/permission
                    // config is completely unchanged, only their
                    // position moves. 3:1 read well in practice (label
                    // text is the longer of the two, the toggle needs
                    // only enough room for its switch).
                    Grid::make(4)
                        ->schema([
                            TextInput::make('label')
                                ->label(__('products.wizard.variations.combination_label'))
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(3),
                            Toggle::make('is_purchasable')
                                ->label(__('products.fields.is_purchasable'))
                                ->columnSpan(1),
                        ]),
                    TextInput::make('sku')
                        ->label(__('products.wizard.variations.sku_label')),
                    TextInput::make('barcode')
                        ->label(__('products.fields.barcode')),
                    TextInput::make('cost')
                        ->label(__('products.fields.cost'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->visible(fn (): bool => ProductResource::staffHasPermission(Permission::COST_VIEW))
                        ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::COST_MANAGE)),
                    TextInput::make('stock_quantity')
                        ->label(__('products.fields.stock_quantity'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)),
                    TextInput::make('regular_price')
                        ->label(__('products.fields.regular_price'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE))
                        ->placeholder(fn (Get $get): ?string => $get('regular_price_placeholder')),
                    TextInput::make('sale_price')
                        ->label(__('products.fields.sale_price'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRICE_MANAGE))
                        ->placeholder(fn (Get $get): ?string => $get('sale_price_placeholder')),
                    Hidden::make('regular_price_placeholder')
                        ->dehydrated(false),
                    Hidden::make('sale_price_placeholder')
                        ->dehydrated(false),
                    // Per-variation photos — a SEPARATE MediaType::IMAGE
                    // collection from the product-level main_photo/
                    // gallery_photos in the sidebar (ProductResource::
                    // sidebarComponents()), backed by
                    // catalog_variation_media (VariationMedia), not
                    // catalog_product_media. VariationMedia has no
                    // type/autoplay concept at all (confirmed against
                    // its own real constructor) — image-only, full
                    // stop, unlike the product-level video field.
                    // Reuses the SAME real config-driven maxSize()/
                    // disk()/saveUploadedFileUsing() as
                    // ProductResource::galleryPhotoComponents() — see
                    // that method's own docblock for why there is
                    // deliberately no ->maxFiles() here either: the
                    // real enforcement point is
                    // VariationMediaCountGuard inside
                    // syncVariationMedia() below, whose own
                    // MediaLimitExceededException message this field
                    // must not shadow with a generic Filament one.
                    FileUpload::make('variation_photos')
                        ->label(__('products.fields.variation_photos'))
                        ->multiple()
                        ->reorderable()
                        ->image()
                        ->panelLayout('grid')
                        ->placeholder(ProductResource::mediaUploadPlaceholder('services.media.max_image_size_kb', 10240))
                        ->maxSize((int) config('services.media.max_image_size_kb', 10240))
                        ->disk(config('services.media.default_disk', 'public'))
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                            return app(MediaStorageAdapter::class)
                                ->store($file->get(), $file->getClientOriginalName())
                                ->path;
                        })
                        // Same base PRODUCT_MANAGE permission every
                        // other per-row field in this class already
                        // uses (sku/barcode/is_purchasable/
                        // stock_quantity) — no dedicated media
                        // permission exists for variation photos.
                        ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)),
                ]),
                ]),
            $this->newVariationComponents(),
            $this->archivedVariationsComponents(),
        ];
    }

    /**
     * "Add variation" — B2's own explicit, one-row-at-a-time
     * alternative to "Generate missing variations": a merchant who
     * only wants exactly ONE specific combination, not every missing
     * one. Rows never come pre-filled (unlike CreateVariableProduct's
     * own 'variations' Repeater, which is populated entirely by
     * generateVariationPreview()) — ->addable() so a merchant explicitly
     * adds a row, one per intended new variation.
     *
     * One Select PER CURRENTLY DECLARED AXIS, keyed
     * "axis_value_{definitionId}" — built via ->schema(fn (): array =>
     * ...) (confirmed supported: Filament\Schemas\Components\Concerns\
     * HasChildComponents::schema() accepts array|Schema|Closure). The
     * axis set is read once per render (this product's declared axes
     * do not change mid-render — only a full Save can change them, and
     * that reloads the whole page), so a closure re-evaluated on every
     * Livewire request update is correct without needing to react to
     * any live() state.
     *
     * Combination-building/write-side lives in addNewVariationRows()
     * (called from updateProduct() — this Repeater's own rows are
     * submitted and processed as part of the normal Save flow, NOT a
     * separate Action, unlike "Generate missing variations"/Restore).
     */
    private function newVariationComponents(): Section
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);
        $axes = $product->variationAxes();

        return Section::make(__('products.new_variations.section_label'))
            ->schema([
                Repeater::make('new_variations')
                    ->hiddenLabel()
                    ->defaultItems(0)
                    ->addActionLabel(__('products.new_variations.add_variation'))
                    ->deletable()
                    ->reorderable(false)
                    ->collapsible()
                    ->helperText(__('products.new_variations.combination_help'))
                    ->schema(function () use ($axes): array {
                        $components = [];

                        foreach ($axes as $axis) {
                            $definitionId = $axis->attributeDefinitionId();
                            $definitionName = AttributeDefinitionModel::find($definitionId)?->name ?? $axis->attributeDefinitionCode();

                            $components[] = Select::make("axis_value_{$definitionId}")
                                ->label($definitionName)
                                // A REAL, CONFIRMED GAP FOUND VIA A
                                // FAILING TEST, NOT ASSUMED SAFE: a bare
                                // ->options($axis->allowedValueIds())
                                // snapshot (this product's PERSISTED
                                // axes at render time) makes Filament's
                                // own implicit "in:" validation reject a
                                // value the merchant just enabled on the
                                // SAME Axes tab in the SAME submission —
                                // "The selected {label} is invalid.",
                                // server-side, before updateProduct() is
                                // ever reached. Merged in here via an
                                // ABSOLUTE Get() path ('/data.axes' —
                                // confirmed working against
                                // HasState::resolveRelativeStatePath()'s
                                // own installed source: a leading '/'
                                // forces isAbsolute=true, read from the
                                // form root regardless of which
                                // Repeater/Section this Select is
                                // nested under) so a value enabled on
                                // the Axes tab in THIS SAME save is
                                // immediately usable here too, not only
                                // after a page reload — this is exactly
                                // what proves the real save-time
                                // ordering (axes declared before new
                                // variations are validated).
                                ->options(function (Get $get) use ($axis, $definitionId): array {
                                    $valueIds = $axis->allowedValueIds();

                                    foreach ($get('/data.axes') ?? [] as $axisRow) {
                                        if ((string) ($axisRow['attribute_definition_id'] ?? '') === $definitionId) {
                                            $valueIds = array_unique(array_merge($valueIds, $axisRow['value_ids'] ?? []));
                                        }
                                    }

                                    return AttributeValueModel::whereIn('id', $valueIds)
                                        ->pluck('value', 'id')
                                        ->all();
                                })
                                ->required()
                                ->searchable();
                        }

                        $components[] = TextInput::make('sku')
                            ->label(__('products.new_variations.sku_label'))
                            ->required();
                        $components[] = TextInput::make('barcode')
                            ->label(__('products.fields.barcode'));
                        $components[] = Toggle::make('is_active')
                            ->label(__('products.wizard.variations.active_label'))
                            ->default(false);

                        return $components;
                    })
                    ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)),
            ]);
    }

    /**
     * "C" — the archived-variations restore list. A SEPARATE Repeater
     * from existing_variations (never the same one — an archived row
     * has nothing editable in it), display-only: every field
     * ->disabled()->dehydrated(false), 'variation_id' a plain Hidden
     * (still dehydrated — its own value is never read at Save time
     * either, since this whole Repeater carries no real write path of
     * its own, but leaving it dehydrated is harmless and keeps the
     * field shape consistent with the others in this class). The
     * per-row Restore Action uses ->extraItemActions() (Concerns\
     * HasExtraItemActions, confirmed present on Repeater in the
     * installed v5.8.1), NOT ->deleteAction() — restoring is not a
     * deletion, and this Repeater's own ->deletable(false)/->addable(false)
     * mean the merchant has no OTHER way to add/remove a row here at
     * all; the only interaction is Restore.
     *
     * ->requiresConfirmation()/->modalDescription() reads the row's
     * own real combination label via the SAME confirmed-working
     * mechanism existingVariationsComponents()'s own ->deleteAction()
     * already established: array $arguments['item'] (the real
     * Repeater item key, bound via HasMountableArguments::__invoke() —
     * confirmed the Repeater's own view calls
     * $action(['item' => $itemKey]) for BOTH deleteAction() and
     * extraItemActions() identically) + Repeater $component (named
     * 'component', bound via HasActions::prepareAction() at
     * cacheActions()/cacheExtraItemActions() time) ->
     * getChildSchema($itemKey)->getRawState() — NOT Get $get, which
     * would resolve relative to the REPEATER's own state path, not
     * this specific item's (identical documented gap as itemLabel()'s
     * own docblock elsewhere in this class).
     *
     * The actual restore WORK happens in
     * $this->restoreArchivedVariationById() — resolved via named
     * $livewire injection (ProductResource::duplicateAction()'s own
     * confirmed-working precedent for reaching Livewire's own page
     * instance from inside an Action closure), kept as a public method
     * rather than inlined in this closure so it stays independently
     * readable/testable. See that method's own docblock for why it
     * refreshes via $this->form->fill($this->data) rather than a full
     * fillForm().
     */
    private function archivedVariationsComponents(): Section
    {
        return Section::make(__('products.variation_restore.section_label'))
            // Hidden entirely when there is nothing to restore — same
            // precedent as clear_regular_price_overrides/
            // clear_sale_price_overrides above (an always-visible,
            // always-a-no-op control is noise). 'archived_variations'
            // has no write path of its own (only ever WRITTEN by
            // mutateFormDataBeforeFill()/refreshVariationRows(), never
            // read in updateProduct()), so hiding this Section cannot
            // drop any value the Save flow depends on — the same
            // "hidden components are excluded from $data, safe only
            // when nothing on submit reads that key" reasoning already
            // documented on this class's own price-field docblocks.
            ->visible(fn (): bool => $this->hasArchivedVariations())
            ->schema([
                Repeater::make('archived_variations')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->extraItemActions([
                        Action::make('restore_variation')
                            ->label(__('products.variation_restore.button_label'))
                            ->requiresConfirmation()
                            ->modalHeading(__('products.variation_restore.confirm_heading'))
                            ->modalDescription(function (array $arguments, Repeater $component): string {
                                $itemKey = $arguments['item'] ?? null;
                                $label = $itemKey !== null
                                    ? ($component->getChildSchema($itemKey)?->getRawState()['label'] ?? '')
                                    : '';

                                return __('products.variation_restore.confirm_description', ['label' => $label]);
                            })
                            ->modalSubmitActionLabel(__('products.variation_restore.confirm_submit'))
                            ->modalCancelActionLabel(__('products.variation_restore.confirm_cancel'))
                            ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE))
                            ->action(function (array $arguments, Repeater $component, $livewire): void {
                                $itemKey = $arguments['item'] ?? null;
                                $variationId = $itemKey !== null
                                    ? ($component->getChildSchema($itemKey)?->getRawState()['variation_id'] ?? null)
                                    : null;

                                if ($variationId === null) {
                                    return;
                                }

                                $livewire->restoreArchivedVariationById((string) $variationId);
                            }),
                    ])
                    ->schema([
                        Hidden::make('variation_id'),
                        TextInput::make('label')
                            ->label(__('products.wizard.variations.combination_label'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('sku')
                            ->label(__('products.wizard.variations.sku_label'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('barcode')
                            ->label(__('products.fields.barcode'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    /**
     * "Generate missing variations" — B1's own header Action, attached
     * via Section::headerActions() (see existingVariationsComponents()'s
     * own comment for why a Section wraps the existing_variations
     * Repeater specifically to host this). ->disabled() whenever this
     * product currently has zero declared axes — nothing to generate.
     * The confirmation text is explicit that a still-enabled archived
     * combination is RESTORED with its ORIGINAL sku, not recreated —
     * Product::addStandardVariation()'s own real §3.9 revival-by-
     * signature behavior, not a new rule invented for this button.
     *
     * The real work is generateMissingVariations() — resolved via
     * named $livewire injection, same precedent as the Restore action.
     */
    private function generateMissingVariationsAction(): Action
    {
        return Action::make('generate_missing_variations')
            ->label(__('products.variations_generate.button_label'))
            ->requiresConfirmation()
            ->modalHeading(__('products.variations_generate.confirm_heading'))
            ->modalDescription(__('products.variations_generate.confirm_description'))
            ->disabled(fn (): bool => ! $this->hasDeclaredAxes() || ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE))
            ->action(fn ($livewire) => $livewire->generateMissingVariations());
    }

    /** A real query, not a loaded-collection count — same reasoning as baseSkuIsChanging()'s own identical posture. */
    private function hasDeclaredAxes(): bool
    {
        return DB::table('catalog_product_attributes')
            ->where('product_id', $this->record->id)
            ->where('is_variation_axis', true)
            ->exists();
    }

    /**
     * Gates archivedVariationsComponents()'s own ->visible() — same
     * real-query posture as hasDeclaredAxes() above, not a loaded-
     * collection count. Scoped identically to archivedVariationRows()'s
     * own filter: STANDARD type (a UNIVERSAL variation is never
     * restorable/never shown here) and ARCHIVED status, compared
     * against the enums' own ->value, never a literal string.
     */
    private function hasArchivedVariations(): bool
    {
        return DB::table('catalog_variations')
            ->where('product_id', $this->record->id)
            ->where('type', VariationType::STANDARD->value)
            ->where('status', VariationStatus::ARCHIVED->value)
            // Same soft-delete blind spot as the raw 'exists' rule
            // VariationController::restore() had (FIX 4): this is a raw
            // DB::table() query, not scoped by VariationModel's own
            // SoftDeletes global scope. Without this, a soft-deleted
            // archived row would count here even though
            // archivedVariationRows() (reading from the domain
            // aggregate, which IS scope-aware) would show none — the
            // Section would render visible with an empty list.
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Mirrors EditProduct::mutateFormDataBeforeFill()'s own seeding for
     * categories/tags/descriptive_attributes_picker/main_photo/
     * gallery_photos/video/video_autoplay exactly (same logic, this
     * product's own id) — see that method's own docblock for the
     * reasoning behind each. findByIdWithVariations() (not findById()):
     * the real $variations array is what existing_variations below is
     * built from.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $productId = (string) $this->record->id;

        $data['categories'] = array_map(
            fn ($c) => (string) $c->categoryId(),
            app(ProductCategoryRepository::class)->findByProductId($productId)
        );

        $data['tags'] = array_map(
            fn ($t) => (string) $t->tagId(),
            app(ProductTagRepository::class)->findByProductId($productId)
        );

        $product = app(ProductRepository::class)->findByIdWithVariations($productId);

        $data['descriptive_attributes_picker'] = ProductResource::seedDescriptiveAttributesPickerRows($product);

        $data['main_photo'] = null;
        $data['gallery_photos'] = [];
        $data['video'] = null;
        $data['video_autoplay'] = false;

        foreach (app(ProductMediaRepository::class)->findByProductId($productId) as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());

            if ($asset === null) {
                continue;
            }

            if ($asset->type === MediaType::IMAGE->value) {
                if ($data['main_photo'] === null) {
                    $data['main_photo'] = $asset->path;
                } else {
                    $data['gallery_photos'][] = $asset->path;
                }
            } elseif ($asset->type === MediaType::VIDEO->value) {
                $data['video'] = $asset->path;
                $data['video_autoplay'] = $pivot->autoplay();
            }
        }

        $pricingAndStock = app(ProductPricingAndStock::class);

        $productRegularPrice = $pricingAndStock->regularPriceDisplayForProduct($productId);
        $productSalePrice = $pricingAndStock->salePriceDisplayForProduct($productId);
        $data['product_regular_price'] = $productRegularPrice;
        $data['product_sale_price'] = $productSalePrice;

        // Seeded ONCE here, same "read via Get(), dehydrated(false)"
        // posture as regular_price_placeholder/sale_price_placeholder
        // below — a real count of this product's own variations that
        // currently carry a VARIATION-level override, used only to
        // decide whether the "clear overrides" toggle is worth showing
        // at all and to interpolate a real number into its own helper
        // text (see existingVariationsComponents()'s own docblock).
        $regularOverrideCount = 0;
        $saleOverrideCount = 0;
        foreach ($product->variations() as $variation) {
            if ($pricingAndStock->regularPriceDisplay($variation->id()) !== null) {
                $regularOverrideCount++;
            }
            if ($pricingAndStock->salePriceDisplay($variation->id()) !== null) {
                $saleOverrideCount++;
            }
        }
        $data['regular_price_override_count'] = $regularOverrideCount;
        $data['sale_price_override_count'] = $saleOverrideCount;

        // Seeded from the domain Product's own real variationAxes() so
        // an UNTOUCHED Axes tab resubmits the identical set — which
        // the new directional guard (catalog-domain-design.md §3.17)
        // now allows as a genuine no-op, unlike the old blanket
        // refusal. allowedValueIds() already returns string[] (see
        // that method's own real source), matching value_ids' own
        // multiple-Select shape exactly.
        $data['axes'] = array_map(
            fn (VariationAxis $axis): array => [
                'attribute_definition_id' => $axis->attributeDefinitionId(),
                'value_ids' => $axis->allowedValueIds(),
            ],
            $product->variationAxes()
        );

        // Both use the SAME two shared row-shape builders this class's
        // own restoreArchivedVariationById()/generateMissingVariations()
        // reuse for their own partial refresh (see those methods' own
        // docblocks for why — NOT a full fillForm() there) — exactly
        // one place each row shape is built, not two.
        $data['existing_variations'] = $this->existingVariationRows($product);
        $data['archived_variations'] = $this->archivedVariationRows($product);

        // 'new_variations' has no seed — it is a page ONLY the
        // merchant fills in per save, never populated from persisted
        // state (there is nothing persisted to seed it FROM: every row
        // here becomes a brand-new Variation the moment it's saved).
        $data['new_variations'] = [];

        return $data;
    }

    /**
     * The real row shape for 'existing_variations' — extracted so
     * mutateFormDataBeforeFill() (a full page load) and
     * generateMissingVariations()/restoreArchivedVariationById() (an
     * independent side-action's own partial refresh — see those
     * methods' own docblocks) share exactly one place this shape is
     * built, never two. ARCHIVED variations are deliberately excluded
     * — see the historical comment this replaced (git blame) for the
     * original reasoning, unchanged: an archived row has nothing to
     * edit in this Repeater, so it must not silently reappear here.
     * array_values() after array_filter(): a Repeater's own row keys
     * come from array iteration, not from variation_id, so a gap left
     * by array_filter() must not leak through as a non-sequential key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function existingVariationRows(Product $product): array
    {
        $pricingAndStock = app(ProductPricingAndStock::class);
        $variationMediaRepository = app(VariationMediaRepository::class);

        $productRegularPrice = $pricingAndStock->regularPriceDisplayForProduct($product->id());
        $productSalePrice = $pricingAndStock->salePriceDisplayForProduct($product->id());

        return array_values(array_map(
            fn (Variation $variation): array => [
                'variation_id' => $variation->id(),
                'label' => $this->variationLabel($variation),
                'sku' => $variation->sku(),
                'barcode' => $variation->barcode(),
                'is_purchasable' => $variation->isPurchasable(),
                'cost' => $pricingAndStock->costDisplay($variation->id()),
                'stock_quantity' => $pricingAndStock->stockQuantity($variation->id()),
                'regular_price' => $pricingAndStock->regularPriceDisplay($variation->id()),
                'sale_price' => $pricingAndStock->salePriceDisplay($variation->id()),
                'regular_price_placeholder' => $productRegularPrice,
                'sale_price_placeholder' => $productSalePrice,
                'variation_photos' => $this->variationPhotoPaths($variationMediaRepository, $variation->id()),
            ],
            array_filter(
                $product->variations(),
                fn (Variation $variation): bool => $variation->status() !== VariationStatus::ARCHIVED
            )
        ));
    }

    /**
     * The real row shape for 'archived_variations' — display-only
     * (see archivedVariationsComponents()'s own docblock for why every
     * field in that Repeater is ->disabled()->dehydrated(false)).
     * Scoped to STANDARD variations only: a UNIVERSAL variation is
     * never customer-selectable and Product::restoreArchivedVariation()
     * itself refuses one outright, so it must never appear in a list
     * whose entire purpose is offering a Restore button. Same two
     * callers as existingVariationRows() above.
     *
     * @return array<int, array<string, mixed>>
     */
    private function archivedVariationRows(Product $product): array
    {
        return array_values(array_map(
            fn (Variation $variation): array => [
                'variation_id' => $variation->id(),
                'label' => $this->variationLabel($variation),
                'sku' => $variation->sku(),
                'barcode' => $variation->barcode(),
            ],
            array_filter(
                $product->variations(),
                fn (Variation $variation): bool => $variation->status() === VariationStatus::ARCHIVED
                    && $variation->type() === VariationType::STANDARD
            )
        ));
    }

    /**
     * Same "{DefinitionName}: {ValueName}, ..." building approach
     * CreateVariableProduct::generateVariationPreview() already
     * established — but reading REAL persisted attributeAssignments()
     * off a real Variation, not a throwaway generator's output, so
     * there is no axis-declaration object to read names off; each
     * definition/value id is looked up directly instead.
     */
    private function variationLabel(Variation $variation): string
    {
        $parts = [];

        foreach ($variation->attributeAssignments() as $definitionId => $valueId) {
            $definitionName = AttributeDefinitionModel::find($definitionId)?->name ?? (string) $definitionId;
            $valueName = AttributeValueModel::find($valueId)?->value ?? (string) $valueId;
            $parts[] = "{$definitionName}: {$valueName}";
        }

        return implode(', ', $parts);
    }

    /**
     * Real, persisted paths for one variation's own photos, ordered by
     * VariationMediaRepository::findByVariationId()'s own real
     * sort_order ASC — a pivot whose asset is somehow gone is silently
     * skipped, same defensive posture already established for the
     * PRODUCT-level media loop above.
     *
     * @return array<int, string>
     */
    private function variationPhotoPaths(VariationMediaRepository $variationMediaRepository, string $variationId): array
    {
        $paths = [];

        foreach ($variationMediaRepository->findByVariationId($variationId) as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());

            if ($asset === null) {
                continue;
            }

            $paths[] = $asset->path;
        }

        return $paths;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => $this->updateProduct($record, $data));
    }

    /**
     * Filament's own real hook point — EditRecord::save() calls
     * $this->callHook('afterSave') AFTER handleRecordUpdate() completes
     * and BEFORE the — confirmed absent, see class docblock — redirect
     * (confirmed against its installed source); CanCallHooks::
     * callHook() invokes $this->afterSave() by plain method name, no
     * parameters of its own, which is why updateProduct() captures
     * $submittedVariationSkus/$finalVariationSkus on the instance
     * instead of returning them.
     *
     * ONE comparison, not two separate mechanisms — a row differing
     * here can only be explained by either the sku-collision-retry
     * suffixing mechanism or the base_sku cascade (both already
     * resolved by the time this runs), so a single itemized
     * notification covers both.
     *
     * $this->record IS STALE HERE without the explicit reassignment —
     * EditRecord::save() never writes handleRecordUpdate()'s own return
     * value back into $this->record (confirmed against its installed
     * source: the return value is discarded at the call site), and
     * this page's own getRedirectUrl() is the framework default (no
     * panel-wide redirect is configured — confirmed no redirect means
     * no automatic form refresh either). $this->fillForm() re-runs
     * mutateFormDataBeforeFill() against $this->getRecord() (confirmed
     * against fillFormWithDataAndCallHooks()'s installed source) —
     * reassigning $this->record first is what makes that re-seed
     * genuinely reflect the just-saved state.
     */
    protected function afterSave(): void
    {
        $this->record = ProductModel::find($this->record->id);

        $changedRows = [];

        foreach ($this->submittedVariationSkus as $variationId => $submittedSku) {
            $finalSku = $this->finalVariationSkus[$variationId] ?? null;

            if ($finalSku === null || $finalSku === $submittedSku) {
                continue;
            }

            $changedRows[] = __('products.sku_adjustment.item', [
                'submitted' => $submittedSku,
                'final' => $finalSku,
            ]);
        }

        if ($changedRows !== []) {
            Notification::make()
                ->title(__('products.sku_adjustment.notification_title'))
                ->body(implode("\n", $changedRows))
                ->warning()
                ->send();
        }

        // UNCONDITIONAL, not only when a sku actually differed — a
        // REAL BUG found after the fact, not just the sku case this
        // method was originally built for: regular_price_placeholder/
        // sale_price_placeholder (and every other value seeded fresh
        // by mutateFormDataBeforeFill() — categories/tags/media/
        // descriptive attributes/product-level price) go stale after
        // ANY successful save that happens not to touch a sku, since
        // nothing else ever re-runs mutateFormDataBeforeFill() on this
        // page (no redirect — see class docblock). A merchant who only
        // changes product_regular_price, for instance, would otherwise
        // keep seeing the OLD product-level price as every empty row's
        // placeholder until the next full page load.
        $this->fillForm();
    }

    /**
     * "Generate missing variations" (B1) — the real work behind
     * generateMissingVariationsAction()'s own header Action, resolved
     * via that Action's named $livewire injection (ProductResource::
     * duplicateAction()'s own confirmed-working precedent). A real,
     * independent side-action, NOT part of the normal Save submission
     * — it reloads the aggregate, runs the generator, and saves
     * immediately on click, exactly like restoreArchivedVariationById()
     * below.
     *
     * VariationCombinationGenerator::generate() and
     * CatalogSkuGeneratorServiceProvider::variationSkuStrategy() are
     * both reused completely as-is (this task's own explicit
     * instruction) — no cartesian-product logic is reimplemented here.
     *
     * TWO REAL, SEPARATE COUNTS, not one combined number:
     * generate()'s own real source confirms $created only ever
     * contains a row with a null id() (a genuinely NEW Variation,
     * never yet persisted) or a non-null id() (which can ONLY be a
     * just-revived ARCHIVED variation here — generate() itself catches
     * DuplicateVariationCombinationException and skips it, so an
     * already-LIVE variation's combination can never reach $created at
     * all). Checked BEFORE save() below, while a genuinely-new
     * Variation's id() is still null.
     */
    public function generateMissingVariations(): void
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);

        $valuesByAxis = [];
        foreach ($product->variationAxes() as $axis) {
            $valuesByAxis[$axis->attributeDefinitionId()] = $axis->allowedValueIds();
        }

        $variations = (new VariationCombinationGenerator())->generate(
            $product,
            $valuesByAxis,
            CatalogSkuGeneratorServiceProvider::variationSkuStrategy($product)
        );

        $createdCount = 0;
        $restoredCount = 0;

        foreach ($variations as $variation) {
            if ($variation->id() === null) {
                $createdCount++;
            } else {
                $restoredCount++;
            }
        }

        app(ProductRepository::class)->save($product);

        Notification::make()
            ->title(__('products.variations_generate.notification_title'))
            ->body(__('products.variations_generate.notification_body', [
                'created' => $createdCount,
                'restored' => $restoredCount,
            ]))
            ->success()
            ->send();

        // Same partial-refresh reasoning as restoreArchivedVariationById()
        // below — freshly generated/restored rows must actually appear
        // without discarding any other unsaved edit in progress
        // elsewhere on the page. Not explicitly required by this
        // task's own B1 wording, but a necessary consequence of it:
        // newly generated rows genuinely need to show up somehow, and
        // the SAME careful mechanism C already established for exactly
        // this reason is the correct one to reuse here too, not a full
        // fillForm().
        $this->refreshVariationRows();
    }

    /**
     * The real work behind archivedVariationsComponents()'s own
     * per-row Restore Action — see that method's own docblock for the
     * full mechanism (named $livewire injection, why it's a public
     * method). Reloads the aggregate fresh (this page's own $product
     * from a normal Save is not involved at all — this is a
     * completely independent action), locates the variation by id,
     * calls the real Product::restoreArchivedVariation(), and saves
     * through the repository (EloquentProductRepository::save() wraps
     * its own DB::transaction() internally — confirmed against its
     * installed source — so no extra transaction wrapping is needed
     * here).
     *
     * On VariationNotRestorableException: a danger notification
     * carrying the exception's OWN message (never a generic string),
     * no data refresh at all (nothing changed — the guard fired before
     * $product->restoreArchivedVariation() mutated anything, and this
     * method returns immediately after sending the notification).
     *
     * REFRESH, NOT fillForm() — confirmed against
     * EditRecord::fillFormWithDataAndCallHooks()'s own installed
     * source: $this->fillForm() re-runs mutateFormDataBeforeFill()
     * against EVERY field, then $this->form->fill($data) with that
     * entirely fresh array. That would silently discard any OTHER
     * unsaved edit the merchant has in progress elsewhere on the page
     * (name, base_sku, a not-yet-saved row edit) — Restore is an
     * independent side-action, not a full-form Save, and must not have
     * that side effect. Recomputing ONLY $this->data['existing_variations']/
     * $this->data['archived_variations'] via the two shared row-shape
     * builders (mutateFormDataBeforeFill()'s own callers), then calling
     * $this->form->fill($this->data) — WITHOUT going through
     * mutateFormDataBeforeFill() again — pushes the CURRENT $this->data
     * (now correct for just these two keys, everything else exactly as
     * the merchant last left it) back through the schema's own real
     * state, with no re-derivation step at all.
     */
    public function restoreArchivedVariationById(string $variationId): void
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);

        $variation = null;
        foreach ($product->variations() as $candidate) {
            if ((string) $candidate->id() === $variationId) {
                $variation = $candidate;

                break;
            }
        }

        if ($variation === null) {
            return;
        }

        $oldStatus = $variation->status()->value;

        try {
            $product->restoreArchivedVariation($variation);
        } catch (VariationNotRestorableException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (\LogicException $e) {
            // Reachable only through a stale page — another staff
            // member re-activated/re-archived this variation, or the
            // axis set changed, while this page stayed open.
            // restoreArchivedVariation()'s own \LogicException covers
            // "not ARCHIVED anymore"/"UNIVERSAL"/"no longer belongs to
            // this product" — same danger-notification-with-the-
            // domain's-own-message posture as the branch above
            // (VariableProductController's identical \LogicException ->
            // 422 mapping is the same principle at the HTTP layer). The
            // refresh IS the deliberate difference from the branch
            // above: nothing changed there, but here the page's own row
            // lists are genuinely stale, so refreshing is what stops
            // the merchant from clicking a button that no longer
            // applies.
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            $this->refreshVariationRows();

            return;
        }

        app(ProductRepository::class)->save($product);

        app(ActivityLogger::class)->logFieldChanged(
            'product',
            $product->id(),
            "variation[{$variationId}].status",
            $oldStatus,
            'draft'
        );

        Notification::make()
            ->title(__('products.variation_restore.notification_success'))
            ->success()
            ->send();

        $this->refreshVariationRows();
    }

    /**
     * Shared by generateMissingVariations()/restoreArchivedVariationById()
     * — see either method's own docblock for why this is
     * $this->form->fill($this->data), never $this->fillForm().
     */
    private function refreshVariationRows(): void
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $this->record->id);

        $this->data['existing_variations'] = $this->existingVariationRows($product);
        $this->data['archived_variations'] = $this->archivedVariationRows($product);

        $this->form->fill($this->data);
    }

    /**
     * Mirrors EditProduct::updateProduct()'s own diff-then-write pattern
     * exactly, for the fields this page's own form actually has. Parent
     * fields first (unchanged from Step 1), then per-variation sku/
     * barcode/is_purchasable/cost/stock_quantity — see
     * updateVariationRows()'s own docblock for that part. Axis
     * re-declaration (see the axesDiffer()/declareVariationAxes() call
     * site below) and "Add variation" (addNewVariationRows()) are now
     * both real, part of this same submission; "Generate missing
     * variations" and archived-variation Restore are their own
     * independent side-actions instead (generateMissingVariations()/
     * restoreArchivedVariationById() above), never part of this method.
     */
    private function updateProduct(Model $record, array $data): Model
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $record->id);

        if ($product === null) {
            throw new RuntimeException("Product \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        $logger = app(ActivityLogger::class);

        // Captured from $data (never mutated by anything below) BEFORE
        // updateVariationRows()/the base_sku cascade can touch a real
        // Variation's own sku — this is the "what the merchant actually
        // typed" side of the post-save notification comparison in
        // afterSave().
        $submittedSkusByVariationId = [];
        foreach ($data['existing_variations'] ?? [] as $row) {
            $variationId = (string) ($row['variation_id'] ?? '');

            if ($variationId !== '') {
                $submittedSkusByVariationId[$variationId] = (string) ($row['sku'] ?? '');
            }
        }

        if ($product->name() !== $data['name']) {
            $logger->logFieldChanged('product', $product->id(), 'name', $product->name(), $data['name']);
            $product->rename($data['name']);
        }

        // Blank slug/base_sku on submit triggers real auto-generation,
        // exactly like Create already does — ->required() no longer
        // blocks a blank submission here (see this class's own
        // generalTabComponents()).
        //
        // slug's own real 'catalog.product.slug' Hook listener
        // (confirmed against its installed source,
        // CatalogSlugGeneratorServiceProvider) runs cleanup()+
        // deduplicate() even on NON-blank input — unlike base_sku's own
        // listener, which returns non-empty input completely unchanged.
        // Calling it unconditionally on every edit (matching base_sku's
        // own call shape) would be a REAL, CONFIRMED REGRESSION found
        // while testing this — verified directly via tinker before
        // writing this guard, not assumed: resubmitting an unchanged,
        // already-valid slug makes deduplicate() find THIS SAME
        // product's own existing row and silently append "-1" to it, a
        // false "collision" against itself. Only invoked when the
        // submission is genuinely blank; a non-blank submitted slug is
        // used verbatim, same as before this fix.
        $newSlug = filled($data['slug'] ?? null)
            ? $data['slug']
            : Hook::apply('catalog.product.slug', '', $data['name']);

        if ($product->slug() !== $newSlug) {
            $logger->logFieldChanged('product', $product->id(), 'slug', $product->slug(), $newSlug);
            $product->changeSlug($newSlug);
        }

        // base_sku's own real Hook listener returns non-empty input
        // completely unchanged (confirmed against its installed
        // source) — safe to call unconditionally, unlike slug's above.
        // $oldBaseSku/$baseSkuChanged feed the cascade below, run AFTER
        // updateVariationRows() — see that call site's own comment for
        // why the ordering matters.
        $oldBaseSku = $product->baseSku();
        $newBaseSku = Hook::apply('catalog.product.base_sku', $data['base_sku'] ?? '');
        $baseSkuChanged = $oldBaseSku !== $newBaseSku;

        if ($baseSkuChanged) {
            $logger->logFieldChanged('product', $product->id(), 'base_sku', $oldBaseSku, $newBaseSku);
            $product->changeBaseSku($newBaseSku);
        }

        $newDescription = filled($data['description'] ?? null) ? $data['description'] : null;
        if ($product->description() !== $newDescription) {
            $logger->logFieldChanged('product', $product->id(), 'description', $product->description(), $newDescription);
            $product->changeDescription($newDescription);
        }

        $newStatus = $data['status'] ?? ProductStatus::DRAFT->value;
        $oldStatus = $product->status()->value;
        // Same ordering requirement as EditProduct's own identical
        // block: detected here, acted on later (only after syncMedia()
        // below) — see that method's own comment for the full ordering
        // argument, unchanged here.
        $shouldCleanArchivedMedia = $oldStatus !== $newStatus && $newStatus === ProductStatus::ARCHIVED->value;

        // RESTRUCTURED for the variation-archiving consistency guard
        // (this class's own docblock point 3 / archiveRemovedVariationRows()
        // below): ARCHIVED/DRAFT transitions are still applied HERE,
        // immediately, exactly as before — only ACTIVE is pulled out of
        // this match() entirely. publish() is NOT called from this
        // block anymore, even on a real DRAFT/ARCHIVED -> ACTIVE
        // transition this submission — it is instead attempted
        // UNCONDITIONALLY-WHENEVER-newStatus-IS-ACTIVE further below,
        // AFTER archiveRemovedVariationRows() has run. This is the one
        // and only real fix this restructuring exists for: the OLD
        // code could never catch "this submission's own variation
        // archiving left an ACTIVE product with nothing sellable"
        // because publish() was only ever invoked when the STATUS
        // FIELD ITSELF changed — an unchanged-Active product silently
        // stayed (wrongly) Active even after its last sellable
        // variation was archived in the very same request. The
        // ActivityLogger call stays HERE, unconditionally on a real
        // status-field change, regardless of which branch (including
        // ACTIVE) ends up applying it — logging what the merchant
        // asked for is correct the moment they ask for it, independent
        // of publish()'s own later, separate validation outcome.
        if ($oldStatus !== $newStatus) {
            $logger->logFieldChanged('product', $product->id(), 'status', $oldStatus, $newStatus);

            if ($newStatus === ProductStatus::ARCHIVED->value) {
                $product->archive();
            } elseif ($newStatus !== ProductStatus::ACTIVE->value) {
                $product->markAsDraft();
            }
        }

        $newVisibility = CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value);
        if ($product->catalogVisibility() !== $newVisibility) {
            $logger->logFieldChanged('product', $product->id(), 'catalog_visibility', $product->catalogVisibility()->value, $newVisibility->value);
            $product->setCatalogVisibility($newVisibility);
        }

        $newBrandId = $data['brand_id'] ?? null;
        if ($product->brandId() !== $newBrandId) {
            $logger->logFieldChanged('product', $product->id(), 'brand_id', $product->brandId(), $newBrandId);
            $product->assignBrand($newBrandId);
        }

        $newSeasonId = $data['season_id'] ?? null;
        if ($product->seasonId() !== $newSeasonId) {
            $logger->logFieldChanged('product', $product->id(), 'season_id', $product->seasonId(), $newSeasonId);
            $product->assignSeason($newSeasonId);
        }

        $newProductGroupId = $data['product_group_id'] ?? null;
        if ($product->productGroupId() !== $newProductGroupId) {
            $logger->logFieldChanged('product', $product->id(), 'product_group_id', $product->productGroupId(), $newProductGroupId);
            $product->assignProductGroup($newProductGroupId);
        }

        // BEFORE syncDescriptiveAttributesFromPickerRows() below AND
        // before any variation write (updateVariationRows()/
        // addNewVariationRows()/the generator) — every one of those
        // validates its own combinations against $product's REAL,
        // FINAL axis set, so the axes themselves must be settled
        // first. Declared ONLY when the submitted set genuinely
        // differs from $product->variationAxes() (axesDiffer()'s own
        // order-insensitive set comparison) — the new directional
        // guard (catalog-domain-design.md §3.17) would ALLOW an
        // identical-set no-op redeclare too (R1), but calling
        // declareVariationAxes() unconditionally on every save would
        // still mean an unnecessary catalog_product_attributes/
        // catalog_product_axis_values delete+reinsert AND a spurious
        // "nothing really changed" activity-log entry on every
        // ordinary save — skipped here for exactly those two reasons,
        // not because the domain itself would reject it.
        try {
            $newAxes = ProductResource::buildVariationAxesFromInput($data['axes'] ?? []);
        } catch (InvalidVariationAxisException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }

        if ($this->axesDiffer($product->variationAxes(), $newAxes)) {
            // Logged BEFORE declareVariationAxes() runs, so the log
            // entry's own old value is the real pre-change summary —
            // human-readable real definition/value NAMES (e.g. "Color:
            // Black, White; Size: S, M"), not raw ids, same posture as
            // syncCategories()/syncTags()'s own name-not-id logging
            // elsewhere in this class.
            $logger->logFieldChanged(
                'product',
                $product->id(),
                'variation_axes',
                $this->axesSummary($product->variationAxes()),
                $this->axesSummary($newAxes)
            );

            try {
                $product->declareVariationAxes($newAxes);
            } catch (UnsafeAxisRedeclarationException|InvalidVariationAxisException|\LogicException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }
        }

        ProductResource::syncDescriptiveAttributesFromPickerRows($product, $data['descriptive_attributes_picker'] ?? [], $logger);

        // BEFORE save() below, not after — EloquentProductRepository::save()
        // itself iterates $product->variations() and persists each one
        // (confirmed against its own real source), the same cascading
        // save CreateVariableProduct::addStandardVariations() already
        // relies on. Mutating the in-memory Variation objects here means
        // this single save() call below persists both the parent fields
        // above AND these per-row changes together.
        $this->updateVariationRows($product, $data['existing_variations'] ?? [], $logger);

        // Right after updateVariationRows() above for readability —
        // ordering between these two does not structurally matter,
        // unlike the cascade below: they operate on disjoint sets
        // (submitted rows vs. rows genuinely absent from this
        // submission), so neither can affect the other's outcome.
        $this->archiveRemovedVariationRows($product, array_keys($submittedSkusByVariationId), $logger);

        // AFTER archiveRemovedVariationRows() above — $product's own
        // non-archived set is settled by then, which is exactly the set
        // this method compares the submitted row order against. BEFORE
        // save() below, so a variation added in THIS SAME submission
        // (addNewVariationRows(), further down) is appended AFTER the
        // renumbering, not interleaved with it.
        $this->applyVariationRowOrder($product, $data['existing_variations'] ?? [], $logger);

        // AFTER updateVariationRows() above, not before — ordering is
        // load-bearing: an explicit per-row sku edit in the SAME
        // submission must win over this cascade, not get silently
        // overwritten by it.
        if ($baseSkuChanged) {
            $this->cascadeBaseSkuToVariationSkus($product, $oldBaseSku, $newBaseSku, $logger);
        }

        // AFTER archiveRemovedVariationRows()/the cascade above, BEFORE
        // the publish() check below — a brand-new variation added in
        // THIS SAME submission must already exist by the time
        // publish()'s own hasAnyNonArchivedStandardVariation() guard
        // runs, so a merchant adding the product's very first variation
        // AND setting status to Active in one save genuinely succeeds.
        $this->addNewVariationRows($product, $data['new_variations'] ?? [], $logger);

        // AFTER archiveRemovedVariationRows() above — see the status
        // block's own comment (near $oldStatus/$newStatus) for why
        // publish() moved out of that block entirely: attempted
        // whenever the FINAL status is ACTIVE, not only on a real
        // status-field change this submission, specifically so this
        // submission's own variation archiving above gets re-validated
        // against "does this product still have anything sellable"
        // BEFORE it is ever persisted. publish() is confirmed
        // idempotent (Product::publish()'s own docblock: "calling this
        // while already ACTIVE simply re-asserts the same status,
        // still subject to the same guard") — reusing its own real
        // guard here, not duplicating hasAnyNonArchivedStandardVariation()'s
        // logic (private to Product, and rightly so).
        if ($newStatus === ProductStatus::ACTIVE->value) {
            try {
                $product->publish();
            } catch (CannotPublishEmptyVariableProductException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }
        }

        app(ProductRepository::class)->save($product);

        $this->syncCategories($product->id(), $data['categories'] ?? []);
        $this->syncTags($product->id(), $data['tags'] ?? []);

        $photoPaths = [];
        if (filled($data['main_photo'] ?? null)) {
            $photoPaths[] = $data['main_photo'];
        }
        foreach ($data['gallery_photos'] ?? [] as $path) {
            $photoPaths[] = $path;
        }
        $this->syncMedia($product->id(), $photoPaths, MediaType::IMAGE);

        $videoPaths = filled($data['video'] ?? null) ? [$data['video']] : [];
        $this->syncMedia($product->id(), $videoPaths, MediaType::VIDEO, (bool) ($data['video_autoplay'] ?? false));

        if ($shouldCleanArchivedMedia) {
            app(ArchiveProductMediaCleaner::class)->clean($product->id());
        }

        // Per-variation photos — a SEPARATE persistence concern from
        // ProductRepository::save() above (VariationMediaRepository,
        // not an in-memory Variation mutation), same "independent of
        // save(), can run after it" reasoning as syncCategories()/
        // syncTags()/syncMedia() above. $variationsById (not
        // $product->variations() re-walked) so a submitted row whose
        // variation_id doesn't resolve to a real Variation on THIS
        // product is silently skipped, same defensive posture as
        // updateVariationRows().
        $variationsById = [];
        foreach ($product->variations() as $variation) {
            $variationsById[(string) $variation->id()] = $variation;
        }
        foreach ($data['existing_variations'] ?? [] as $row) {
            $variationId = (string) ($row['variation_id'] ?? '');

            if (! isset($variationsById[$variationId])) {
                continue;
            }

            $this->syncVariationMedia($variationId, $row['variation_photos'] ?? []);
        }

        // cost/stock_quantity/regular_price/sale_price, AFTER the
        // product/variation save() above — ProductPricingAndStock
        // composes separate EasyCo\Pricing/EasyCo\Inventory
        // repositories, entirely independent of ProductRepository::
        // save(), same ordering EditProduct's own
        // updatePricingAndStock() already establishes for the SIMPLE
        // flow.
        $this->updateProductLevelPricing($product->id(), $data, $logger);
        $this->updateVariationPricingAndStock($product->variations(), $data['existing_variations'] ?? [], $logger, $product->id());

        // AFTER updateVariationPricingAndStock() above — see this
        // method's own docblock for the deliberate ordering decision
        // (the checkbox wins a same-submission conflict against a new
        // per-row override, the opposite of the base_sku cascade's own
        // "explicit edit wins" precedent).
        $this->clearVariationPriceOverrides($product->variations(), $data, $logger, $product->id());

        // The REAL, final side of the afterSave() comparison —
        // $product->variations() reflects any sku-collision-retry
        // correction (EloquentProductRepository::
        // saveVariationModelWithSkuCollisionRetry() calls
        // $variation->setSku() on the in-memory Variation the moment a
        // retry actually happens — confirmed against its installed
        // source) AND the cascade above, since save() has already run.
        // Scoped to exactly the variation_ids that were submitted this
        // request (mirrors $submittedSkusByVariationId's own keys),
        // not every variation on the product.
        foreach ($product->variations() as $variation) {
            $variationId = (string) $variation->id();

            if (isset($submittedSkusByVariationId[$variationId])) {
                $this->finalVariationSkus[$variationId] = $variation->sku();
            }
        }
        $this->submittedVariationSkus = $submittedSkusByVariationId;

        return ProductModel::find($product->id());
    }

    /**
     * Cascades a real base_sku rename to every variant sku that still
     * starts with the OLD base_sku prefix. str_starts_with($sku,
     * $oldBaseSku.'-') — the trailing hyphen, not a bare substring
     * match — so an unrelated sku that merely CONTAINS the old
     * base_sku (e.g. old base_sku "1544", an unrelated sku
     * "9-1544-X") is never false-matched. A variant sku that does NOT
     * start with the old prefix (already manually customized to
     * something unrelated) is deliberately left alone — an unrelated
     * manual sku must never be silently touched by a base_sku rename.
     */
    private function cascadeBaseSkuToVariationSkus(Product $product, string $oldBaseSku, string $newBaseSku, ActivityLogger $logger): void
    {
        $prefix = $oldBaseSku.'-';

        foreach ($product->variations() as $variation) {
            // An ARCHIVED variation's sku is historical (Variation::
            // archive()'s own docblock — "historical references...
            // must remain valid forever") and must stay stable, never
            // touched by a base_sku rename — this deliberately also
            // catches a variation archiveRemovedVariationRows() just
            // archived earlier in THIS SAME submission (that call runs
            // BEFORE this one — see the call site's own comment), not
            // only one already archived on an earlier request.
            if ($variation->status() === VariationStatus::ARCHIVED) {
                continue;
            }

            $currentSku = $variation->sku();

            if (! str_starts_with($currentSku, $prefix)) {
                continue;
            }

            $newSku = $newBaseSku.'-'.substr($currentSku, strlen($prefix));

            $logger->logFieldChanged('product', $product->id(), "variation[{$variation->id()}].sku", $currentSku, $newSku);
            $variation->setSku($newSku);
        }
    }

    /**
     * The PRODUCT-level counterpart of updateVariationPricingAndStock()'s
     * own regular_price/sale_price handling — ONCE per submission (a
     * single PRODUCT-level PriceListItem per system list, not one per
     * row). Same permission-gate-BEFORE-reading-$data discipline: the
     * PRICE_MANAGE check gates the whole method, before either
     * product_regular_price or product_sale_price is ever read out of
     * $data. ActivityLogger field names are NOT per-id scoped (unlike
     * every per-variation field in this class) — there is only ever one
     * PRODUCT-level record per product, so no row to disambiguate
     * against.
     */
    private function updateProductLevelPricing(string $productId, array $data, ActivityLogger $logger): void
    {
        if (! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)) {
            return;
        }

        $pricingAndStock = app(ProductPricingAndStock::class);

        $currentRegularPrice = $pricingAndStock->regularPriceDisplayForProduct($productId);
        $newRegularPrice = $pricingAndStock->normalizeDecimalDisplay($data['product_regular_price'] ?? null);
        if ($currentRegularPrice !== $newRegularPrice) {
            $logger->logFieldChanged('product', $productId, 'product_regular_price', $currentRegularPrice, $newRegularPrice);
            $pricingAndStock->writeRegularPriceForProduct($productId, $newRegularPrice);
        }

        $currentSalePrice = $pricingAndStock->salePriceDisplayForProduct($productId);
        $newSalePrice = $pricingAndStock->normalizeDecimalDisplay($data['product_sale_price'] ?? null);
        if ($currentSalePrice !== $newSalePrice) {
            $logger->logFieldChanged('product', $productId, 'product_sale_price', $currentSalePrice, $newSalePrice);
            $pricingAndStock->writeSalePriceForProduct($productId, $newSalePrice);
        }
    }

    /**
     * The write side of the "clear variation-level price overrides"
     * toggles (existingVariationsComponents()'s own docblock) — an
     * explicit, opt-in flatten of every VARIATION-level override for
     * that price type back to the PRODUCT-level value. Gated by the
     * SAME PRICE_MANAGE permission as every other price write in this
     * class, checked BEFORE either $data key is read, same discipline
     * as updateVariationPricingAndStock()'s own regular_price/sale_price
     * block — a tampered ->set() on the toggle from a staff member
     * lacking PRICE_MANAGE can never trigger a real clear.
     *
     * ORDERING, EXPLICITLY DECIDED, NOT DEFAULTED INTO: called AFTER
     * updateVariationPricingAndStock() (not folded into
     * updateProductLevelPricing(), which runs BEFORE it in
     * updateProduct()) — so in the genuinely ambiguous case of a
     * merchant typing a NEW per-row override into a row in the SAME
     * submission they also check "clear overrides" for, the checkbox
     * wins: whatever was just written is cleared right back out. This
     * is the OPPOSITE of the base_sku cascade's own "explicit per-row
     * edit wins" precedent (cascadeBaseSkuToVariationSkus() runs AFTER
     * updateVariationRows() for exactly that reason) — deliberately,
     * per this task's own explicit instruction, not an oversight:
     * checking the box is itself an explicit, deliberate act ("flatten
     * every override of this type"), distinct from a base_sku rename
     * (which is a PARENT field change that merely CASCADES to rows,
     * never something a merchant is deliberately clicking specifically
     * to override a row edit). A merchant who both types a new override
     * AND checks the box in one submission has given two contradictory
     * instructions in the same request; the checkbox — the more
     * sweeping, more explicit of the two — wins. Each cleared row is
     * logged individually, old value = the real override that existed,
     * new value = null — same "variation[{id}].regular_price"/
     * "variation[{id}].sale_price" ActivityLogger field-name pattern as
     * every other per-variation price write in this class.
     *
     * @param Variation[] $variations
     */
    private function clearVariationPriceOverrides(array $variations, array $data, ActivityLogger $logger, string $productId): void
    {
        if (! ProductResource::staffHasPermission(Permission::PRICE_MANAGE)) {
            return;
        }

        $clearRegular = (bool) ($data['clear_regular_price_overrides'] ?? false);
        $clearSale = (bool) ($data['clear_sale_price_overrides'] ?? false);

        if (! $clearRegular && ! $clearSale) {
            return;
        }

        $pricingAndStock = app(ProductPricingAndStock::class);

        foreach ($variations as $variation) {
            $variationId = (string) $variation->id();

            if ($clearRegular) {
                $currentRegularPrice = $pricingAndStock->regularPriceDisplay($variationId);
                if ($currentRegularPrice !== null) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].regular_price", $currentRegularPrice, null);
                    $pricingAndStock->writeRegularPrice($variationId, null);
                }
            }

            if ($clearSale) {
                $currentSalePrice = $pricingAndStock->salePriceDisplay($variationId);
                if ($currentSalePrice !== null) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].sale_price", $currentSalePrice, null);
                    $pricingAndStock->writeSalePrice($variationId, null);
                }
            }
        }
    }

    /**
     * sku/barcode/is_purchasable — no permission gate needed for these
     * three (per this task's own instruction: PRODUCT_MANAGE is already
     * this whole page's base edit permission, gating canEdit() itself).
     * Diff-then-write, same ActivityLogger::logFieldChanged() pattern
     * as every other field in this class — but with field names scoped
     * per variation ("variation[{id}].sku" etc.), unlike every other
     * call in this class: this is the first field set here where
     * multiple rows of the SAME field name are genuinely possible in
     * one submission (a VARIABLE product's several variations) — an
     * unscoped field name would make two different variations' sku
     * changes indistinguishable in the activity log.
     *
     * barcode is filled()-normalized through the same
     * 'catalog.variation.barcode' Hook EditProduct's own universal-
     * variation barcode write already uses — a blank submission still
     * gets a real chance to auto-generate, exactly like the SIMPLE
     * flow.
     *
     * A row whose variation_id doesn't resolve to a real Variation on
     * this product (should never happen via this form — the Hidden
     * field is always seeded from a real persisted id — but a
     * genuinely stale, concurrent-edit row is possible) is silently
     * skipped, not thrown: this update is about every OTHER row and
     * field this same request also legitimately changes, and the real,
     * current state of the skipped variation is simply left untouched,
     * not corrupted.
     *
     * @param array<int, array{variation_id?: mixed, sku?: mixed, barcode?: mixed, is_purchasable?: mixed}> $rows
     */
    private function updateVariationRows(Product $product, array $rows, ActivityLogger $logger): void
    {
        $variationsById = [];
        foreach ($product->variations() as $variation) {
            $variationsById[(string) $variation->id()] = $variation;
        }

        foreach ($rows as $row) {
            $variationId = (string) ($row['variation_id'] ?? '');
            $variation = $variationsById[$variationId] ?? null;

            if ($variation === null) {
                continue;
            }

            $newSku = (string) ($row['sku'] ?? '');
            if ($variation->sku() !== $newSku) {
                $logger->logFieldChanged('product', $product->id(), "variation[{$variationId}].sku", $variation->sku(), $newSku);
                $variation->setSku($newSku);
            }

            $newBarcode = filled($row['barcode'] ?? null) ? $row['barcode'] : null;
            if ($variation->barcode() !== $newBarcode) {
                $logger->logFieldChanged('product', $product->id(), "variation[{$variationId}].barcode", $variation->barcode(), $newBarcode);
                $barcode = Hook::apply('catalog.variation.barcode', $newBarcode ?? '', $variation);
                $variation->setBarcode($barcode !== '' ? $barcode : null);
            }

            $newIsPurchasable = (bool) ($row['is_purchasable'] ?? true);
            if ($variation->isPurchasable() !== $newIsPurchasable) {
                $logger->logFieldChanged('product', $product->id(), "variation[{$variationId}].is_purchasable", $variation->isPurchasable() ? '1' : '0', $newIsPurchasable ? '1' : '0');
                $variation->setPurchasable($newIsPurchasable);
            }
        }
    }

    /**
     * The real write side of the existing-variations Repeater's own
     * drag-and-drop reorder: the order the rows were submitted in IS the
     * merchant's own display order, so array index becomes
     * catalog_variations.sort_order through
     * VariationRepository::updateSortOrders() — the exact same
     * "submitted array order = sort_order" shape syncMedia()/
     * syncVariationMedia() already use for the media pivots, and the
     * place the order genuinely persists (Variation itself carries no
     * order field at all — see the 2026_09_23_000001 migration).
     *
     * NO-OP ON AN ORDINARY SAVE — the common case. The comparison is
     * against the order these same rows are in TODAY, read straight off
     * $product->variations() (already sort_order ASC, id ASC, because
     * ProductRepository::findByIdWithVariations() loads it that way), so
     * a save that never touched the row order writes nothing and logs
     * nothing — not just "the same values written again".
     *
     * Restricted in two ways, both deliberate:
     * - Only $product's own NON-ARCHIVED variations are considered. A row
     *   removed from the form in this same submission was archived by
     *   archiveRemovedVariationRows() above, and its absence is an
     *   archive, never a reorder; an archived variation also keeps
     *   whatever sort_order it already had (it isn't part of the list
     *   the merchant is ordering).
     * - A submitted row whose variation_id is empty or doesn't resolve to
     *   one of this product's own live variations is skipped, the same
     *   defensive posture updateVariationRows()/updateVariationPricingAndStock()
     *   already take for a tampered form.
     */
    private function applyVariationRowOrder(Product $product, array $rows, ActivityLogger $logger): void
    {
        $liveVariationIds = [];
        $labelsByVariationId = [];

        foreach ($product->variations() as $variation) {
            if ($variation->status() === VariationStatus::ARCHIVED) {
                continue;
            }

            $variationId = (string) $variation->id();
            $liveVariationIds[] = $variationId;
            $labelsByVariationId[$variationId] = $this->variationLabel($variation);
        }

        $submittedVariationIds = [];
        foreach ($rows as $row) {
            $variationId = (string) ($row['variation_id'] ?? '');

            if ($variationId !== '' && isset($labelsByVariationId[$variationId])) {
                $submittedVariationIds[] = $variationId;
            }
        }

        if ($submittedVariationIds === []) {
            return;
        }

        $currentOrder = array_values(array_filter(
            $liveVariationIds,
            static fn (string $variationId): bool => in_array($variationId, $submittedVariationIds, true)
        ));

        if ($currentOrder === $submittedVariationIds) {
            return;
        }

        // Real combination labels, not variation ids — same
        // human-readable-logging posture as axesSummary() below.
        $logger->logFieldChanged(
            'product',
            $product->id(),
            'variation_order',
            $this->variationOrderSummary($currentOrder, $labelsByVariationId),
            $this->variationOrderSummary($submittedVariationIds, $labelsByVariationId)
        );

        app(VariationRepository::class)->updateSortOrders($product->id(), $submittedVariationIds);
    }

    /**
     * "Color: Black | Color: White" — the activity-log summary of one
     * variation order, built from the same real labels the rows
     * themselves display.
     *
     * @param array<int, string> $orderedVariationIds
     * @param array<string, string> $labelsByVariationId
     */
    private function variationOrderSummary(array $orderedVariationIds, array $labelsByVariationId): string
    {
        return implode(' | ', array_map(
            static fn (string $variationId): string => $labelsByVariationId[$variationId] ?? $variationId,
            $orderedVariationIds
        ));
    }

    /**
     * The real archive mechanism behind the Repeater's own delete
     * button (existingVariationsComponents()'s own docblock) — a
     * variation present on $product (the full set, loaded BEFORE this
     * submission's own removals) but absent from
     * $submittedVariationIds (captured in updateProduct() BEFORE any
     * mutation, from the exact same $data['existing_variations'] this
     * page's own Repeater just submitted) was removed from the form
     * client-side this request, and that removal IS the real archive
     * trigger — the same "absence from submission = no longer applies"
     * detection shape ProductResource::syncCategories()/syncTags()
     * already use, scoped to this Repeater instead of a pivot table.
     *
     * NEVER a hard delete — Variation::archive()'s own docblock
     * ("historical references... must remain valid forever", CLAUDE.md
     * rule 4) — and deliberately NOT a cleanup pass: this method does
     * not touch price/cost/stock/media rows for the archived
     * variation at all, on purpose (see this task's own report for the
     * full reasoning) — a later, separate "revive" feature needs that
     * state to still be there if the merchant re-adds the exact same
     * combination.
     *
     * Skips a variation ALREADY ARCHIVED — both one archived on an
     * earlier request, and (just as importantly) one this SAME
     * updateProduct() call already archived moments ago via this exact
     * method on an earlier invocation... which cannot actually happen
     * (this method runs once per submission), but the guard is what
     * makes this call genuinely idempotent/safe to reason about in
     * isolation, and avoids a spurious "archived -> archived" log
     * entry for a row that was already archived before this submission
     * even started (e.g. stale form state).
     *
     * @param array<int, string> $submittedVariationIds
     */
    private function archiveRemovedVariationRows(Product $product, array $submittedVariationIds, ActivityLogger $logger): void
    {
        $submittedIds = array_flip($submittedVariationIds);

        foreach ($product->variations() as $variation) {
            $variationId = (string) $variation->id();

            if (isset($submittedIds[$variationId])) {
                continue;
            }

            if ($variation->status() === VariationStatus::ARCHIVED) {
                continue;
            }

            $logger->logFieldChanged('product', $product->id(), "variation[{$variationId}].status", $variation->status()->value, VariationStatus::ARCHIVED->value);
            $variation->archive();
        }
    }

    /**
     * "Genuinely differs" per updateProduct()'s own comment: same
     * attribute_definition_id keys AND, per definition, the same
     * allowed-value id set — order never matters, either of axes or of
     * values. Mirrors Product::assertAxisChangeIsSafe()'s own R1 "is
     * this identical" check exactly (same normalize-then-compare
     * shape, see normalizedIdSet() below), but lives here at the app
     * layer rather than reusing a private domain method — this
     * comparison decides whether to call declareVariationAxes() AT
     * ALL (to avoid an unnecessary write + a spurious log entry, see
     * that call site's own comment), which is a genuinely different
     * question from the domain's own "is this specific change safe."
     *
     * @param VariationAxis[] $currentAxes
     * @param VariationAxis[] $newAxes
     */
    private function axesDiffer(array $currentAxes, array $newAxes): bool
    {
        $current = [];
        foreach ($currentAxes as $axis) {
            $current[$axis->attributeDefinitionId()] = $this->normalizedIdSet($axis->allowedValueIds());
        }

        $new = [];
        foreach ($newAxes as $axis) {
            $new[$axis->attributeDefinitionId()] = $this->normalizedIdSet($axis->allowedValueIds());
        }

        if ($this->normalizedIdSet(array_keys($current)) !== $this->normalizedIdSet(array_keys($new))) {
            return true;
        }

        foreach ($current as $definitionId => $valueIds) {
            if ($valueIds !== $new[$definitionId]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $ids
     * @return string[]
     */
    private function normalizedIdSet(array $ids): array
    {
        $unique = array_values(array_unique(array_map('strval', $ids)));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * A compact, human-readable summary for the 'variation_axes'
     * ActivityLogger entry — real definition/value NAMES (e.g. "Color:
     * Black, White; Size: S, M"), not raw ids, matching every other
     * logFieldChanged() call in this class that logs a name rather
     * than an id (syncCategories()/syncTags()). A definition/value
     * whose model has since been deleted falls back to its own raw id,
     * same defensive posture as variationLabel() above.
     *
     * @param VariationAxis[] $axes
     */
    private function axesSummary(array $axes): string
    {
        $parts = [];

        foreach ($axes as $axis) {
            $definitionId = $axis->attributeDefinitionId();
            $definitionName = AttributeDefinitionModel::find($definitionId)?->name ?? $axis->attributeDefinitionCode();

            $valueNames = array_map(
                fn (string $valueId): string => AttributeValueModel::find($valueId)?->value ?? $valueId,
                $axis->allowedValueIds()
            );

            $parts[] = "{$definitionName}: ".implode(', ', $valueNames);
        }

        return implode('; ', $parts);
    }

    /**
     * "Add variation" (B2) — writes every row from the new_variations
     * Repeater, each one an explicitly-chosen combination (unlike
     * generateMissingVariations()'s own cartesian sweep). Each row's
     * own "axis_value_{definitionId}" fields (built dynamically per
     * this product's CURRENTLY DECLARED axes — see
     * newVariationComponents()'s own docblock) are collected back into
     * a [definitionId => valueId] combination map here, keyed exactly
     * the way Product::addStandardVariation() expects.
     *
     * ProductResource::writeStandardVariation() is the SAME shared
     * addStandardVariation()+barcode-Hook+activate() core
     * CreateVariableProduct::addStandardVariations() uses — see that
     * method's own docblock for why this page catches a broader
     * exception set than Create's own call site does.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function addNewVariationRows(Product $product, array $rows, ActivityLogger $logger): void
    {
        $axes = $product->variationAxes();

        foreach ($rows as $row) {
            $combination = [];
            foreach ($axes as $axis) {
                $definitionId = $axis->attributeDefinitionId();
                $valueId = $row["axis_value_{$definitionId}"] ?? null;

                if ($valueId !== null) {
                    $combination[$definitionId] = $valueId;
                }
            }

            try {
                ProductResource::writeStandardVariation(
                    $product,
                    $combination,
                    (string) ($row['sku'] ?? ''),
                    $row['barcode'] ?? '',
                    (bool) ($row['is_active'] ?? false)
                );
            } catch (InvalidVariationAxisException|DuplicateVariationCombinationException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }
        }
    }

    /**
     * Mirrors EditProduct::updatePricingAndStock()'s own real
     * permission-gate-BEFORE-reading-$data pattern exactly — checking
     * the permission before ever reading that row's own $data value is
     * what actually matters (a merely-disabled field's value still
     * dehydrates into $data on submit, confirmed against Filament's own
     * isDehydrated() — see priceStockTabComponents()'s own docblock for
     * the full, already-found gap this guards against), not the diff
     * itself. Applied per row now instead of once for a single
     * universal Variation. Step 2b extends this with regular_price/
     * sale_price (PRICE_MANAGE-gated, same as cost/stock above) — each
     * row's own VARIATION-level override via the existing, unchanged
     * writeRegularPrice()/writeSalePrice().
     *
     * @param Variation[] $variations
     * @param array<int, array{variation_id?: mixed, cost?: mixed, stock_quantity?: mixed, regular_price?: mixed, sale_price?: mixed}> $rows
     */
    private function updateVariationPricingAndStock(array $variations, array $rows, ActivityLogger $logger, string $productId): void
    {
        $pricingAndStock = app(ProductPricingAndStock::class);

        $variationIds = array_map(fn (Variation $variation): string => (string) $variation->id(), $variations);
        $validVariationIds = array_flip($variationIds);

        foreach ($rows as $row) {
            $variationId = (string) ($row['variation_id'] ?? '');

            if (! isset($validVariationIds[$variationId])) {
                continue;
            }

            if (ProductResource::staffHasPermission(Permission::COST_MANAGE)) {
                $currentCost = $pricingAndStock->costDisplay($variationId);
                $newCost = $pricingAndStock->normalizeDecimalDisplay($row['cost'] ?? null);

                if ($newCost !== null && $currentCost !== $newCost) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].cost", $currentCost, $newCost);
                    $pricingAndStock->writeCost($variationId, $newCost);
                }
            }

            if (ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)) {
                $currentStock = $pricingAndStock->stockQuantity($variationId);
                $newStock = (int) ($row['stock_quantity'] ?? 0);

                if ($currentStock !== $newStock) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].stock_quantity", (string) $currentStock, (string) $newStock);
                    $pricingAndStock->writeStockQuantity($variationId, $newStock);
                }
            }

            // Same permission-gate-BEFORE-reading-$data discipline as
            // cost/stock above — but no "!== null" guard here, unlike
            // cost: writeRegularPrice()/writeSalePrice() (unchanged,
            // reused verbatim) genuinely remove the PriceListItem on a
            // blank value (pricing-persistence-domain-design.md §4.5:
            // "active only while populated"), the real, intended
            // behavior for clearing a per-row override back to the
            // PRODUCT-level fallback — unlike ProductCostRepository,
            // which has no removal path at all (see writeCost()'s own
            // docblock).
            if (ProductResource::staffHasPermission(Permission::PRICE_MANAGE)) {
                $currentRegularPrice = $pricingAndStock->regularPriceDisplay($variationId);
                $newRegularPrice = $pricingAndStock->normalizeDecimalDisplay($row['regular_price'] ?? null);
                if ($currentRegularPrice !== $newRegularPrice) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].regular_price", $currentRegularPrice, $newRegularPrice);
                    $pricingAndStock->writeRegularPrice($variationId, $newRegularPrice);
                }

                $currentSalePrice = $pricingAndStock->salePriceDisplay($variationId);
                $newSalePrice = $pricingAndStock->normalizeDecimalDisplay($row['sale_price'] ?? null);
                if ($currentSalePrice !== $newSalePrice) {
                    $logger->logFieldChanged('product', $productId, "variation[{$variationId}].sale_price", $currentSalePrice, $newSalePrice);
                    $pricingAndStock->writeSalePrice($variationId, $newSalePrice);
                }
            }
        }
    }

    /**
     * Duplicated verbatim from EditProduct::syncCategories() — see
     * class docblock for why (type-agnostic already, but extraction
     * left as a proposal, not done silently here).
     */
    protected function syncCategories(string $productId, array $submittedCategoryIds): void
    {
        $repository = app(ProductCategoryRepository::class);
        $current = $repository->findByProductId($productId);

        $submitted = array_map('strval', $submittedCategoryIds);
        $currentByCategoryId = [];
        foreach ($current as $pivot) {
            $currentByCategoryId[$pivot->categoryId()] = $pivot;
        }

        foreach ($submitted as $categoryId) {
            if (! isset($currentByCategoryId[$categoryId])) {
                $repository->save(new ProductCategory(id: null, productId: $productId, categoryId: $categoryId));

                $categoryName = CategoryModel::find($categoryId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'categories', null, $categoryName);
            }
        }

        foreach ($currentByCategoryId as $categoryId => $pivot) {
            // Same real, pre-existing numeric-string-array-key cast bug
            // and fix as EditProduct::syncCategories()'s own identical
            // comment — see that method for the full explanation.
            $categoryId = (string) $categoryId;

            if (! in_array($categoryId, $submitted, true)) {
                $repository->remove($pivot->id());

                $categoryName = CategoryModel::find($categoryId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'categories', $categoryName, null);
            }
        }
    }

    /**
     * Duplicated verbatim from EditProduct::syncTags() — see class
     * docblock for why.
     */
    protected function syncTags(string $productId, array $submittedTagIds): void
    {
        $repository = app(ProductTagRepository::class);
        $current = $repository->findByProductId($productId);

        $submitted = array_map('strval', $submittedTagIds);
        $currentByTagId = [];
        foreach ($current as $pivot) {
            $currentByTagId[$pivot->tagId()] = $pivot;
        }

        foreach ($submitted as $tagId) {
            if (! isset($currentByTagId[$tagId])) {
                $repository->save(new ProductTag(id: null, productId: $productId, tagId: $tagId));

                $tagName = TagModel::find($tagId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'tags', null, $tagName);
            }
        }

        foreach ($currentByTagId as $tagId => $pivot) {
            // Same real, pre-existing bug/fix as syncCategories() above
            // and EditProduct::syncTags()'s own identical comment.
            $tagId = (string) $tagId;

            if (! in_array($tagId, $submitted, true)) {
                $repository->remove($pivot->id());

                $tagName = TagModel::find($tagId)?->name;
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'tags', $tagName, null);
            }
        }
    }

    /**
     * Duplicated verbatim from EditProduct::syncMedia() — see class
     * docblock for why. Same ordering requirement (orphans detached
     * before any new attach is attempted) and the same VideoCountGuard/
     * ProductMediaCountGuard/MediaLimitExceededException handling — see
     * that method's own full docblock for the complete reasoning,
     * unchanged here.
     */
    protected function syncMedia(string $productId, array $submittedPaths, MediaType $type, bool $autoplay = false): void
    {
        $repository = app(ProductMediaRepository::class);
        $current = $repository->findByProductId($productId);

        $pivotByPath = [];
        foreach ($current as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());
            if ($asset !== null && $asset->type === $type->value) {
                $pivotByPath[$asset->path] = $pivot;
            }
        }

        $submittedValues = array_values($submittedPaths);

        foreach ($pivotByPath as $path => $pivot) {
            if (! in_array($path, $submittedValues, true)) {
                $repository->remove($pivot->id());
                unset($pivotByPath[$path]);
            }
        }

        $guard = app(ProductMediaCountGuard::class);
        $videoGuard = $type === MediaType::VIDEO ? app(VideoCountGuard::class) : null;

        foreach ($submittedValues as $sortOrder => $path) {
            if (isset($pivotByPath[$path])) {
                $pivot = $pivotByPath[$path];
                $changed = false;

                if ($pivot->sortOrder() !== $sortOrder) {
                    $pivot->updateSortOrder($sortOrder);
                    $changed = true;
                }

                if ($pivot->autoplay() !== $autoplay) {
                    $pivot->updateAutoplay($autoplay);
                    $changed = true;
                }

                if ($changed) {
                    $repository->save($pivot);
                }

                continue;
            }

            try {
                $guard->assertCanAttach($productId);
                $videoGuard?->assertCanAttach($productId);
            } catch (MediaLimitExceededException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $asset = ProductResource::createMediaAsset($path, $type);

            $repository->save(new ProductMedia(
                id: null,
                productId: $productId,
                mediaId: $asset->id(),
                sortOrder: $sortOrder,
                autoplay: $autoplay,
            ));
        }
    }

    /**
     * The per-VARIATION counterpart of syncMedia() above — same real
     * diff-then-write logic (detach removed, reorder kept, attach new,
     * MediaLimitExceededException -> Notification+Halt), simplified:
     * no $type parameter (VariationMedia is always MediaType::IMAGE —
     * confirmed against its own real constructor, which has no type
     * field at all) and no $autoplay (no video concept either).
     *
     * No PRODUCT_MANAGE re-check here, unlike PRICE_MANAGE/COST_MANAGE
     * in updateVariationPricingAndStock() — same real reasoning
     * updateVariationRows()'s own docblock already establishes for
     * sku/barcode/is_purchasable: PRODUCT_MANAGE is this whole page's
     * own base edit permission (ProductResource::editPermission()),
     * already enforced by EditRecord::authorizeAccess() at mount() —
     * confirmed against its installed source (abort_unless(canEdit(),
     * 403) before the form is ever filled). Unlike COST_MANAGE/
     * PRICE_MANAGE, which gate a NARROWER capability a staff member can
     * genuinely lack while still holding PRODUCT_MANAGE (the real,
     * reachable two-tier gap the 'Product Entry' role's own tests
     * exploit), there is no staff member who can reach this method at
     * all without ALREADY holding PRODUCT_MANAGE — a redundant check
     * here would be unreachable dead code, not a real second gate.
     */
    private function syncVariationMedia(string $variationId, array $submittedPaths): void
    {
        $repository = app(VariationMediaRepository::class);
        $current = $repository->findByVariationId($variationId);

        $pivotByPath = [];
        foreach ($current as $pivot) {
            $asset = MediaAssetModel::find($pivot->mediaId());
            if ($asset !== null) {
                $pivotByPath[$asset->path] = $pivot;
            }
        }

        $submittedValues = array_values($submittedPaths);

        foreach ($pivotByPath as $path => $pivot) {
            if (! in_array($path, $submittedValues, true)) {
                $repository->remove($pivot->id());
                unset($pivotByPath[$path]);
            }
        }

        $guard = app(VariationMediaCountGuard::class);

        foreach ($submittedValues as $sortOrder => $path) {
            if (isset($pivotByPath[$path])) {
                $pivot = $pivotByPath[$path];

                if ($pivot->sortOrder() !== $sortOrder) {
                    $pivot->updateSortOrder($sortOrder);
                    $repository->save($pivot);
                }

                continue;
            }

            try {
                $guard->assertCanAttach($variationId);
            } catch (MediaLimitExceededException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }

            $asset = ProductResource::createMediaAsset($path);

            $repository->save(new VariationMedia(
                id: null,
                variationId: $variationId,
                mediaId: $asset->id(),
                sortOrder: $sortOrder,
            ));
        }
    }
}
