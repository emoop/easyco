<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
 * Step A's "General" step plus Step B's "Axes" step. Ends in a real,
 * persisted VARIABLE Product with its declared axes (if any)
 * persisted via the real domain layer — but still zero
 * catalog_variations rows: generating actual Variations from the
 * declared axes is Step C, not attempted here.
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
                            Select::make('value_ids')
                                ->label(__('products.wizard.axes.values_label'))
                                ->multiple()
                                ->searchable()
                                ->required()
                                ->options(fn (Get $get): array => AttributeValueModel::where('attribute_definition_id', $get('attribute_definition_id'))
                                    ->pluck('value', 'id')
                                    ->all()),
                        ])
                        ->itemLabel(fn (array $state): ?string => filled($state['attribute_definition_id'] ?? null)
                            ? AttributeDefinitionModel::find($state['attribute_definition_id'])?->name
                            : null),
                ]),
        ];
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

        match ($data['status'] ?? ProductStatus::DRAFT->value) {
            ProductStatus::ACTIVE->value => $product->publish(),
            ProductStatus::ARCHIVED->value => $product->archive(),
            default => $product->markAsDraft(),
        };

        $product->setCatalogVisibility(CatalogVisibility::from($data['catalog_visibility'] ?? CatalogVisibility::HIDDEN->value));
        $product->assignBrand($data['brand_id'] ?? null);
        $product->assignProductGroup($data['product_group_id'] ?? null);

        $this->declareAxes($product, $data['axes'] ?? []);

        app(ProductRepository::class)->save($product);

        $productId = $product->id();

        app(ActivityLogger::class)->logCreated('product', $productId);

        return ProductModel::find($productId);
    }

    /**
     * Mirrors VariableProductController::store()'s own real axis-
     * construction + declareVariationAxes() sequence exactly — same
     * two-tier catch (InvalidVariationAxisException around the whole
     * construction loop; a nested catch(\LogicException) scoped to
     * ONLY the declareVariationAxes() call itself, for the "same
     * definition declared twice" case — see that controller's own
     * docblock for why this catch must stay narrowly scoped rather
     * than widened). The one real difference: this is a Filament page,
     * not a JSON API, so a caught exception becomes a Notification +
     * Halt (CreateProduct::attachMedia()'s own established precedent
     * in this same panel), never a 422 response.
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
        $axes = [];

        try {
            foreach ($axesInput as $axisInput) {
                $attributeDefinitionId = (string) ($axisInput['attribute_definition_id'] ?? '');

                $definition = app(AttributeDefinitionRepository::class)->findById($attributeDefinitionId);
                $values = array_map(
                    fn ($valueId) => app(AttributeValueRepository::class)->findById((string) $valueId),
                    $axisInput['value_ids'] ?? []
                );

                $axes[] = new VariationAxis($definition, $values);
            }

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
}
