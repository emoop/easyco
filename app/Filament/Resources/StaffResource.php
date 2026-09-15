<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\StaffResource\Pages\CreateStaff;
use App\Filament\Resources\StaffResource\Pages\EditStaff;
use App\Filament\Resources\StaffResource\Pages\ListStaff;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Staff management — admin-panel-design.md §10 Part 2. Gated entirely by
 * Permission::STAFF_MANAGE, same reasoning as RoleResource.
 *
 * No delete action anywhere on this Resource — Staff has no delete(),
 * only deactivate() (staff-access-domain-design.md §12.2). deletePermission()
 * is deliberately left unoverridden.
 *
 * is_active is NEVER a ToggleColumn in the table — a ToggleColumn
 * performs its own direct Eloquent write on toggle, completely
 * bypassing handleRecordUpdate() (and therefore both the domain layer
 * AND the last-active-staff guard below) — exactly the raw-Eloquent-
 * bypass risk admin-panel-design.md §5 warns about generally. The
 * table below uses a plain, read-only IconColumn instead; the only way
 * to actually change it is the Edit page's Toggle field, which does go
 * through handleRecordUpdate().
 */
class StaffResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = StaffModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    // Same fix as RoleResource — without these, Filament derives the
    // label from StaffModel's own name, rendering as "Staff Models".
    // Overridden as methods, not the $modelLabel/$pluralModelLabel
    // static properties — see RoleResource's identical override for why.
    public static function getModelLabel(): string
    {
        return __('staff.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.plural_label');
    }

    /** See RoleResource::getNavigationGroup()'s docblock for the group/sort reasoning. */
    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::ADMIN;
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }

    protected static function viewAnyPermission(): ?Permission
    {
        return Permission::STAFF_MANAGE;
    }

    protected static function createPermission(): ?Permission
    {
        return Permission::STAFF_MANAGE;
    }

    protected static function editPermission(): ?Permission
    {
        return Permission::STAFF_MANAGE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('staff.fields.name'))
                ->required()
                // No Staff::changeName() mutator exists — a real,
                // deliberate gap this task surfaced, not an oversight.
                // Still visible on the Edit form for context, just not
                // editable, until that mutator is a considered domain
                // decision rather than invented here to make this form
                // "complete".
                ->disabledOn('edit'),
            TextInput::make('email')
                ->label(__('staff.fields.email'))
                ->required()
                ->email()
                // Same reasoning as name — no Staff::changeEmail()
                // mutator exists either.
                ->disabledOn('edit'),
            TextInput::make('password')
                ->label(__('staff.fields.password'))
                ->password()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('staff.fields.password_help_edit') : null),
            Select::make('role_id')
                ->label(__('staff.fields.role'))
                ->options(fn () => RoleModel::pluck('name', 'id'))
                ->required(),
            Toggle::make('is_active')
                ->label(__('staff.fields.is_active'))
                // Staff::create() always starts active — a toggle that
                // can only ever be "on" at creation time is confusing,
                // not useful. Shown only on the Edit form.
                ->hiddenOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.fields.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('staff.fields.email'))
                    ->searchable(),
                TextColumn::make('role.name')
                    ->label(__('staff.fields.role')),
                IconColumn::make('is_active')
                    ->label(__('staff.fields.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
