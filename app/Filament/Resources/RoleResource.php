<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
     * Viewing a role uses the same permission as viewing the list —
     * anyone who can reach the Roles table at all can view any
     * individual role's full detail. There is no separate "view detail"
     * permission in the vocabulary and none is invented here.
     */
    protected static function viewPermission(): ?Permission
    {
        return static::viewAnyPermission();
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
            // Every row (system or custom) now navigates to the
            // read-only View page — reachable by anyone who can see this
            // table at all (viewPermission() === viewAnyPermission()),
            // so the row-click destination is no longer conditional on
            // canEdit() the way it was before ViewRole existed. Edit
            // stays a separate, explicitly-gated button next to it,
            // visible only for non-system roles — see canEdit()'s own
            // docblock and this class's earlier "Filament's own default
            // row-click URL" finding for why ->visible() on EditAction
            // is still required regardless of ->recordUrl() below.
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (RoleModel $record): bool => static::canEdit($record)),
            ])
            ->recordUrl(fn (RoleModel $record): string => static::getUrl('view', ['record' => $record]));
    }

    /**
     * Read-only — every group from permissionGroups() below, each
     * listing all Permission cases in that group with a clear
     * granted/not-granted visual distinction. Deliberately shows the
     * FULL 17-permission picture, not just what this role grants:
     * per the domain owner's own question after using the panel ("how
     * does a new admin know which role fits which purpose, if all they
     * see is a permission count?"), an admin comparing two roles side
     * by side needs to see both what's granted and what's withheld.
     */
    public static function infolist(Schema $schema): Schema
    {
        $sections = [];

        foreach (self::permissionGroups() as $groupLabel => $permissions) {
            $entries = [];

            foreach ($permissions as $permission) {
                $label = ucfirst(strtolower(str_replace('_', ' ', $permission->name)));

                $entries[] = IconEntry::make($permission->value)
                    ->label($label)
                    ->state(fn (RoleModel $record): bool => in_array($permission->value, $record->permissions, true))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray');
            }

            $sections[] = Section::make($groupLabel)
                ->schema($entries)
                ->columns(2);
        }

        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('is_system')
                ->label('Role type')
                ->formatStateUsing(fn (bool $state): string => $state ? 'System role' : 'Custom role'),
            ...$sections,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Mirrors Permission.php's own comment groupings exactly (Catalog /
     * Cost and pricing / Orders / Point of sale / Marketing / Reporting
     * / System). This is a static, hand-maintained map rather than a
     * method on the enum itself — Permission.php's own docblock states
     * "No methods on this enum — pure vocabulary," and that boundary is
     * kept here: a future 18th Permission needs this mapping updated by
     * hand (unlike permissionOptions() above, which derives itself
     * automatically), a real accepted maintenance tradeoff, not an
     * oversight. test_permission_groups_account_for_every_real_permission_exactly_once
     * (RoleResourceTest) is the regression test that catches a forgotten
     * update.
     *
     * @return array<string, Permission[]>
     */
    private static function permissionGroups(): array
    {
        return [
            'Catalog' => [Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE, Permission::TAXONOMY_MANAGE],
            'Cost and pricing' => [Permission::COST_VIEW, Permission::COST_MANAGE, Permission::PRICE_MANAGE],
            'Orders' => [Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::REFUND_CASH, Permission::REFUND_BANK],
            'Point of sale' => [Permission::POS_OPERATE, Permission::POS_DISCOUNT],
            'Marketing' => [Permission::PROMOTION_MANAGE],
            'Reporting' => [Permission::REPORT_VIEW],
            'System' => [Permission::SETTINGS_MANAGE, Permission::STAFF_MANAGE, Permission::AI_MANAGE],
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
