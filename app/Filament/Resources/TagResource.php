<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\TagResource\Pages\CreateTag;
use App\Filament\Resources\TagResource\Pages\EditTag;
use App\Filament\Resources\TagResource\Pages\ListTags;
use App\Filament\Resources\TagResource\Pages\ViewTag;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
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
 * Tag management — admin-panel-design.md §10/Part B, catalog-domain-
 * design.md §3.12/§3.13. Identical shape to BrandResource minus the
 * logo field. Gated identically: PRODUCT_VIEW to browse/view,
 * TAXONOMY_MANAGE to create/edit/delete.
 */
class TagResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = TagModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-hashtag';

    public static function getModelLabel(): string
    {
        return __('tags.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tags.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::PRODUCT_VIEW;
    }

    /** See BrandResource::canViewAny()'s identical docblock — same "Tags field enabled" toggle, same closed nav+direct-URL gap. */
    public static function canViewAny(): bool
    {
        return static::staffCanForAction(static::viewAnyPermission())
            && ProductResource::tagsFieldEnabled();
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
                ->label(__('tags.fields.name'))
                ->required(),
            TextInput::make('slug')
                ->label(__('tags.fields.slug'))
                ->required()
                ->unique(table: 'catalog_tags', column: 'slug', ignoreRecord: true),
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
                    ->label(__('tags.fields.products_count'))
                    ->state(fn (TagModel $record): int => app(TagRepository::class)->countProductsUsing((string) $record->id))
                    ->formatStateUsing(fn (int $state): string => trans_choice('tags.products_count.count', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its 'tags' SelectFilter (Filament's real
                    // #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    // 'status' => 'all': this count is every product carrying the
                    // tag, whatever its status, so the drill-down opens the one
                    // view whose rows can match the number (the products list
                    // itself defaults to Active).
                    ->url(fn (TagModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', [
                            'status' => 'all',
                            'filters' => ['tags' => ['value' => $record->id]],
                        ])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (TagModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (TagModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label(__('tags.fields.name')),
            TextEntry::make('slug')
                ->label(__('tags.fields.slug')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'view' => ViewTag::route('/{record}'),
            'edit' => EditTag::route('/{record}/edit'),
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
        ];
    }

    /** See CategoryResource::deleteAction()'s identical reasoning — catalog_product_tags is also cascadeOnDelete(). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (TagModel $record): bool => static::canDelete($record))
            ->action(function (TagModel $record): void {
                $repository = app(TagRepository::class);
                $inUseCount = $repository->countProductsUsing((string) $record->id);

                if ($inUseCount > 0) {
                    Notification::make()
                        ->title(__('tags.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('tags.delete_blocked', ['name' => $record->name, 'count' => $inUseCount]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('tags.deleted'))
                    ->success()
                    ->send();
            });
    }
}
