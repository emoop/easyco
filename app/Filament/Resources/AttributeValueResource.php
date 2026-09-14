<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\ListAttributeValues;
use App\Filament\Resources\AttributeValueResource\Pages\ViewAttributeValue;
use BackedEnum;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * AttributeValue management — admin-panel-design.md §10, catalog-
 * domain-design.md §3.12. Gated identically to the other four:
 * PRODUCT_VIEW to browse/view, TAXONOMY_MANAGE to create/edit. No
 * delete action — AttributeValue has no delete() domain method.
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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (AttributeValueModel $record): bool => static::canEdit($record)),
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
        ];
    }
}
