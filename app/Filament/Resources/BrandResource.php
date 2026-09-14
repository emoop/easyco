<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\BrandResource\Pages\ViewBrand;
use BackedEnum;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
        return Permission::TAXONOMY_MANAGE;
    }

    protected static function editPermission(): ?Permission
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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (BrandModel $record): bool => static::canEdit($record)),
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
        ];
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
