<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ProductGroupResource\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroupResource\Pages\ViewProductGroup;
use BackedEnum;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (ProductGroupModel $record): bool => static::canEdit($record)),
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
        ];
    }
}
