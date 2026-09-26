<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\BrandResource\Pages\ViewBrand;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Brand management — admin-panel-design.md §10, catalog-domain-design.md
 * §3.12. Same "row navigates to View, Edit is a separate gated action"
 * shape RoleResource established (mirrored, not reinvented). Gated by
 * PRODUCT_VIEW for browsing (Product Entry can see the taxonomy while
 * building a variable product) and TAXONOMY_MANAGE for create/edit —
 * routes/api.php's own existing decision, now extended with edit since
 * Brand has real mutators as of the previous task.
 *
 * No delete action — Brand has no delete() domain method.
 */
class BrandResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = BrandModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    public static function getModelLabel(): string
    {
        return __('brands.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('brands.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 60;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    /**
     * Catalog settings' "Brand field enabled" toggle
     * (ProductResource::brandFieldEnabled()) — when the merchant turns
     * Brand off, this Resource disappears from the nav (Resource's own
     * canAccess() = canViewAny(), confirmed against the installed
     * HasAuthorization source) AND a direct URL hit 403s, because
     * ListBrands::authorizeAccess() already calls this same
     * canViewAny() (a real, pre-existing gap Filament's own
     * shouldRegisterNavigation() docblock flags — "hiding from
     * navigation does NOT prevent direct URL access" — already closed
     * here the same way ListRoles/ListProductGroups do it). Overrides
     * the trait's own one-line canViewAny() rather than calling
     * parent:: (it is a trait method on THIS class, not an inherited
     * one) — same explicit-redefinition posture
     * AuthorizesViaStaffPermission's own docblock already establishes
     * for a Page's local canAccess().
     */
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission())
            && ProductResource::brandFieldEnabled();
    }

    protected static function viewPermission(): ?Permission
    {
        return static::viewAnyPermission();
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    /**
     * Delete is now real (admin-panel-design.md's Part B) — same
     * TAXONOMY_MANAGE gate as create/edit. The actual protection
     * against deleting an in-use Brand is the countProductsUsing()
     * check inside deleteAction() below, not this permission gate —
     * this only controls who can attempt it at all.
     */
    protected static function deletePermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('brands.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('brands.fields.slug'))
                ->required()
                ->unique(table: 'catalog_brands', column: 'slug', ignoreRecord: true),
            FileUpload::make('logo')
                ->label(__('brands.fields.logo'))
                ->image()
                ->disk(config('services.media.default_disk', 'public'))
                // Bypasses FileUpload's own storeAs()/directory()/filename
                // generation entirely — routes the real bytes through the
                // exact same EasyCo\Media\Contracts\MediaStorageAdapter::
                // store() call MediaController uses for every other
                // upload in this app, so a Brand logo ends up on the same
                // disk, under the same uploads/{Y}/{m}/{uuid}.{ext}
                // convention, not a parallel path invented here.
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    return app(MediaStorageAdapter::class)
                        ->store($file->get(), $file->getClientOriginalName())
                        ->path;
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->label(__('brands.fields.logo'))
                    ->disk(config('services.media.default_disk', 'public'))
                    ->state(fn (BrandModel $record): ?string => static::logoPath($record))
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label(__('brands.fields.products_count'))
                    ->state(fn (BrandModel $record): int => app(BrandRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('brands.products_count.count', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its existing 'brand_id' SelectFilter (Filament's real
                    // #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    // 'status' => 'all': this count is every product using the
                    // brand, whatever its status, so the drill-down opens the one
                    // view whose rows can match the number (the products list
                    // itself defaults to Active).
                    ->url(fn (BrandModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', [
                            'status' => 'all',
                            'filters' => ['brand_id' => ['value' => $record->id]],
                        ])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (BrandModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (BrandModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('brands.fields.name')),
            ImageEntry::make('logo')
                ->label(__('brands.fields.logo'))
                ->disk(config('services.media.default_disk', 'public'))
                ->state(fn (BrandModel $record): ?string => static::logoPath($record)),
            TextEntry::make('slug')
                ->label(__('brands.fields.slug')),
            // No ->label() override — mirrors RoleResource's own table()/
            // infolist() created_at columns, which leave this one to
            // Filament's auto-derived "Created at" untranslated too.
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'view' => ViewBrand::route('/{record}'),
            'edit' => EditBrand::route('/{record}/edit'),
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
        ];
    }

    /**
     * Shared by table() and ViewBrand's header actions — cancels the
     * delete and shows a friendly, translated notification instead of
     * letting it proceed whenever countProductsUsing() > 0. brand_id's
     * real FK is nullOnDelete() (confirmed in the prior task), so
     * nothing at the DB level itself would actually block this delete
     * — this app-layer check is the real, only protection, not a
     * backstop. Any other, genuinely unexpected QueryException is
     * still converted to the same friendly message rather than a raw
     * 500.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (BrandModel $record): bool => static::canDelete($record))
            ->action(function (BrandModel $record): void {
                $repository = app(BrandRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('brands.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('brands.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('brands.deleted'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The single call site resolving a BrandModel's logo path from its
     * logo_media_asset_id — used by the table column, the infolist
     * entry, and EditBrand's own mutateFormDataBeforeFill(), so all
     * three can never drift from each other.
     */
    public static function logoPath(BrandModel $record): ?string
    {
        if ($record->logo_media_asset_id === null) {
            return null;
        }

        return MediaAssetModel::find($record->logo_media_asset_id)?->path;
    }

    /**
     * Shared by CreateBrand and EditBrand — mirrors MediaController's
     * own MediaAsset::create()/MediaAssetRepository::save()/job-dispatch
     * sequence exactly (§3.6: dispatch only for images, which a Brand
     * logo always is per this field's ->image() constraint).
     */
    public static function createLogoMediaAsset(string $storedPath): MediaAsset
    {
        $disk = config('services.media.default_disk', 'public');

        $asset = MediaAsset::create(MediaType::IMAGE, $disk, $storedPath);
        app(MediaAssetRepository::class)->save($asset);

        ProcessMediaAssetJob::dispatch($asset->id());

        return $asset;
    }
}
