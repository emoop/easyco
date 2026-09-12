<?php

namespace EasyCo\Staff\Tests;

use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class StaffTest extends TestCase
{
    private function persistedRole(array $permissions = [Permission::PRODUCT_VIEW]): Role
    {
        return Role::reconstituteFromStorage('1', 'Test Role', $permissions, false);
    }

    public function test_empty_email_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Staff::create('', 'a-hash', 'Petar', $this->persistedRole());
    }

    public function test_malformed_email_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Staff::create('not-an-email', 'a-hash', 'Petar', $this->persistedRole());
    }

    public function test_mixed_case_email_is_lowercased(): void
    {
        $staff = Staff::create('Petar@Example.COM', 'a-hash', 'Petar', $this->persistedRole());

        $this->assertSame('petar@example.com', $staff->email());
    }

    public function test_empty_password_hash_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Staff::create('petar@example.com', '', 'Petar', $this->persistedRole());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Staff::create('petar@example.com', 'a-hash', '  ', $this->persistedRole());
    }

    public function test_a_role_without_an_id_throws(): void
    {
        $unpersistedRole = Role::create('Custom', [Permission::PRODUCT_VIEW]);

        $this->expectException(InvalidArgumentException::class);

        Staff::create('petar@example.com', 'a-hash', 'Petar', $unpersistedRole);
    }

    public function test_create_always_produces_an_active_staff(): void
    {
        $staff = Staff::create('petar@example.com', 'a-hash', 'Petar', $this->persistedRole());

        $this->assertTrue($staff->isActive());
    }

    public function test_can_delegates_to_the_roles_grants_when_active(): void
    {
        $role = $this->persistedRole([Permission::PRODUCT_VIEW]);
        $staff = Staff::reconstituteFromStorage('1', 'petar@example.com', 'a-hash', 'Petar', $role, true);

        $this->assertTrue($staff->can(Permission::PRODUCT_VIEW));
        $this->assertFalse($staff->can(Permission::COST_VIEW));
    }

    public function test_a_deactivated_staff_is_denied_every_permission_regardless_of_role(): void
    {
        $role = $this->persistedRole(array_values(Permission::cases()));
        $staff = Staff::reconstituteFromStorage('1', 'petar@example.com', 'a-hash', 'Petar', $role, false);

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($staff->can($permission));
        }
    }

    public function test_assign_id_can_only_be_called_once(): void
    {
        $staff = Staff::create('petar@example.com', 'a-hash', 'Petar', $this->persistedRole());
        $staff->assignId('1');

        $this->assertSame('1', $staff->id());

        $this->expectException(LogicException::class);
        $staff->assignId('2');
    }
}
