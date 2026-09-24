<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ProductGroupResource\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroupResource\Pages\ViewProductGroup;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
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
 * ProductGroup management — admin-panel-design.md §10/Part B,
 * catalog-domain-design.md §3.15. Structurally identical to
 * SeasonResource minus its Part B machinery (product-count column,
 * drill-down, delete action) — ProductGroupRepository has no
 * countProductsUsing() or delete() at all (confirmed against the real
 * contract: only save()/findById()/all()), so none of that is
 * fabricated here.
 *
 * No delete action anywhere on this Resource — ProductGroup has no
 * delete() domain method or repository method.
 */
class ProductGroupResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = ProductGroupModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getModelLabel(): string
    {
        return __('product_groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('product_groups.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning; sort 75 places this between Season (70) and CatalogSettings (80). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 75;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    /** See BrandResource::canViewAny()'s identical docblock — same "Product group field enabled" toggle, same closed nav+direct-URL gap. */
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission())
            && ProductResource::productGroupFieldEnabled();
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
            TextInput::make('code')
                ->label(__('product_groups.fields.code'))
                ->required()
                ->unique(table: 'catalog_product_groups', column: 'code', ignoreRecord: true)
                // No changeCode() mutator exists on ProductGroup — code
                // is the stable machine identifier other things key
                // against (catalog-domain-design.md §3.15, same posture
                // AttributeDefinition::code already established). Flagged
                // here, not invented.
                ->disabledOn('edit'),
            TextInput::make('name')
                ->label(__('product_groups.fields.name'))
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label(__('product_groups.fields.products_count'))
                    ->state(fn (ProductGroupModel $record): int => app(ProductGroupRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('product_groups.products_count.count', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its existing 'product_group_id' SelectFilter (Filament's
                    // real #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    ->url(fn (ProductGroupModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', ['filters' => ['product_group_id' => ['value' => $record->id]]])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ProductGroupModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (ProductGroupModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')
                ->label(__('product_groups.fields.code')),
            TextEntry::make('name')
                ->label(__('product_groups.fields.name')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductGroups::route('/'),
            'create' => CreateProductGroup::route('/create'),
            'view' => ViewProductGroup::route('/{record}'),
            'edit' => EditProductGroup::route('/{record}/edit'),
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
        ];
    }

    /** See SeasonResource::deleteAction()'s identical reasoning — product_group_id is also nullOnDelete(). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (ProductGroupModel $record): bool => static::canDelete($record))
            ->action(function (ProductGroupModel $record): void {
                $repository = app(ProductGroupRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('product_groups.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('product_groups.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('product_groups.deleted'))
                    ->success()
                    ->send();
            });
    }
}
