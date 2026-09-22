<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Services\ArchiveProductMediaCleaner;
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
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Variation;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\VideoCountGuard;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * VARIABLE-product edit scaffold — Step 1 of the "real VARIABLE
 * editing" series (admin-panel-design.md §13.1's own follow-on). Parent
 * fields only, mirroring EditProduct.php's General+Attributes tabs and
 * sidebar, PLUS a read-only list of this product's existing Variations.
 *
 * EXPLICITLY NOT HERE (separate, later steps, each needing its own
 * design — see this class's own git history / task notes, not
 * repeated per-field below): per-variation price/cost/stock/barcode/
 * is_purchasable; per-variation media; adding new variations or
 * extending declared axes (needs declareVariationAxes()'s own
 * redeclaration guard worked out first). size_guide_id is out of scope
 * too — not wired into SIMPLE's own admin UI either.
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
     * A read-only display of this product's real, persisted Variations
     * — NOT a preview, NOT editable from this step (per-variation
     * price/cost/stock/barcode/is_purchasable editing and adding new
     * variations are separate, later steps). Every field disabled;
     * ->dehydrated(false) on the whole Repeater — this step's own
     * submit never needs to read 'existing_variations' back at all (see
     * updateProduct()'s own docblock: it only ever reads the parent-
     * level $data keys this class's own form defines), so keeping it
     * out of the dehydrated payload entirely is both the simplest
     * choice and a real guard against ever accidentally trusting a
     * round-tripped copy of this data over the real domain object.
     *
     * The Hidden 'variation_id' carries the real persisted id forward
     * for a later step to key off of — not recomputed from the
     * combination the way CreateVariableProduct's own wizard preview
     * has to (that page has no persisted ids yet at preview time; this
     * page always does).
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function existingVariationsComponents(): array
    {
        return [
            Repeater::make('existing_variations')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->disabled()
                ->dehydrated(false)
                ->schema([
                    Hidden::make('variation_id'),
                    TextInput::make('label')
                        ->label(__('products.wizard.variations.combination_label'))
                        ->disabled(),
                    TextInput::make('sku')
                        ->label(__('products.wizard.variations.sku_label'))
                        ->disabled(),
                    TextInput::make('barcode')
                        ->label(__('products.fields.barcode'))
                        ->disabled(),
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

        $data['existing_variations'] = array_map(
            fn (Variation $variation): array => [
                'variation_id' => $variation->id(),
                'label' => $this->variationLabel($variation),
                'sku' => $variation->sku(),
                'barcode' => $variation->barcode(),
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
     * exactly, for the fields this page's own form actually has — no
     * universalVariation()/barcode/is_purchasable/price/cost/stock
     * reads or writes at all (a VARIABLE product has no universal
     * Variation; that data isn't in this step's form to begin with).
     * declareAxes()/addStandardVariation()/per-variation edits are
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

        return ProductModel::find($product->id());
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
