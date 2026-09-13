<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5. RoleResource::canEdit()
 * already blocks a system role from ever reaching this page, so no
 * try/catch around CannotModifySystemRoleException here — if it's ever
 * thrown from this method, that means the canEdit() guard was somehow
 * bypassed, which is a genuine bug that should propagate loudly, not be
 * swallowed into a clean validation message.
 */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = app(RoleRepository::class)->findById((string) $record->id);

        if ($role === null) {
            // A Resource edit page acting on a row that has vanished
            // from the domain layer is a data-integrity bug, not a
            // normal null case — mirrors
            // EloquentStaffRepository::toDomainStaff()'s own fail-loud
            // posture for an analogous situation.
            throw new RuntimeException("Role \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        $permissions = array_map(
            static fn (string $value): Permission => Permission::from($value),
            $data['permissions'] ?? []
        );

        $role->rename($data['name']);
        $role->updatePermissions($permissions);

        app(RoleRepository::class)->save($role);

        return RoleModel::find($role->id());
    }
}
