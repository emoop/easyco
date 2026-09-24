<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use EasyCo\Extensibility\Hook;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * VARIABLE product creation wizard (admin-panel-design.md §13.1) —
 * complete: General (Step A), Axes (Step B), Variations (Step C).
 * Ends in a real, persisted VARIABLE Product with its declared axes
 * and real, correctly-statused Variations — functionally complete per
 * §13.1.
 *
 * WHERE IT ENDS IS DELIBERATE: the merchant is redirected into
 * EditVariableProduct's own Variations tab (getRedirectUrl() below),
 * never Filament's default 'view' page. The wizard deliberately creates
 * no prices and no stock, so the price & stock screen — which IS that
 * tab — is the only place this flow can sensibly stop.
 *
 * PREVIEW GENERATION HAPPENS IN THE AXES STEP'S OWN
 * ->afterValidation(), NOT THE VARIATIONS STEP — the cartesian product
 * is built once, the moment the merchant leaves Axes (confirmed
 * against Wizard::nextStep()'s real source: callBeforeValidation() ->
 * getChildSchema()->validate() -> callAfterValidation() ->
 * $nextStep?->fillStateWithNull() — that ordering is exactly why this
 * is safe: fillStateWithNull() only nulls a field when
 * Arr::has($livewire->data, $path) is false, and afterValidation()'s
 * own $set('variations', ...) has already populated that path by the
 * time fillStateWithNull() runs on the Variations step immediately
 * after). ->hasSkippableSteps() is deliberately left at HasWizard's
 * own default (false) — the "regenerate exactly once, via Next" design
 * depends on steps only being reachable in order.
 *
 * `form()` is already wired by HasWizard (it builds the Wizard schema
 * component from getSteps() itself) — no manual form()/Wizard
 * component override here, per that trait's own real source
 * (Filament\Resources\Pages\Concerns\HasWizard::form()).
 *
 * The "General" step's fields are authored fresh for this class, NOT
 * ProductResource::generalTabComponents() — that method includes
 * barcode/is_purchasable, which live on the (nonexistent, at this
 * step) universal Variation for a VARIABLE product, and refactoring a
 * method the existing SIMPLE flow/tests depend on is out of scope
 * here. categories/tags/season/media (SIMPLE's own "sidebar" fields)
 * are deliberately NOT included — admin-panel-design.md §13.1 only
 * specifies the General TAB's own fields; whether/where those belong
 * in this wizard is an open question for a later step.
 *
 * WRAPPED IN ITS OWN DB::transaction() — mirrors CreateProduct's own
 * identical gap/fix exactly (same panel, same cause): Filament's own
 * transaction wrapping is a no-op here because
 * Panel::hasDatabaseTransactions() defaults to false and
 * AdminPanelProvider never opts in. See CreateProduct's own docblock
 * for the full reasoning; nothing about it differs for this page.
 */
class CreateVariableProduct extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ProductResource::class;

    /**
     * WHERE CREATION ENDS: the Variations tab of the EDIT page, not
     * Filament's own 'view' default.
     *
     * Filament's default (CreateRecord::getRedirectUrl()) sends the
     * merchant to 'view' whenever the resource has that page and
     * canView() holds — confirmed against the installed source. That is
     * wrong for THIS wizard, and it was already wrong before this
     * change: the wizard creates axes and variations only, so the View
     * page shows a product with no price and no stock and nothing to
     * click, and the merchant had to go back to the product list, find
     * the product they had just created and reopen it before they could
     * do the one thing left to do. The natural end of this flow is the
     * price & stock screen, which IS EditVariableProduct's Variations
     * tab (its bulk cost/stock fields plus every row's own
     * cost/stock/regular/sale price and photos).
     *
     * $this->getRecord() is safe to read here: CreateRecord::create()
     * assigns the record BEFORE asking for the redirect URL (installed
     * source, confirmed).
     *
     * The canEdit() guard is defense-in-depth, not a second real
     * boundary — this page's own createPermission() already implies
     * PRODUCT_MANAGE — but redirecting into a page the user cannot open
     * would be a worse failure than falling back to Filament's own
     * default.
     */
    protected function getRedirectUrl(): string
    {
        if (! ProductResource::canEdit($this->getRecord())) {
            return parent::getRedirectUrl();
        }

        return ProductResource::getUrl('edit-variable', [
            'record' => $this->getRecord(),
            'tab' => ProductResource::VARIATIONS_TAB_ID,
        ]);
    }

    /**
     * PUBLIC ONLY FOR ITS OWN TEST — the same visibility-widening
     * precedent ProductResource::sidebarComponents() already
     * established. No production caller reads this directly; Filament
     * calls it itself, once the record has been created.
     *
     * Replaces Filament's plain "created" toast with one that says what
     * the merchant still has to do and offers a direct link to the new
     * product's read-only View page — so the overview stays one click
     * away without leaving the price & stock screen this flow redirects
     * to. The notification is sent BEFORE that redirect
     * (CreateRecord::create()'s own order, confirmed against the
     * installed source) and is therefore displayed on the page the
     * merchant lands on.
     */
    public function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('products.created_notification.title'))
            ->body(__('products.created_notification.body'))
            ->success()
            ->actions([
                Action::make('view')
                    ->label(__('products.created_notification.view_action'))
                    ->url(ProductResource::getUrl('view', ['record' => $this->getRecord()]))
                    ->button()
                    ->markAsRead(),
            ]);
    }

    public function getSteps(): array
    {
        return [
            Step::make(__('products.wizard.steps.general'))
                ->schema([
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
                ]),
            Step::make(__('products.wizard.steps.axes'))
                ->schema([
                    // Deliberately NOT ->required()/->minItems(1) — zero
                    // rows is a legal, submittable state (Step A's own
                    // tests create a product with no declared axes at
                    // all; that must keep working unchanged).
                    Repeater::make('axes')
                        ->hiddenLabel()
                        // Filament's own Repeater::setUp() defaults to
                        // ->defaultItems(1) — a real, confirmed
                        // regression found by running Step A's own
                        // existing tests against this step: a phantom,
                        // empty, unfillable row appeared on every fresh
                        // wizard mount, tripping this row's own
                        // ->required() fields on submit even when the
                        // merchant never touched this step at all.
                        // Explicit ->defaultItems(0) is what actually
                        // keeps "zero rows" the real default, legal,
                        // submittable state this task requires.
                        ->defaultItems(0)
                        ->addActionLabel(__('products.wizard.axes.add_axis'))
                        // Deliberately NOT excluding an
                        // attribute_definition_id already chosen in
                        // another row from this row's own options() —
                        // a Repeater-level nicety this task's own
                        // instructions offered as optional, skipped
                        // here: reaching a SIBLING repeater ITEM's own
                        // state (not just a sibling FIELD within the
                        // same item, which value_ids's own ->options()
                        // below does need and does do) needs a relative
                        // Get() path escaping the current item's own
                        // container, and I did not have a confirmed,
                        // tested-in-this-codebase syntax for that to
                        // rely on without real risk of silently
                        // resolving to the wrong scope. The backend
                        // catch in createProduct() below is the
                        // mandatory correctness backstop either way, so
                        // this is purely a UX nicety left for a later
                        // pass, not a correctness gap.
                        ->schema([
                            Select::make('attribute_definition_id')
                                ->label(__('products.wizard.axes.attribute_label'))
                                ->options(fn (): array => AttributeDefinitionModel::where('type', AttributeType::SELECT->value)->pluck('name', 'id')->all())
                                ->searchable()
                                ->required()
                                ->live()
                                // NOT optional polish — a stale value_ids
                                // referencing the PREVIOUS definition
                                // would otherwise trip VariationAxis's
                                // own real valueBelongsToWrongDefinition
                                // check at submit.
                                ->afterStateUpdated(fn (Set $set) => $set('value_ids', [])),
                            CheckboxList::make('value_ids')
                                ->label(__('products.wizard.axes.values_label'))
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
                ])
                ->afterValidation(fn (Get $get, Set $set) => $this->generateVariationPreview($get, $set)),
            Step::make(__('products.wizard.steps.variations'))
                // The wizard ends here, but the PRODUCT is not finished:
                // no price and no stock exists yet for any variation it
                // creates (deliberately — see this class's own docblock
                // and admin-panel-design.md §13.1). Saying so on the step
                // itself, rather than only after the fact, is what makes
                // the redirect below legible: the merchant knows before
                // pressing Create where they will land next.
                ->description(__('products.wizard.variations.after_create_help'))
                ->schema([
                    Toggle::make('activate_all')
                        ->label(__('products.wizard.variations.activate_all'))
                        ->default(false)
                        // Never part of $data['variations'] itself — a
                        // pure UI convenience that writes into each
                        // row's own is_active via $set() below, not a
                        // real field submitted on its own.
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                            foreach (array_keys($get('variations') ?? []) as $key) {
                                $set("variations.{$key}.is_active", $state);
                            }
                        }),
                    // Same defaultItems(0) fix as Step B's own axes
                    // Repeater, and the same real, confirmed cause —
                    // Filament's Repeater::setUp() always defaults to
                    // defaultItems(1) regardless of instance. Verified
                    // separately for THIS Repeater (not just assumed
                    // from Step B's own fix) via a real test: without
                    // this, a phantom row appears even when no axes
                    // were ever declared (generateVariationPreview()
                    // would set 'variations' to [] for a no-axes
                    // product, but the phantom default row still shows
                    // up underneath it on initial mount, before any
                    // afterValidation() has run).
                    Repeater::make('variations')
                        ->hiddenLabel()
                        ->defaultItems(0)
                        // Rows only ever come from generation
                        // (afterValidation() on the Axes step) — a
                        // merchant never manually adds one.
                        ->addable(false)
                        // Left at Filament's own default (true) —
                        // deleting a row means "don't create this
                        // specific variation," which needs no extra
                        // logic: a deleted row is simply absent from
                        // $data['variations'] at submit.
                        ->deletable()
                        ->reorderable(false)
                        ->schema([
                            TextInput::make('label')
                                ->label(__('products.wizard.variations.combination_label'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('sku')
                                ->label(__('products.wizard.variations.sku_label'))
                                ->required(),
                            TextInput::make('barcode')
                                ->label(__('products.fields.barcode')),
                            Toggle::make('is_active')
                                ->label(__('products.wizard.variations.active_label'))
                                ->default(false),
                        ]),
                ]),
        ];
    }

    /**
     * The Axes step's own ->afterValidation() — NOT the Variations
     * step. Locks in base_sku/slug right now (not just at final
     * submit): without this, SKU previews would be built against a
     * possibly-blank base_sku, then a DIFFERENT base_sku could get
     * generated at final submit, making the previewed SKUs wrong/
     * stale. Safe to do here — Hook::apply('catalog.product.base_sku',
     * $nonEmptyValue) already returns non-empty input unchanged
     * (CatalogSkuGeneratorServiceProvider's own real listener), so
     * createProduct()'s own existing resolution call downstream simply
     * becomes a no-op the second time; Steps A/B's own existing tests
     * (which fillForm()+call('create') directly, never navigating the
     * wizard) never trigger this closure at all, so they are
     * completely unaffected.
     *
     * Builds a THROWAWAY, never-saved Product purely to run the real
     * VariationCombinationGenerator against it — reused as-is, not
     * reimplemented, per this task's own instruction. The real,
     * persisted Product and its real Variations are built fresh again
     * in createProduct() at final submit; this throwaway is discarded
     * the moment this method returns.
     *
     * Deliberately NOT wrapped in its own Notification+Halt —
     * InvalidVariationAxisException here would mean the Axes step's
     * own SELECT-only, per-row-scoped, all-required() UI somehow
     * produced an invalid axis anyway, a near-impossible edge case
     * given those constraints. Left uncaught, same risk tolerance
     * already accepted for Step A's own flagged gaps — not a new
     * pattern.
     */
    private function generateVariationPreview(Get $get, Set $set): void
    {
        $baseSku = Hook::apply('catalog.product.base_sku', $get('base_sku') ?? '');
        $slug = Hook::apply('catalog.product.slug', $get('slug') ?? '', $get('name') ?? '');
        $set('base_sku', $baseSku);
        $set('slug', $slug);

        $axes = ProductResource::buildVariationAxesFromInput($get('axes') ?? []);

        $throwaway = Product::createVariable($get('name') ?? '', $baseSku, $slug);
        $throwaway->declareVariationAxes($axes);

        $axisValueIdsByAttributeDefinitionId = [];
        $definitionNames = [];
        $valueNames = [];

        foreach ($axes as $axis) {
            $definitionId = $axis->attributeDefinitionId();
            $axisValueIdsByAttributeDefinitionId[$definitionId] = $axis->allowedValueIds();
            // VariationAxis only exposes attributeDefinitionCode(), not
            // the real human name — confirmed against that class's own
            // source. AttributeDefinitionModel::find() is the real
            // name; the code is kept as a last-resort fallback only if
            // the row were somehow gone by now (a real, if unlikely,
            // TOCTOU gap — flagged, not solved, mirroring this
            // wizard's existing risk posture elsewhere).
            $definitionNames[$definitionId] = AttributeDefinitionModel::find($definitionId)?->name ?? $axis->attributeDefinitionCode();

            foreach ($axis->allowedValues() as $value) {
                $valueNames[$value->id()] = $value->value();
            }
        }

        $variations = (new VariationCombinationGenerator())->generate(
            $throwaway,
            $axisValueIdsByAttributeDefinitionId,
            CatalogSkuGeneratorServiceProvider::variationSkuStrategy($throwaway)
        );

        $rows = [];

        foreach ($variations as $variation) {
            $labelParts = [];

            foreach ($variation->attributeAssignments() as $definitionId => $valueId) {
                $definitionName = $definitionNames[(string) $definitionId] ?? (string) $definitionId;
                $valueName = $valueNames[(string) $valueId] ?? (string) $valueId;
                $labelParts[] = "{$definitionName}: {$valueName}";
            }

            $rows[] = [
                'combination_json' => json_encode($variation->attributeAssignments()),
                'label' => implode(', ', $labelParts),
                'sku' => $variation->sku(),
                'barcode' => '',
                'is_active' => false,
            ];
        }

        $set('variations', $rows);
        $set('activate_all', false);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn (): Model => $this->createProduct($data));
    }

    private function createProduct(array $data): Model
    {
        $baseSku = Hook::apply('catalog.product.base_sku', $data['base_sku'] ?? '');
        $slug = Hook::apply('catalog.product.slug', $data['slug'] ?? '', $data['name']);

        $product = Product::createVariable($data['name'], $baseSku, $slug);

        if (filled($data['description'] ?? null)) {
            $product->changeDescription($data['description']);
        }

        $product->setCatalogVisibility(CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value));
        $product->assignBrand($data['brand_id'] ?? null);
        $product->assignProductGroup($data['product_group_id'] ?? null);

        // Must run before addStandardVariation() below — those calls
        // validate every combination against $product's own declared
        // axes.
        $this->declareAxes($product, $data['axes'] ?? []);

        $this->addStandardVariations($product, $data['variations'] ?? []);

        // MOVED here from right after createVariable() — a real,
        // load-bearing reorder, not cosmetic: Product::publish()'s own
        // guard (CannotPublishEmptyVariableProductException) requires
        // at least one real, non-archived STANDARD variation to
        // already exist on a VARIABLE product, which is only true once
        // addStandardVariations() above has run. This is the Step A
        // gap flagged and deferred at the time — closed here now that
        // real Variations exist to check against. Wrapping the WHOLE
        // match(), not just the ACTIVE branch: archive()/markAsDraft()
        // never throw this exception (confirmed against Product::
        // publish()'s own real guard — only that one method has it),
        // so a single try/catch around the whole statement behaves
        // identically to one scoped to only the ACTIVE branch, with
        // less branching to read.
        try {
            match ($data['status'] ?? ProductStatus::DRAFT->value) {
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

        app(ProductRepository::class)->save($product);

        $productId = $product->id();

        app(ActivityLogger::class)->logCreated('product', $productId);

        return ProductModel::find($productId);
    }

    /**
     * Mirrors VariableProductController::store()'s own real axis-
     * construction + declareVariationAxes() sequence exactly — same
     * two-tier catch (InvalidVariationAxisException around the whole
     * construction loop, via buildVariationAxes() below — shared with
     * generateVariationPreview()'s own identical needs — plus a nested
     * catch(\LogicException) scoped to ONLY the declareVariationAxes()
     * call itself, for the "same definition declared twice" case — see
     * that controller's own docblock for why this catch must stay
     * narrowly scoped rather than widened). The one real difference:
     * this is a Filament page, not a JSON API, so a caught exception
     * becomes a Notification + Halt (CreateProduct::attachMedia()'s
     * own established precedent in this same panel), never a 422
     * response.
     *
     * Safe to call unconditionally, even with $axesInput === [] — an
     * empty $axes array passed to declareVariationAxes() is equivalent
     * to never calling it (Product::declareVariationAxes()'s own body:
     * an empty input array leaves $this->variationAxes as an empty
     * array either way), so there is no need to branch on emptiness
     * here.
     *
     * @param array<int, array{attribute_definition_id?: mixed, value_ids?: array<int, mixed>}> $axesInput
     */
    private function declareAxes(Product $product, array $axesInput): void
    {
        try {
            // ProductResource::buildVariationAxesFromInput() — extracted
            // from this method's own former private buildVariationAxes()
            // so EditVariableProduct's own Axes-tab write path shares the
            // exact same construction loop, not a second copy of it.
            $axes = ProductResource::buildVariationAxesFromInput($axesInput);

            try {
                $product->declareVariationAxes($axes);
            } catch (\LogicException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }
        } catch (InvalidVariationAxisException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    /**
     * Persists the real, final Variations from the Variations step's
     * own $data['variations'] rows — independent of how those rows
     * got there (the real generation flow via
     * generateVariationPreview(), or a row submitted directly, e.g. in
     * a test that bypasses wizard navigation entirely). Mirrors this
     * same file's own barcode-hook-wrapping precedent already
     * established for the SIMPLE flow (CreateProduct::createProduct()
     * — 'catalog.variation.barcode', applied here per row instead of
     * once for the single universal Variation).
     *
     * @param array<int, array{combination_json?: mixed, sku?: mixed, barcode?: mixed, is_active?: mixed}> $rows
     */
    private function addStandardVariations(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $combination = json_decode((string) ($row['combination_json'] ?? '[]'), true) ?? [];

            try {
                // ProductResource::writeStandardVariation() — the shared
                // addStandardVariation()+barcode-Hook+activate() core,
                // now also used by EditVariableProduct's own "Add
                // variation" write path. Only DuplicateVariationCombinationException
                // is caught here (unchanged from before this
                // extraction): this row's own combination already
                // passed generateVariationPreview()'s own upfront
                // validation, so InvalidVariationAxisException is not a
                // realistically reachable case for this specific caller.
                ProductResource::writeStandardVariation(
                    $product,
                    $combination,
                    (string) ($row['sku'] ?? ''),
                    $row['barcode'] ?? '',
                    (bool) ($row['is_active'] ?? false)
                );
            } catch (DuplicateVariationCombinationException $e) {
                Notification::make()
                    ->title($e->getMessage())
                    ->danger()
                    ->send();

                throw (new Halt)->rollBackDatabaseTransaction();
            }
        }
    }
}
