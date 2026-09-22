<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Services\ArchiveProductMediaCleaner;
use App\Services\ProductPricingAndStock;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
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
use EasyCo\Catalog\Variation;
use EasyCo\Extensibility\Hook;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VideoCountGuard;
use EasyCo\Staff\Enums\Permission;
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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * VARIABLE-product edit scaffold — Step 1 ("real VARIABLE editing"
 * series, admin-panel-design.md §13.1's own follow-on): parent fields,
 * mirroring EditProduct.php's General+Attributes tabs and sidebar. Step
 * 2a (this revision) extends the "Variations" tab from read-only to
 * genuinely editable per-row: sku/barcode/is_purchasable/cost/
 * stock_quantity, plus a bulk-set convenience for cost and stock —
 * see existingVariationsComponents()'s own docblock for the full
 * shape, and updateProduct()'s own for how each is diff-written.
 *
 * EXPLICITLY NOT HERE (separate, later steps, each needing its own
 * design — see this class's own git history / task notes, not
 * repeated per-field below): regular_price/sale_price and the
 * PRODUCT/VARIATION pricing-scope toggle (Step 2b — needs new domain-
 * service work ProductPricingAndStock doesn't have yet); per-variation
 * media (Step 3); adding new variations or extending declared axes
 * (Step 4 — needs declareVariationAxes()'s own redeclaration guard
 * worked out first). size_guide_id is out of scope too — not wired
 * into SIMPLE's own admin UI either.
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
 * inline rather than as a separable chunk), Attributes
 * (ProductResource::attributesTabComponents(), unmodified — widened to
 * `public` for this reuse, a pure visibility change, see that method's
 * own docblock), a third read-only "Variations" tab (this class's own
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
                                ->tabs([
                                    Tab::make(__('products.tabs.general'))
                                        ->schema($this->generalTabComponents()),
                                    Tab::make(__('products.tabs.attributes'))
                                        ->schema(ProductResource::attributesTabComponents()),
                                    Tab::make(__('products.tabs.variations'))
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
                ->helperText(__('products.fields.slug_help'))
                ->required(fn (string $operation): bool => $operation === 'edit'),
            TextInput::make('base_sku')
                ->label(__('products.fields.base_sku'))
                ->helperText(fn (string $operation): string => $operation === 'edit'
                    ? __('products.base_sku_change_warning')
                    : __('products.fields.base_sku_help'))
                ->required(fn (string $operation): bool => $operation === 'edit'),
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
                ->searchable(),
            Select::make('product_group_id')
                ->label(__('products.fields.product_group_id'))
                ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                ->searchable()
                ->required(fn (): bool => (bool) (app(SiteSettingsRepository::class)->get('catalog.product_group_required') ?? false)),
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
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function existingVariationsComponents(): array
    {
        return [
            TextInput::make('bulk_cost')
                ->label(__('products.wizard.variations.bulk_cost'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->dehydrated(false)
                ->visible(fn (): bool => ProductResource::staffHasPermission(Permission::COST_VIEW))
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
                ->disabled(fn (): bool => ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE))
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    foreach (array_keys($get('existing_variations') ?? []) as $key) {
                        $set("existing_variations.{$key}.stock_quantity", $state);
                    }
                }),
            Repeater::make('existing_variations')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->schema([
                    Hidden::make('variation_id'),
                    TextInput::make('label')
                        ->label(__('products.wizard.variations.combination_label'))
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('sku')
                        ->label(__('products.wizard.variations.sku_label')),
                    TextInput::make('barcode')
                        ->label(__('products.fields.barcode')),
                    Toggle::make('is_purchasable')
                        ->label(__('products.fields.is_purchasable')),
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
                ]),
        ];
    }

    /**
     * Mirrors EditProduct::mutateFormDataBeforeFill()'s own seeding for
     * categories/tags/descriptive_attributes/main_photo/gallery_photos/
     * video/video_autoplay exactly (same logic, this product's own id)
     * — see that method's own docblock for the reasoning behind each.
     * findByIdWithVariations() (not findById()): the real $variations
     * array is what existing_variations below is built from.
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

        $descriptive = [];
        foreach ($product->descriptiveAttributes() as $definitionId => $value) {
            $descriptive[$definitionId] = ProductResource::normalizeCurrentDescriptiveValue($value);
        }
        $data['descriptive_attributes'] = $descriptive;

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

        $data['existing_variations'] = array_map(
            fn (Variation $variation): array => [
                'variation_id' => $variation->id(),
                'label' => $this->variationLabel($variation),
                'sku' => $variation->sku(),
                'barcode' => $variation->barcode(),
                'is_purchasable' => $variation->isPurchasable(),
                'cost' => $pricingAndStock->costDisplay($variation->id()),
                'stock_quantity' => $pricingAndStock->stockQuantity($variation->id()),
            ],
            $product->variations()
        );

        return $data;
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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => $this->updateProduct($record, $data));
    }

    /**
     * Mirrors EditProduct::updateProduct()'s own diff-then-write pattern
     * exactly, for the fields this page's own form actually has. Parent
     * fields first (unchanged from Step 1), then per-variation sku/
     * barcode/is_purchasable/cost/stock_quantity — see
     * updateVariationRows()'s own docblock for that part. Still no
     * regular_price/sale_price reads or writes (Step 2b), and
     * declareAxes()/addStandardVariation()/adding new variations are
     * likewise untouched here — separate, later steps, per this class's
     * own docblock.
     */
    private function updateProduct(Model $record, array $data): Model
    {
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $record->id);

        if ($product === null) {
            throw new RuntimeException("Product \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        $logger = app(ActivityLogger::class);

        if ($product->name() !== $data['name']) {
            $logger->logFieldChanged('product', $product->id(), 'name', $product->name(), $data['name']);
            $product->rename($data['name']);
        }

        if ($product->slug() !== $data['slug']) {
            $logger->logFieldChanged('product', $product->id(), 'slug', $product->slug(), $data['slug']);
            $product->changeSlug($data['slug']);
        }

        if ($product->baseSku() !== $data['base_sku']) {
            $logger->logFieldChanged('product', $product->id(), 'base_sku', $product->baseSku(), $data['base_sku']);
            $product->changeBaseSku($data['base_sku']);
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

        if ($oldStatus !== $newStatus) {
            $logger->logFieldChanged('product', $product->id(), 'status', $oldStatus, $newStatus);

            // Product::publish()'s own real guard
            // (CannotPublishEmptyVariableProductException) requires at
            // least one non-archived STANDARD variation — always true
            // right after this product's own creation, but a merchant
            // could later archive every variation and then try to
            // reactivate the product here. Same Notification+Halt
            // pattern CreateVariableProduct::createProduct() already
            // established; archive()/markAsDraft() never throw this
            // exception (only publish() has this guard), so wrapping
            // the whole match() behaves identically to scoping the
            // catch to only the ACTIVE branch, with less branching.
            try {
                match ($newStatus) {
                    ProductStatus::ACTIVE->value => $product->publish(),
                    ProductStatus::ARCHIVED->value => $product->archive(),
                    default => $product->markAsDraft(),
                };
            } catch (CannotPublishEmptyVariableProductException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
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

        foreach (ProductResource::descriptiveAttributeDefinitions() as $definitionModel) {
            $definitionId = (string) $definitionModel->id;
            $currentRaw = $product->descriptiveAttributes()[$definitionId] ?? null;
            $currentNormalized = ProductResource::normalizeCurrentDescriptiveValue($currentRaw);

            $submittedRaw = $data['descriptive_attributes'][$definitionId] ?? null;
            $submittedNormalized = ProductResource::normalizeSubmittedDescriptiveValue($definitionModel, $submittedRaw);

            if ($submittedNormalized === $currentNormalized) {
                continue;
            }

            $logger->logFieldChanged('product', $product->id(), $definitionModel->code, $currentNormalized, $submittedNormalized);

            $definition = ProductResource::toDomainAttributeDefinition($definitionModel);

            if ($submittedNormalized === null) {
                $product->removeDescriptiveAttribute($definition);

                continue;
            }

            ProductResource::applyDescriptiveAttribute($product, $definitionModel, $submittedRaw);
        }

        // BEFORE save() below, not after — EloquentProductRepository::save()
        // itself iterates $product->variations() and persists each one
        // (confirmed against its own real source), the same cascading
        // save CreateVariableProduct::addStandardVariations() already
        // relies on. Mutating the in-memory Variation objects here means
        // this single save() call below persists both the parent fields
        // above AND these per-row changes together.
        $this->updateVariationRows($product, $data['existing_variations'] ?? [], $logger);

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

        // cost/stock_quantity, AFTER the product/variation save() above
        // — ProductPricingAndStock composes separate EasyCo\Pricing/
        // EasyCo\Inventory repositories, entirely independent of
        // ProductRepository::save(), same ordering EditProduct's own
        // updatePricingAndStock() already establishes for the SIMPLE
        // flow.
        $this->updateVariationPricingAndStock($product->variations(), $data['existing_variations'] ?? [], $logger, $product->id());

        return ProductModel::find($product->id());
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
     * Mirrors EditProduct::updatePricingAndStock()'s own real
     * permission-gate-BEFORE-reading-$data pattern exactly — checking
     * the permission before ever reading that row's own $data value is
     * what actually matters (a merely-disabled field's value still
     * dehydrates into $data on submit, confirmed against Filament's own
     * isDehydrated() — see priceStockTabComponents()'s own docblock for
     * the full, already-found gap this guards against), not the diff
     * itself. Applied per row now instead of once for a single
     * universal Variation.
     *
     * @param Variation[] $variations
     * @param array<int, array{variation_id?: mixed, cost?: mixed, stock_quantity?: mixed}> $rows
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
}
