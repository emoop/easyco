<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\ShippingClassResource\Pages\CreateShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\EditShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\ListShippingClasses;
use App\Filament\Support\HelpLink;
use App\Services\Exceptions\ShippingClassDefaultException;
use App\Services\Exceptions\ShippingClassInUseException;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use App\Services\ShippingClassUsageReader;
use App\Services\ShippingClassWriter;
use BackedEnum;
use EasyCo\Shipping\Persistence\Eloquent\ShippingClassModel;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * Shipping classes (shipping-domain-design.md §12.3.1, stage 5b): the list with where each class is used, and the
 * create / edit form. EVERY write is one call to ShippingClassWriter: a Filament form or action never writes a
 * shipping row itself, and every validation message on the form is the service's, translated. The permission is
 * `shipping_manage` for everything; the Eloquent model is the package's read model for the table only.
 *
 * THE CODE IS SET ONCE: the field is editable on create and read-only (with the reason) on edit.
 *
 * THE LIST READS IN A BOUNDED NUMBER OF QUERIES whatever the number of classes: the page of classes once, and the
 * usage of ALL listed classes in two grouped reads (rates, variations) through ShippingClassUsageReader — computed
 * once per request, never per row.
 */
class ShippingClassResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = ShippingClassModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    /** Per request: the listed records => class code => ['methods' => n, 'variations' => n]. */
    private static ?WeakMap $listUsage = null;

    public static function getModelLabel(): string
    {
        return __('shipping.classes.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shipping.classes.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('shipping.classes.navigation_label');
    }

    /** After the Shipping page (10), the zones (20) and the methods (30). */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::SHIPPING;
    }

    public static function getNavigationSort(): ?int
    {
        return 40;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function viewPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    protected static function deletePermission(): ?Permission
    {
        return Permission::SHIPPING_MANAGE;
    }

    /** By code, the same order as ShippingClassRepository::all(). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('code');
    }

    // ---- the form ------------------------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->label(__('shipping.classes.fields.name'))
                ->required()
                ->maxLength(ShippingClass::NAME_MAX_LENGTH),
            TextInput::make('code')
                ->label(__('shipping.classes.fields.code'))
                ->helperText(__('shipping.classes.help.code'))
                ->required()
                ->maxLength(64)
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('description')
                ->label(__('shipping.classes.fields.description'))
                ->maxLength(ShippingClassWriter::DESCRIPTION_MAX_LENGTH),
            Toggle::make('is_default')
                ->label(__('shipping.classes.fields.is_default')),
            Text::make(__('shipping.classes.help.is_default'))->color('gray')->size('sm'),
            Text::make(__('shipping.classes.help.modes'))->color('gray')->size('sm'),
            HelpLink::group(['class_mode', 'class_editor'], 'shipping'),
        ]);
    }

    // ---- the list ------------------------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading(__('shipping.classes.empty'))
            ->emptyStateDescription(__('shipping.classes.empty_description'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('shipping.classes.fields.code'))
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label(__('shipping.classes.fields.name'))
                    ->wrap(),
                // a plain marker, no colour
                TextColumn::make('is_default')
                    ->label(__('shipping.classes.fields.is_default'))
                    ->formatStateUsing(fn ($state): string => $state ? __('shipping.classes.default_marker') : ''),
                TextColumn::make('description')
                    ->label(__('shipping.classes.fields.description'))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('used_by')
                    ->label(__('shipping.classes.fields.used_by'))
                    ->state(function (ShippingClassModel $record, $livewire): string {
                        $usage = static::usage($livewire)[(string) $record->code] ?? ['methods' => 0, 'variations' => 0];

                        if ($usage['methods'] === 0 && $usage['variations'] === 0) {
                            return __('shipping.classes.used_by_none');
                        }

                        return __('shipping.classes.used_by', [
                            'methods' => trans_choice('shipping.classes.methods_count', $usage['methods'], ['count' => $usage['methods']]),
                            'variations' => trans_choice('shipping.classes.variations_count', $usage['variations'], ['count' => $usage['variations']]),
                        ]);
                    })
                    ->wrap(),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::editAction(),
                    static::deleteAction(),
                ])
                    ->label(__('shipping.classes.actions.menu'))
                    ->icon('heroicon-m-chevron-down')
                    ->iconPosition(IconPosition::After)
                    ->color('gray')
                    ->button(),
            ]);
    }

    /**
     * The usage of the classes the table lists — read ONCE per request (two grouped reads for all of them).
     *
     * @return array<string, array{methods: int, variations: int}>
     */
    private static function usage($livewire): array
    {
        $records = $livewire->getTableRecords();

        static::$listUsage ??= new WeakMap();

        return static::$listUsage[$records] ??= app(ShippingClassUsageReader::class)->usage(
            $records->pluck('code')->map(static fn ($code): string => (string) $code)->values()->all(),
        );
    }

    private static function editAction(): Action
    {
        return Action::make('edit_class')
            ->label(__('shipping.classes.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->url(fn (ShippingClassModel $record): string => static::getUrl('edit', ['record' => $record]))
            ->visible(fn (ShippingClassModel $record): bool => static::canEdit($record));
    }

    private static function deleteAction(): Action
    {
        return Action::make('delete_class')
            ->label(__('shipping.classes.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (ShippingClassModel $record): string => __('shipping.classes.delete.heading', ['name' => $record->name]))
            ->modalDescription(__('shipping.classes.delete.description'))
            ->modalSubmitActionLabel(__('shipping.classes.delete.submit'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->visible(fn (ShippingClassModel $record): bool => static::canDelete($record))
            ->action(function (ShippingClassModel $record): void {
                try {
                    app(ShippingClassWriter::class)->delete((string) $record->id);
                } catch (ShippingClassInUseException|ShippingClassDefaultException|ShippingClassNotFoundException $exception) {
                    Notification::make()->title(__('shipping.classes.notice.refused'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('shipping.classes.notice.deleted'))->success()->send();
            });
    }

    // ---- shared by the pages -------------------------------------------------------------------------------

    /**
     * A service refusal as the form's own field errors (the form's state lives under `data`).
     *
     * @throws ValidationException
     */
    public static function fieldErrors(ShippingClassInvalidException $exception): never
    {
        $messages = [];

        foreach ($exception->errors as $field => $list) {
            $messages['data.'.$field] = $list;
        }

        throw ValidationException::withMessages($messages);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShippingClasses::route('/'),
            'create' => CreateShippingClass::route('/create'),
            'edit' => EditShippingClass::route('/{record}/edit'),
        ];
    }
}
