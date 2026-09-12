<?php

namespace EasyCo\Staff\Contracts;

use EasyCo\Staff\Role;

interface RoleRepository
{
    public function save(Role $role): void;

    public function findById(string $id): ?Role;

    /**
     * Only ever matches an isSystem:true role by this exact name — a
     * merchant-created custom role happening to share a name with a
     * system role is NOT returned here. Mirrors
     * PriceListRepository::findSystemListByName() exactly.
     */
    public function findSystemRoleByName(string $name): ?Role;
}
