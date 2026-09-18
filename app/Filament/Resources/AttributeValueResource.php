<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\ListAttributeValues;
use App\Filament\Resources\AttributeValueResource\Pages\RelatedProductsAxis;
use App\Filament\Resources\AttributeValueResource\Pages\ViewAttributeValue;
use App\Filament\Resources\ProductResource;
use BackedEnum;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
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
 * AttributeValue management — admin-panel-design.md §10/Part B,
 * catalog-domain-design.md §3.12/§3.13. Gated identically to the other
 * five: PRODUCT_VIEW to browse/view, TAXONOMY_MANAGE to
 * create/edit/delete.
 *
 * `attribute_definition_id` is NOT editable on the Edit form — no
 * changeAttributeDefinition() mutator exists on the domain class, and
 * none is invented here.
 */
class AttributeValueResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = AttributeValueModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-list-bullet';

    public static function getModelLabel(): string
    {
        return __('attribute_values.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('attribute_values.plural_label');
    }

    /**
     * navigation_label (below) overrides what shows in the sidebar
     * itself; this method still controls which group/position it
     * appears within. See CategoryResource::getNavigationGroup()'s
     * docblock for the group/sort reasoning.
     */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::CATALOG;
    }

    public static function getNavigationSort(): ?int
    {
        return 50;
    }

    /**
     * Navigation-only label — reads a dedicated
     * attribute_values.navigation_label key, separate from
     * getPluralModelLabel(). The resource's own label stays "Стойност
     * на атрибут"/"Стойности на атрибути" for page titles,
     * breadcrumbs, and delete confirmations — this override is
     * deliberately scoped to the sidebar only, not a rename of the
     * resource itself.
     */
    public static function getNavigationLabel(): string
    {
        return __('attribute_values.navigation_label');
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
            Select::make('attribute_definition_id')
                ->label(__('attribute_values.fields.attribute_definition_id'))
                // Filtered to SELECT/MULTISELECT only — a UI convenience,
                // not a domain rule (AttributeValue itself places no such
                // restriction; only AttributeDefinition::
                // assertUsableAsVariationAxis() cares about SELECT
                // specifically, and only for the axis use case). No
                // changeAttributeDefinition() mutator exists, so this is
                // disabled on edit — see this class's own docblock.
                ->options(
                    fn (): array => AttributeDefinitionModel::whereIn('type', [
                        AttributeType::SELECT->value,
                        AttributeType::MULTISELECT->value,
                    ])->pluck('name', 'id')->all()
                )
                ->required()
                ->disabledOn('edit'),
            TextInput::make('value')
                ->label(__('attribute_values.fields.value'))
                ->required(),
            TextInput::make('sort_order')
                ->label(__('attribute_values.fields.sort_order'))
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('value')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attributeDefinition.name')
                    ->label(__('attribute_values.fields.attribute_definition_id')),
                TextColumn::make('sort_order')
                    ->label(__('attribute_values.fields.sort_order'))
                    ->sortable(),
                TextColumn::make('descriptive_count')
                    ->label(__('attribute_values.fields.descriptive_count'))
                    ->state(fn (AttributeValueModel $record): int => app(AttributeValueRepository::class)->countProductsUsing((string) $record->id)['descriptive'])
                    ->formatStateUsing(fn (int $state): string => trans_choice('attribute_values.products_count.descriptive', $state, ['count' => $state]))
                    // Same redirect as AttributeDefinitionResource's own
                    // descriptive_count column — also sets
                    // attribute_value_id, which narrows the same Filter
                    // down to exactly this value's own products (see
                    // ProductResource::table()'s 'attribute_usage' Filter
                    // docblock for the exact query branch this takes).
                    ->url(fn (AttributeValueModel $record, int $state): ?string => $state > 0
                        ? ProductResource::getUrl('index', ['filters' => ['attribute_usage' => [
                            'attribute_definition_id' => $record->attribute_definition_id,
                            'attribute_value_id' => $record->id,
                        ]]])
                        : null),
                TextColumn::make('axis_count')
                    ->label(__('attribute_values.fields.axis_count'))
                    ->state(fn (AttributeValueModel $record): int => app(AttributeValueRepository::class)->countProductsUsing((string) $record->id)['axis'])
                    ->formatStateUsing(fn (int $state): string => trans_choice('attribute_values.products_count.axis', $state, ['count' => $state]))
                    ->url(fn (AttributeValueModel $record, int $state): ?string => $state > 0 ? static::getUrl('products-axis', ['record' => $record]) : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (AttributeValueModel $record): bool => static::canEdit($record)),
                static::deleteAction(),
            ])
            ->recordUrl(fn (AttributeValueModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('value')
                ->label(__('attribute_values.fields.value')),
            TextEntry::make('attributeDefinition.name')
                ->label(__('attribute_values.fields.attribute_definition_id')),
            TextEntry::make('sort_order')
                ->label(__('attribute_values.fields.sort_order')),
            TextEntry::make('created_at')
                ->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttributeValues::route('/'),
            'create' => CreateAttributeValue::route('/create'),
            'view' => ViewAttributeValue::route('/{record}'),
            'edit' => EditAttributeValue::route('/{record}/edit'),
            // 'products-descriptive' retired — see
            // AttributeDefinitionResource::getPages()'s identical note.
            // 'products-axis' stays untouched.
            'products-axis' => RelatedProductsAxis::route('/{record}/products-axis'),
        ];
    }

    /** See AttributeDefinitionResource::deleteAction()'s identical reasoning. */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (AttributeValueModel $record): bool => static::canDelete($record))
            ->action(function (AttributeValueModel $record): void {
                $repository = app(AttributeValueRepository::class);
                $counts = $repository->countProductsUsing((string) $record->id);

                if ($counts['descriptive'] > 0 || $counts['axis'] > 0) {
                    $key = match (true) {
                        $counts['descriptive'] > 0 && $counts['axis'] > 0 => 'attribute_values.delete_blocked.both',
                        $counts['descriptive'] > 0 => 'attribute_values.delete_blocked.descriptive_only',
                        default => 'attribute_values.delete_blocked.axis_only',
                    };

                    Notification::make()
                        ->title(__($key, ['name' => $record->value, 'descriptive' => $counts['descriptive'], 'axis' => $counts['axis']]))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $repository->delete((string) $record->id);
                } catch (QueryException) {
                    Notification::make()
                        ->title(__('attribute_values.delete_blocked.both', ['name' => $record->value, 'descriptive' => $counts['descriptive'], 'axis' => $counts['axis']]))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('attribute_values.deleted'))
                    ->success()
                    ->send();
            });
    }
}
