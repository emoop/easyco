<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\Resources\SeasonResource\Pages\RelatedProducts;
use App\Filament\Resources\SeasonResource\Pages\ViewSeason;
use BackedEnum;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label(__('seasons.fields.products_count'))
                    ->state(fn (SeasonModel $record): int => app(SeasonRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('seasons.products_count.count', $state, ['count' => $state]))
                    ->url(fn (SeasonModel $record, int $state): ?string => $state > 0 ? static::getUrl('products', ['record' => $record]) : null),
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
            'products' => RelatedProducts::route('/{record}/products'),
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
