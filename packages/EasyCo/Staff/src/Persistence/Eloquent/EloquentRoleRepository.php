<?php

namespace EasyCo\Staff\Persistence\Eloquent;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;

/**
 * Maps the Role entity onto `staff_roles`. No unique-constraint
 * collision handling here, deliberately — the migration does not put a
 * uniqueness constraint on `name` (design doc does not specify one as
 * an invariant), so there is nothing for a QueryException-based guard
 * to detect.
 */
final class EloquentRoleRepository implements RoleRepository
{
    public function save(Role $role): void
    {
        $model = $role->id() !== null
            ? RoleModel::findOrFail($role->id())
            : new RoleModel();

        $model->name = $role->name();
        $model->permissions = array_map(
            static fn (Permission $permission) => $permission->value,
            $role->permissions()
        );
        $model->is_system = $role->isSystem();

        $model->save();

        if ($role->id() === null) {
            $role->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Role
    {
        $model = RoleModel::find($id);

        return $model !== null ? $this->toDomainRole($model) : null;
    }

    public function findSystemRoleByName(string $name): ?Role
    {
        $model = RoleModel::where('name', $name)->where('is_system', true)->first();

        return $model !== null ? $this->toDomainRole($model) : null;
    }

    private function toDomainRole(RoleModel $model): Role
    {
        $permissions = array_map(
            static fn (string $value) => Permission::from($value),
            $model->permissions
        );

        return Role::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            permissions: $permissions,
            isSystem: $model->is_system,
        );
    }
}
