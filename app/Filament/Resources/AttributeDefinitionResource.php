<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeDefinitionResource\Pages\RelatedProductsAxis;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ViewAttributeDefinition;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
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
use Illuminate\Database\QueryException;

/**
 * AttributeDefinition management — admin-panel-design.md §10/Part B,
 * catalog-domain-design.md §3.12/§3.13. Gated identically to Brand/
 * Category/Tag: PRODUCT_VIEW to browse/view, TAXONOMY_MANAGE to
 * create/edit/delete.
 *
 * `code` and `type` are NOT editable on the Edit form — no
 * changeCode()/no mutator for `type` exists on the domain class at
 * all. AttributeDefinition's own class docblock explains why: `code`
 * is the stable machine identifier other things key against, and
 * `type` drives assertUsableAsVariationAxis()'s hard rule — changing
 * either after real use (a variation axis, existing AttributeValues)
 * is a genuine invariant risk, out of this pass's scope. Flagged here,
 * not silently worked around.
 */
class AttributeDefinitionResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = AttributeDefinitionModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function getModelLabel(): string
    {
        return __('attribute_definitions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('attribute_definitions.plural_label');
    }

    /** See CategoryResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 40;
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
            TextInput::make('code')
                ->label(__('attribute_definitions.fields.code'))
                ->required()
                ->unique(table: 'catalog_attribute_definitions', column: 'code', ignoreRecord: true)
                // No changeCode() mutator exists — see this class's own
                // docblock.
                ->disabledOn('edit'),
            TextInput::make('name')
                ->label(__('attribute_definitions.fields.name'))
                ->required(),
            Select::make('type')
                ->label(__('attribute_definitions.fields.type'))
                ->options(self::typeOptions())
                ->required()
                // No mutator for `type` exists either — see this class's
                // own docblock.
                ->disabledOn('edit'),
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
                TextColumn::make('type')
                    ->label(__('attribute_definitions.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("attribute_definitions.types.{$state}"))
                    ->color(fn (string $state): string => self::typeColor(AttributeType::from($state))),
                TextColumn::make('descriptive_count')
                    ->label(__('attribute_definitions.fields.descriptive_count'))
                    ->state(fn (AttributeDefinitionModel $record): int => app(AttributeDefinitionRepository::class)->countProductsUsing((string) $record->id)['descriptive'])
                    ->formatStateUsing(fn (int $state): string => trans_choice('attribute_definitions.products_count.descriptive', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.filtered_list') : null)
                    // Redirects into ProductResource's own real list,
                    // pre-filtered via the new 'attribute_usage' Filter
                    // (ProductResource::table()'s own docblock) — not a
                    // custom drill-down table anymore. Real row actions
                    // (View/Edit/Duplicate), not a stripped-down set.
                    ->url(fn (AttributeDefinitionModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', ['filters' => ['attribute_usage' => ['attribute_definition_id' => $record->id]]])
                        : null),
                TextColumn::make('axis_count')
                    ->label(__('attribute_definitions.fields.axis_count'))
                    ->state(fn (AttributeDefinitionModel $record): int => app(AttributeDefinitionRepository::class)->countProductsUsing((string) $record->id)['axis'])
                    ->formatStateUsing(fn (int $state): string => trans_choice('attribute_definitions.products_count.axis', $state, ['count' => $state]))
                    ->color(fn (int $state): ?string => $state > 0 ? 'info' : null)
                    ->tooltip(fn (int $state): ?string => $state > 0 ? __('related_products.count_tooltip.axis_list') : null)
                    ->url(fn (AttributeDefinitionModel $record, int $state): ?string => $state > 0 ? static::getUrl('products-axis', ['record' => $record]) : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (AttributeDefinitionModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (AttributeDefinitionModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')
                ->label(__('attribute_definitions.fields.code')),
            TextEntry::make('name')
                ->label(__('attribute_definitions.fields.name')),
            TextEntry::make('type')
                ->label(__('attribute_definitions.fields.type'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __("attribute_definitions.types.{$state}"))
                ->color(fn (string $state): string => self::typeColor(AttributeType::from($state))),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeDefinitions::route('/'),
            'create' => CreateAttributeDefinition::route('/create'),
            'view' => ViewAttributeDefinition::route('/{record}'),
            'edit' => EditAttributeDefinition::route('/{record}/edit'),
            // 'products-descriptive' retired — descriptive_count now
            // redirects straight into ProductResource's own list (see
            // that column's own comment). 'products-axis' stays: see
            // RelatedProductsAxis's own docblock for why it is NOT
            // migrated (axis usage is VARIABLE-only; ProductResource is
            // SIMPLE-only; no other admin view can show a VARIABLE
            // product yet).
            'products-axis' => RelatedProductsAxis::route('/{record}/products-axis'),
        ];
    }

    /**
     * Blocks delete whenever EITHER descriptive or axis usage is
     * nonzero, explicitly distinguishing which in the message — real
     * DB-level restrictOnDelete() protection already exists underneath
     * (confirmed in the prior task) for both catalog_product_attributes
     * and catalog_variation_attribute_values, so this app-layer check
     * exists to give a friendly message before ever hitting that DB
     * exception, not because the DB itself is unsafe without it.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (AttributeDefinitionModel $record): bool => static::canDelete($record))
            ->action(function (AttributeDefinitionModel $record): void {
                $repository = app(AttributeDefinitionRepository::class);
                $counts = $repository->countProductsUsing((string) $record->id);

                if ($counts['descriptive'] > 0 || $counts['axis'] > 0) {
                    $key = match (true) {
                        $counts['descriptive'] > 0 && $counts['axis'] > 0 => 'attribute_definitions.delete_blocked.both',
                        $counts['descriptive'] > 0 => 'attribute_definitions.delete_blocked.descriptive_only',
                        default => 'attribute_definitions.delete_blocked.axis_only',
                    };

                    Notification::make()
                        ->title(__($key, ['name' => $record->name, 'descriptive' => $counts['descriptive'], 'axis' => $counts['axis']]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('attribute_definitions.delete_blocked.both', ['name' => $record->name, 'descriptive' => $counts['descriptive'], 'axis' => $counts['axis']]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('attribute_definitions.deleted'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Derives every option's label from the translated type map, the
     * same "options() only, no separate maintained list" spirit
     * RoleResource's permissionOptions() follows for Permission — a
     * future 6th AttributeType needs only a new lang key, not a second
     * place to update here.
     *
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (AttributeType::cases() as $type) {
            $options[$type->value] = __("attribute_definitions.types.{$type->value}");
        }

        return $options;
    }

    /**
     * Visual-cue color per type — SELECT/MULTISELECT are the two types
     * usable in a variation axis context (directly, or via a future
     * MULTISELECT extension), so they get the more prominent colors;
     * the three purely-descriptive types share a neutral one.
     */
    private static function typeColor(AttributeType $type): string
    {
        return match ($type) {
            AttributeType::SELECT => 'success',
            AttributeType::MULTISELECT => 'info',
            AttributeType::TEXT, AttributeType::NUMBER, AttributeType::BOOLEAN => 'gray',
        };
    }
}
