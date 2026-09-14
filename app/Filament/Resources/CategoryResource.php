<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\CategoryResource\Pages\RelatedProducts;
use App\Filament\Resources\CategoryResource\Pages\ViewCategory;
use App\Filament\Concerns\AuthorizesViaStaffPermission;
use BackedEnum;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Category management — admin-panel-design.md §10/Part B, catalog-
 * domain-design.md §3.12/§3.13. Same shape as BrandResource; no logo
 * field. Gated identically: PRODUCT_VIEW to browse/view,
 * TAXONOMY_MANAGE to create/edit/delete.
 */
class CategoryResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = CategoryModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getModelLabel(): string
    {
        return __('categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('categories.plural_label');
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
            Select::make('parent_id')
                ->label(__('categories.fields.parent_id'))
                // A category can't be its own parent — the one piece of
                // cycle-avoidance worth doing at the UI layer even though
                // Category::changeParent() itself still has none
                // (catalog-domain-design.md §6, Category's own class
                // docblock). Excludes the record's own id from the
                // options list on Edit; there is no record yet on Create,
                // so nothing to exclude there.
                ->options(function (?Model $record): array {
                    return CategoryModel::query()
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable(),
            TextInput::make('name')
                ->label(__('categories.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('categories.fields.slug'))
                ->required()
                ->unique(table: 'catalog_categories', column: 'slug', ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label(__('categories.fields.parent_id')),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('products_count')
                    ->label(__('categories.fields.products_count'))
                    ->state(fn (CategoryModel $record): int => app(CategoryRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('categories.products_count.count', $state, ['count' => $state]))
                    ->url(fn (CategoryModel $record, int $state): ?string => $state > 0 ? static::getUrl('products', ['record' => $record]) : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CategoryModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (CategoryModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('categories.fields.name')),
            TextEntry::make('parent.name')
                ->label(__('categories.fields.parent_id')),
            TextEntry::make('slug')
                ->label(__('categories.fields.slug')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
            'products' => RelatedProducts::route('/{record}/products'),
        ];
    }

    /**
     * See BrandResource::deleteAction()'s identical reasoning —
     * catalog_product_categories' own FK is cascadeOnDelete() (deletes
     * the pivot row, not the Category), so nothing at the DB level
     * blocks a Category delete either; this app-layer check is the
     * real protection.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (CategoryModel $record): bool => static::canDelete($record))
            ->action(function (CategoryModel $record): void {
                $repository = app(CategoryRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('categories.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('categories.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('categories.deleted'))
                    ->success()
                    ->send();
            });
    }
}
