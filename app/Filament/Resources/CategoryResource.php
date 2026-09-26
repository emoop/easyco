<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\CategoryResource\Pages\ViewCategory;
use App\Filament\Resources\ProductResource;
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
use Illuminate\Database\Eloquent\Builder;
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

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getModelLabel(): string
    {
        return __('categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('categories.plural_label');
    }

    /**
     * Navigation grouping — admin-panel-design.md's stated top-level
     * structure (this task). Sort values leave 10 open for a future
     * ProductResource to slot in above everything else; Tag (30) sits
     * right after Category as the other simple, flat taxonomy concept
     * merchants think of alongside it.
     *
     * Returns the App\Filament\NavigationGroup enum case, not a raw
     * translated string — see that enum's own docblock for why (group
     * RENDER ORDER across the sidebar depends on it).
     */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
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
                // so nothing to exclude there. hierarchicalOptions() also
                // excludes every descendant of $record, not just $record
                // itself — picking a descendant as the new parent would
                // create a cycle, which Category::changeParent() has no
                // protection against (see this class's own docblock).
                ->options(fn (?Model $record): array => static::hierarchicalOptions(
                    $record !== null ? (string) $record->getKey() : null
                ))
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
        // Walked ONCE per table render, not once per row — captured by
        // the formatStateUsing/modifyQueryUsing closures below via
        // `use ()`, not re-queried through a per-row static call (which
        // would otherwise re-run the whole tree walk N times over for N
        // rows).
        ['orderedIds' => $orderedIds, 'depthById' => $depthById] = static::walkCategoryTree();

        return $table
            // Default row order is the tree order (parent immediately
            // followed by its own children, depth-first) rather than
            // Eloquent's default id/created_at order. A user explicitly
            // sorting by clicking a column header (->sortable() on
            // name/created_at) still works and replaces this ordering,
            // same as any other Filament table default sort.
            ->modifyQueryUsing(function (Builder $query) use ($orderedIds): Builder {
                // FIELD() with no arguments is invalid SQL — only true
                // when the table is genuinely empty, in which case
                // there is nothing to order anyway.
                if ($orderedIds === []) {
                    return $query;
                }

                $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));

                return $query->orderByRaw("FIELD(id, {$placeholders})", $orderedIds);
            })
            ->columns([
                TextColumn::make('name')
                    ->formatStateUsing(fn (CategoryModel $record, string $state): string => str_repeat('— ', $depthById[$record->id] ?? 0).$state)
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
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list, pre-filtered
                    // via its existing 'categories' SelectFilter (Filament's real
                    // #[Url(as: 'filters')] binding on ListRecords::$tableFilters)
                    // — not a custom drill-down table anymore.
                    // 'status' => 'all': this count is every product in the
                    // category, whatever its status, so the drill-down opens the
                    // one view whose rows can match the number (the products list
                    // itself defaults to Active).
                    ->url(fn (CategoryModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', [
                            'status' => 'all',
                            'filters' => ['categories' => ['value' => $record->id]],
                        ])
                        : null),
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
            // 'products' retired — products_count now redirects straight into
            // ProductResource's own list (see that column's own comment).
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

    /**
     * Depth-first walk from every root category (parent_id === null)
     * down through its own children, by name within each level — this
     * IS the tree view: this task's own real requirement, both on this
     * Resource's own list/edit and reused by ProductResource's
     * categories field. One query, walked in memory (a merchant's
     * category count is realistically small; no pagination concern here
     * the way ProductResource's own product list has).
     *
     * @return array{orderedIds: array<int, string>, depthById: array<string, int>}
     */
    private static function walkCategoryTree(): array
    {
        $all = CategoryModel::query()->orderBy('name')->get(['id', 'parent_id']);

        $childrenByParentId = [];
        foreach ($all as $category) {
            $childrenByParentId[$category->parent_id ?? '']["{$category->id}"] = true;
        }

        $orderedIds = [];
        $depthById = [];
        $visited = [];

        $visit = function (string $parentKey, int $depth) use (&$visit, &$orderedIds, &$depthById, &$visited, $childrenByParentId): void {
            foreach (array_keys($childrenByParentId[$parentKey] ?? []) as $id) {
                // Defensive only — Category::changeParent() itself has no
                // cycle protection at all (this class's own docblock, per
                // catalog-domain-design.md §6). Without this, a
                // manually-created cycle in the data would turn this walk
                // into an infinite loop and crash the whole admin panel;
                // this simply stops descending into an already-visited
                // node rather than attempting to validate/fix the cycle.
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;

                $orderedIds[] = $id;
                $depthById[$id] = $depth;

                $visit($id, $depth + 1);
            }
        };

        $visit('', 0);

        return ['orderedIds' => $orderedIds, 'depthById' => $depthById];
    }

    /**
     * [id => indented name] in tree order, for any Select that should
     * present categories hierarchically — this Resource's own parent_id
     * field and ProductResource's categories field both use this same
     * method, rather than each building its own flat pluck().
     *
     * $excludeId also excludes every DESCENDANT of $excludeId, not just
     * $excludeId itself — used only by this Resource's own parent_id
     * field (never by ProductResource's, which has no self-reference
     * concern): picking a descendant as the new parent would create a
     * cycle, which Category::changeParent() has no protection against
     * (see this class's own docblock).
     *
     * @return array<string, string>
     */
    public static function hierarchicalOptions(?string $excludeId = null): array
    {
        $all = CategoryModel::query()->orderBy('name')->get(['id', 'parent_id', 'name']);

        $childrenByParentId = [];
        $nameById = [];
        foreach ($all as $category) {
            $childrenByParentId[$category->parent_id ?? '']["{$category->id}"] = true;
            $nameById["{$category->id}"] = $category->name;
        }

        $excludedIds = [];
        if ($excludeId !== null) {
            $collectDescendants = function (string $id) use (&$collectDescendants, &$excludedIds, $childrenByParentId): void {
                $excludedIds[$id] = true;
                foreach (array_keys($childrenByParentId[$id] ?? []) as $childId) {
                    $collectDescendants($childId);
                }
            };
            $collectDescendants($excludeId);
        }

        $options = [];
        $visited = [];

        $visit = function (string $parentKey, int $depth) use (&$visit, &$options, &$visited, $childrenByParentId, $nameById, $excludedIds): void {
            foreach (array_keys($childrenByParentId[$parentKey] ?? []) as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;

                if (! isset($excludedIds[$id])) {
                    $options[$id] = str_repeat('— ', $depth).$nameById[$id];
                }

                $visit($id, $depth + 1);
            }
        };

        $visit('', 0);

        return $options;
    }
}
