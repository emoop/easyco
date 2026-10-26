<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\Resources\SeasonResource\Pages\ViewSeason;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Staff\Enums\Permission;
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
 * Season management — admin-panel-design.md §10/Part B, catalog-
 * domain-design.md §3.13. Identical shape to BrandResource minus the
 * logo field entirely (Season has no logo — confirmed by the domain
 * owner). Same permission gating, same List/Create/Edit/View shape,
 * same row-navigates-to-View pattern, same product-count column/
 * drill-down/delete-guard shape as Part B.
 */
class SeasonResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = SeasonModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sun';

    public static function getModelLabel(): string
    {
        return __('seasons.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seasons.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 70;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    /** See BrandResource::canViewAny()'s identical docblock — same "Season field enabled" toggle, same closed nav+direct-URL gap. */
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission())
            && ProductResource::seasonFieldEnabled();
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
                ->label(__('seasons.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('seasons.fields.slug'))
                ->required()
                ->unique(table: 'catalog_seasons', column: 'slug', ignoreRecord: true),
            // CT-1.1: ONE picture, chosen from «Медия» (or uploaded into it): any picture type the library offers, never a social image, no minimum size. The design cuts the frame later. «Премахни
            // изображението» only detaches (the file stays in «Медия»), and a save that does not touch the field keeps it.
            MediaPicker::make('picture_media_asset_id')
                ->label(__('seasons.fields.picture'))
                ->plainImagesOnly()
                ->helperText(__('seasons.picture_help')),
        ]);
    }

    /**
     * CT-1.1: season id => the disk path of its picture's `thumbnail` size (one query for the whole list). A picture still being processed has no size yet: no thumbnail, not a broken image.
     *
     * @return array<int, string>
     */
    public static function pictureThumbnails(): array
    {
        $out = [];

        foreach (DB::table('catalog_seasons')->join('catalog_media', 'catalog_media.id', '=', 'catalog_seasons.picture_media_asset_id')->get(['catalog_seasons.id as id', 'catalog_media.variants as variants']) as $row) {
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
                    ->label(__('seasons.fields.picture'))
                    ->disk(config('services.media.default_disk', 'public'))
                    ->state(fn (SeasonModel $record): ?string => $thumbnails[(int) $record->id] ?? null)
                    ->imageHeight(40)
                    ->imageWidth(40)
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label(__('seasons.fields.products_count'))
                    ->state(fn (SeasonModel $record): int => app(SeasonRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('seasons.products_count.count', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its existing 'season_id' SelectFilter (Filament's real
                    // #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    // 'status' => 'all': this count is every product in the
                    // season, whatever its status, so the drill-down opens the one
                    // view whose rows can match the number (the products list
                    // itself defaults to Active).
                    ->url(fn (SeasonModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', [
                            'status' => 'all',
                            'filters' => ['season_id' => ['value' => $record->id]],
                        ])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (SeasonModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (SeasonModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('seasons.fields.name')),
            TextEntry::make('slug')
                ->label(__('seasons.fields.slug')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeasons::route('/'),
            'create' => CreateSeason::route('/create'),
            'view' => ViewSeason::route('/{record}'),
            'edit' => EditSeason::route('/{record}/edit'),
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
        ];
    }

    /** See BrandResource::deleteAction()'s identical reasoning — season_id is also nullOnDelete(). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (SeasonModel $record): bool => static::canDelete($record))
            ->action(function (SeasonModel $record): void {
                $repository = app(SeasonRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('seasons.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('seasons.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('seasons.deleted'))
                    ->success()
                    ->send();
            });
    }
}
