<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Settings\Contracts\SiteSettingsRepository;
use BackedEnum;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
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
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Product')
                ->tabs([
                    Tab::make(__('products.tabs.general'))
                        ->schema(static::generalTabComponents()),
                    Tab::make(__('products.tabs.attributes'))
                        ->schema(static::attributesTabComponents()),
                    Tab::make(__('products.tabs.media'))
                        ->schema(static::mediaTabComponents()),
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
            Textarea::make('description')
                ->label(__('products.fields.description')),
            Select::make('status')
                ->label(__('products.fields.status'))
                ->options([
                    ProductStatus::DRAFT->value => __('products.status_options.draft'),
                    ProductStatus::ACTIVE->value => __('products.status_options.active'),
                    ProductStatus::ARCHIVED->value => __('products.status_options.archived'),
                ])
                ->default(ProductStatus::DRAFT->value)
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
            Select::make('season_id')
                ->label(__('products.fields.season_id'))
                ->options(fn (): array => SeasonModel::pluck('name', 'id')->all())
                ->searchable(),
            Select::make('product_group_id')
                ->label(__('products.fields.product_group_id'))
                ->options(fn (): array => ProductGroupModel::pluck('name', 'id')->all())
                ->searchable()
                // Read fresh on each render, not cached at class-load
                // time — mirrors why getModelLabel() etc. are methods,
                // not static properties (admin-panel-design.md §13.4).
                ->required(fn (): bool => (bool) (app(SiteSettingsRepository::class)->get('catalog.product_group_required') ?? false)),
            Select::make('categories')
                ->label(__('products.fields.categories'))
                ->multiple()
                ->options(fn (): array => CategoryModel::pluck('name', 'id')->all())
                ->searchable(),
            Select::make('tags')
                ->label(__('products.fields.tags'))
                ->multiple()
                ->options(fn (): array => TagModel::pluck('name', 'id')->all())
                ->searchable(),
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

    /** @return array<int, Component> */
    protected static function mediaTabComponents(): array
    {
        return [
            FileUpload::make('photos')
                ->label(__('products.fields.photos'))
                ->multiple()
                ->reorderable()
                ->image()
                // Deliberately NO ->maxFiles(): confirmed against a real
                // failing test that Filament's own maxFiles() is hard,
                // blocking Livewire field validation — it would reject
                // an over-limit submission with its own generic message
                // ("must not have more than N items") before
                // handleRecordCreation() ever runs, making
                // ProductMediaCountGuard's real, specific
                // MediaLimitExceededException message (what this task
                // explicitly requires the user to see) unreachable. The
                // guard inside CreateProduct::attachPhotos()/
                // EditProduct::syncPhotos() is the one and only real
                // enforcement point now, matching the API's own
                // ProductMediaController::store() behavior exactly.
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
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
                    ->label(__('products.fields.catalog_visibility'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("products.visibility_options.{$state}"))
                    ->color(fn (string $state): string => $state === CatalogVisibility::VISIBLE->value ? 'success' : 'gray'),
                TextColumn::make('brand.name')
                    ->label(__('products.fields.brand_id')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
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
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ProductModel $record): bool => static::canEdit($record)),
            ])
            ->recordUrl(fn (ProductModel $record): string => static::getUrl('view', ['record' => $record]));
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
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
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
     * ProcessMediaAssetJob::dispatch()), reused here per uploaded photo
     * instead of once for a single logo.
     */
    public static function createMediaAsset(string $storedPath): MediaAsset
    {
        $disk = config('services.media.default_disk', 'public');

        $asset = MediaAsset::create(MediaType::IMAGE, $disk, $storedPath);
        app(MediaAssetRepository::class)->save($asset);

        ProcessMediaAssetJob::dispatch($asset->id());

        return $asset;
    }
}
