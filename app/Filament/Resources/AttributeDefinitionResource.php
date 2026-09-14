<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ListAttributeDefinitions;
use App\Filament\Resources\AttributeDefinitionResource\Pages\ViewAttributeDefinition;
use BackedEnum;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
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
 * AttributeDefinition management — admin-panel-design.md §10, catalog-
 * domain-design.md §3.12. Gated identically to Brand/Category/Tag:
 * PRODUCT_VIEW to browse/view, TAXONOMY_MANAGE to create/edit. No
 * delete action — AttributeDefinition has no delete() domain method.
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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (AttributeDefinitionModel $record): bool => static::canEdit($record)),
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
        ];
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
