<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\RoleModel;
use EasyCo\Staff\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Write-interception per admin-panel-design.md §5 — the form reads
 * directly through RoleModel, but every write goes through the real
 * domain Role::create() + RoleRepository, never a raw Eloquent
 * ::create(). Always produces a non-system Role, structurally —
 * Role::create() has no isSystem parameter at all (Role.php's own
 * "isSystem=true is only reachable via createSystemRole()," seeding-
 * layer-only), so nothing a user submits here can ever influence it.
 */
class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $permissions = array_map(
            static fn (string $value): Permission => Permission::from($value),
            $data['permissions'] ?? []
        );

        $role = Role::create($data['name'], $permissions);

        app(RoleRepository::class)->save($role);

        return RoleModel::find($role->id());
    }
}
