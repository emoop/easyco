<?php

namespace EasyCo\Staff\Tests;

use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Exceptions\CannotModifySystemRoleException;
use EasyCo\Staff\Role;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function test_empty_name_throws_on_create(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Role::create('', [Permission::PRODUCT_VIEW]);
    }

    public function test_empty_name_throws_on_create_system_role(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Role::createSystemRole('   ', [Permission::PRODUCT_VIEW]);
    }

    public function test_a_non_permission_element_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Role::create('Broken', [Permission::PRODUCT_VIEW, 'not_a_permission']);
    }

    public function test_an_empty_permissions_array_is_valid_and_grants_nothing(): void
    {
        $role = Role::create('Empty', []);

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($role->grants($permission));
        }
    }

    public function test_create_always_produces_a_non_system_role(): void
    {
        $role = Role::create('Custom', [Permission::PRODUCT_VIEW]);

        $this->assertFalse($role->isSystem());
    }

    public function test_create_system_role_always_produces_a_system_role(): void
    {
        $role = Role::createSystemRole('Administrator', [Permission::PRODUCT_VIEW]);

        $this->assertTrue($role->isSystem());
    }

    public function test_grants_returns_true_for_a_granted_permission_and_false_for_one_not_granted(): void
    {
        $role = Role::create('Product Entry', [Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE]);

        $this->assertTrue($role->grants(Permission::PRODUCT_VIEW));
        $this->assertFalse($role->grants(Permission::COST_VIEW));
    }

    public function test_reconstitute_from_storage_round_trips_all_fields(): void
    {
        $permissions = [Permission::PRODUCT_VIEW, Permission::COST_VIEW];

        $role = Role::reconstituteFromStorage('7', 'Manager', $permissions, true);

        $this->assertSame('7', $role->id());
        $this->assertSame('Manager', $role->name());
        $this->assertSame($permissions, $role->permissions());
        $this->assertTrue($role->isSystem());
    }

    public function test_assign_id_can_only_be_called_once(): void
    {
        $role = Role::create('Custom', []);
        $role->assignId('1');

        $this->assertSame('1', $role->id());

        $this->expectException(LogicException::class);
        $role->assignId('2');
    }

    public function test_rename_changes_the_name(): void
    {
        $role = Role::create('Custom', [Permission::PRODUCT_VIEW]);

        $role->rename('Renamed');

        $this->assertSame('Renamed', $role->name());
    }

    public function test_rename_on_a_system_role_throws_cannot_modify_system_role_exception(): void
    {
        $role = Role::reconstituteFromStorage('1', 'Administrator', [Permission::PRODUCT_VIEW], true);

        $this->expectException(CannotModifySystemRoleException::class);

        $role->rename('New Name');
    }

    public function test_update_permissions_changes_the_permission_set(): void
    {
        $role = Role::create('Custom', [Permission::PRODUCT_VIEW]);

        $role->updatePermissions([Permission::COST_VIEW, Permission::COST_MANAGE]);

        $this->assertEqualsCanonicalizing([Permission::COST_VIEW, Permission::COST_MANAGE], $role->permissions());
    }

    public function test_update_permissions_on_a_system_role_throws(): void
    {
        $role = Role::reconstituteFromStorage('1', 'Administrator', [Permission::PRODUCT_VIEW], true);

        $this->expectException(CannotModifySystemRoleException::class);

        $role->updatePermissions([Permission::COST_VIEW]);
    }

    public function test_update_permissions_still_validates_like_the_constructor(): void
    {
        $role = Role::create('Custom', [Permission::PRODUCT_VIEW]);

        $this->expectException(InvalidArgumentException::class);

        $role->updatePermissions([Permission::PRODUCT_VIEW, 'not_a_permission']);
    }
}
