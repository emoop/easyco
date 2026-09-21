<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * VARIABLE product creation wizard, Step A (admin-panel-design.md
 * §13.1) — scaffold + the "General" step only. Ends in a real,
 * persisted, bare VARIABLE Product: zero variations, zero declared
 * axes. Axes/Variations grid/price/stock are separate, later steps —
 * not attempted here.
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

        app(ProductRepository::class)->save($product);

        $productId = $product->id();

        app(ActivityLogger::class)->logCreated('product', $productId);

        return ProductModel::find($productId);
    }
}
