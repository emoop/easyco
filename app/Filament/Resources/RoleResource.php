<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Role management — admin-panel-design.md §10 Part 2. Gated entirely by
 * Permission::STAFF_MANAGE (the enum's own docblock — "create, edit,
 * deactivate staff and assign their roles" — covers Role management
 * too; no separate permission exists for it and none is invented here).
 *
 * No delete action anywhere on this Resource — Role has no delete()
 * domain method (staff-access-domain-design.md §12.1's own "no
 * delete() — not asked for" decision), so deletePermission() is
 * deliberately left unoverridden, keeping AuthorizesViaStaffPermission's
 * fail-closed default (canDelete() === false) in force.
 */
class RoleResource extends Resource
{
    use AuthorizesViaStaffPermission;

    protected static ?string $model = RoleModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    // Without these, Filament derives the label from the model class's
    // own name (RoleModel) rather than the domain concept, rendering as
    // "Role Models" in navigation/breadcrumbs — a real, confirmed
    // cosmetic bug found via this task's manual smoke test.
    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

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

    /**
     * System roles are never editable through the admin UI, regardless
     * of permission — mirrors Role::rename()/updatePermissions()'s own
     * CannotModifySystemRoleException guard (staff-access-domain-
     * design.md §12.1). Checked here so the Edit action is never even
     * reachable for a system role in the table/page, rather than
     * reachable-but-guaranteed-to-throw at submission.
     */
    public static function canEdit(Model $record): bool
    {
        if ($record->is_system) {
            return false;
        }

        return parent::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required(),
            CheckboxList::make('permissions')
                ->options(self::permissionOptions())
                // Not required — an empty selection is valid
                // (staff-access-domain-design.md §11: "an empty role"
                // is an explicitly tested, valid case).
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_system')
                    ->label('Type')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-pencil')
                    ->trueColor('warning')
                    ->falseColor('success'),
                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(fn (RoleModel $record): string => count($record->permissions).' permissions'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            // Filament's own default row-click URL (built in
            // ListRecords::makeTable(), confirmed directly against the
            // installed v5.8.1 source) resolves the EditAction's own
            // getUrl() BEFORE ever consulting canEdit() directly — it
            // only skips that action if it is isHidden(). Without the
            // ->visible() below, the action is never hidden, so a
            // system role's row (and its "Type" icon column
            // specifically, which has no columnUrl of its own and so
            // falls back to this same recordUrl) stayed clickable
            // straight into a 403, even though canEdit() itself already
            // correctly returned false. Both fixes below are required:
            // ->visible() fixes the button AND restores Filament's own
            // fallback logic's second check (which does call canEdit()
            // directly); the explicit ->recordUrl() makes the row-level
            // behavior unambiguous rather than relying on that fallback
            // chain.
            ->recordActions([
                EditAction::make()
                    ->visible(fn (RoleModel $record): bool => static::canEdit($record)),
            ])
            ->recordUrl(fn (RoleModel $record): ?string => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Derives every option's label programmatically from the
     * Permission case's own name (PRODUCT_VIEW -> "Product view") so a
     * future 18th Permission needs zero UI changes here.
     *
     * @return array<string, string>
     */
    private static function permissionOptions(): array
    {
        $options = [];

        foreach (Permission::cases() as $permission) {
            $label = ucfirst(strtolower(str_replace('_', ' ', $permission->name)));
            $options[$permission->value] = $label;
        }

        return $options;
    }
}
