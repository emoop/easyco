<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Resources\CollectionResource\Pages\EditCollection;
use App\Filament\Resources\CollectionResource\Pages\ListCollections;
use App\Filament\Resources\CollectionResource\Pages\ViewCollection;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Staff\Enums\Permission;
use App\Filament\Design\InfoHint;
use App\Filament\Forms\Components\MediaPicker;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * P6e-1: «Колекции» — the admin of the collections (name, slug, ONE picture): a clone of SeasonResource whose in-use count is the pivot's (a product has MANY collections).
 * Switched OFF by default (`catalog.collections_enabled`, ProductResource::collectionsEnabled()): invisible in the navigation and refused on a direct URL until the owner turns it on.
 */
class CollectionResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = CollectionModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getModelLabel(): string
    {
        return __('collections.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('collections.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 71;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    /** See BrandResource::canViewAny()'s identical docblock — the «Включи колекциите» toggle (default OFF), the same closed nav+direct-URL gap: with the switch OFF the navigation item is absent AND a direct URL is refused (403). */
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission())
            && ProductResource::collectionsEnabled();
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

    protected static function deletePermission(): ?Permission
    {
        return Permission::TAXONOMY_MANAGE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('collections.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('collections.fields.slug'))
                ->required()
                ->unique(table: 'catalog_collections', column: 'slug', ignoreRecord: true),
            // CT-1.1: ONE picture, chosen from «Медия» (or uploaded into it): any picture type the library offers, never a social image, no minimum size. The design cuts the frame later. «Премахни
            // изображението» only detaches (the file stays in «Медия»), and a save that does not touch the field keeps it.
            MediaPicker::make('picture_media_asset_id')
                ->label(__('collections.fields.picture'))
                ->plainImagesOnly()
                ->afterLabel(InfoHint::make(__('collections.picture_help'), 'picture_media_asset_id')),
        ]);
    }

    /**
     * CT-1.1: collection id => the disk path of its picture's `thumbnail` size (one query for the whole list). A picture still being processed has no size yet: no thumbnail, not a broken image.
     *
     * @return array<int, string>
     */
    public static function pictureThumbnails(): array
    {
        $out = [];

        foreach (DB::table('catalog_collections')->join('catalog_media', 'catalog_media.id', '=', 'catalog_collections.picture_media_asset_id')->get(['catalog_collections.id as id', 'catalog_media.variants as variants']) as $row) {
            foreach ((array) json_decode((string) $row->variants, true) as $variant) {
                if (is_array($variant) && ($variant['tier'] ?? null) === 'thumbnail' && is_string($variant['path'] ?? null)) {
                    $out[(int) $row->id] = $variant['path'];
                }
            }
        }

        return $out;
    }

    public static function table(Table $table): Table
    {
        // the small pictures of the list, found ONCE per render (one query), not once per row
        $thumbnails = static::pictureThumbnails();

        return $table
            ->columns([
                // CT-1.1: as the category and the brand lists — a small 40×40 thumbnail (the `thumbnail` size, never the original)
                ImageColumn::make('picture')
                    ->label(__('collections.fields.picture'))
                    ->disk(config('services.media.default_disk', 'public'))
                    ->state(fn (CollectionModel $record): ?string => $thumbnails[(int) $record->id] ?? null)
                    ->imageHeight(40)
                    ->imageWidth(40)
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label(__('collections.fields.products_count'))
                    ->state(fn (CollectionModel $record): int => app(CollectionRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('collections.products_count.count', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its existing 'collections' SelectFilter (Filament's real
                    // #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    // 'status' => 'all': this count is every product in the
                    // collection, whatever its status, so the drill-down opens the one
                    // view whose rows can match the number (the products list
                    // itself defaults to Active).
                    ->url(fn (CollectionModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', [
                            'status' => 'all',
                            'filters' => ['collections' => ['value' => $record->id]],
                        ])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CollectionModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (CollectionModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('collections.fields.name')),
            TextEntry::make('slug')
                ->label(__('collections.fields.slug')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollections::route('/'),
            'create' => CreateCollection::route('/create'),
            'view' => ViewCollection::route('/{record}'),
            'edit' => EditCollection::route('/{record}/edit'),
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
        ];
    }

    /** See BrandResource::deleteAction()'s identical reasoning — a collection that is in use cannot be deleted (the pivot catalog_product_collections is also cascadeOnDelete(), the database's last guard, like the tag's). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (CollectionModel $record): bool => static::canDelete($record))
            ->action(function (CollectionModel $record): void {
                $repository = app(CollectionRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('collections.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('collections.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('collections.deleted'))
                    ->success()
                    ->send();
            });
    }
}
