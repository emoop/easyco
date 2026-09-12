<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Exceptions\StaffEmailAlreadyRegisteredException;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentStaffRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): StaffRepository
    {
        return app(StaffRepository::class);
    }

    private function persistedRole(string $name = 'Manager', array $permissions = [Permission::PRODUCT_VIEW, Permission::COST_VIEW]): Role
    {
        $role = Role::createSystemRole($name, $permissions);
        app(RoleRepository::class)->save($role);

        return $role;
    }

    public function test_the_real_staff_table_has_the_documented_columns_the_unique_email_key_and_the_role_fk(): void
    {
        $createTable = DB::select('SHOW CREATE TABLE staff')[0]->{'Create Table'};

        $this->assertStringContainsString('`id`', $createTable);
        $this->assertStringContainsString('`email`', $createTable);
        $this->assertStringContainsString('`password`', $createTable);
        $this->assertStringContainsString('`name`', $createTable);
        $this->assertStringContainsString('`role_id`', $createTable);
        $this->assertStringContainsString('`is_active`', $createTable);
        $this->assertStringContainsString('`deleted_at`', $createTable);
        $this->assertStringContainsString('UNIQUE KEY `staff_email_unique` (`email`)', $createTable);
        $this->assertStringContainsString('CONSTRAINT `staff_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `staff_roles` (`id`)', $createTable);
    }

    public function test_save_insert_then_find_by_id_round_trips_every_field_with_a_fully_hydrated_role(): void
    {
        $role = $this->persistedRole('Manager', [Permission::PRODUCT_VIEW, Permission::COST_VIEW]);
        $staff = Staff::create('petar@example.com', 'a-hashed-value', 'Petar', $role);

        $this->repository()->save($staff);

        $this->assertNotNull($staff->id());

        $reloaded = $this->repository()->findById($staff->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($staff->id(), $reloaded->id());
        $this->assertSame('petar@example.com', $reloaded->email());
        $this->assertSame('a-hashed-value', $reloaded->passwordHash());
        $this->assertSame('Petar', $reloaded->name());
        $this->assertTrue($reloaded->isActive());

        // The whole point of the hydration design: role() is a fully
        // hydrated Role, not a bare id.
        $this->assertSame($role->id(), $reloaded->role()->id());
        $this->assertSame('Manager', $reloaded->role()->name());
        $this->assertEqualsCanonicalizing([Permission::PRODUCT_VIEW, Permission::COST_VIEW], $reloaded->role()->permissions());
        $this->assertTrue($reloaded->role()->isSystem());
    }

    public function test_find_by_email_round_trips_and_is_case_insensitive(): void
    {
        $role = $this->persistedRole();
        $staff = Staff::create('Petar@Example.com', 'a-hashed-value', 'Petar', $role);
        $this->repository()->save($staff);

        $found = $this->repository()->findByEmail('PETAR@EXAMPLE.COM');

        $this->assertNotNull($found);
        $this->assertSame($staff->id(), $found->id());
        $this->assertSame('petar@example.com', $found->email());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_email_for_a_nonexistent_email_returns_null(): void
    {
        $this->assertNull($this->repository()->findByEmail('nobody@example.com'));
    }

    public function test_saving_a_duplicate_email_throws_and_persists_no_duplicate_row(): void
    {
        $role = $this->persistedRole();
        $first = Staff::create('petar@example.com', 'hash-one', 'Petar', $role);
        $this->repository()->save($first);

        $second = Staff::create('PETAR@EXAMPLE.COM', 'hash-two', 'Someone Else', $role);

        $this->expectException(StaffEmailAlreadyRegisteredException::class);

        try {
            $this->repository()->save($second);
        } finally {
            $this->assertSame(1, StaffModel::count());
        }
    }

    public function test_any_returns_false_when_no_staff_exist(): void
    {
        $this->assertFalse($this->repository()->any());
    }

    public function test_any_returns_true_after_one_staff_is_saved(): void
    {
        $role = $this->persistedRole();
        $this->repository()->save(Staff::create('petar@example.com', 'a-hash', 'Petar', $role));

        $this->assertTrue($this->repository()->any());
    }

    public function test_any_still_returns_true_after_the_sole_staff_is_soft_deleted(): void
    {
        $role = $this->persistedRole();
        $staff = Staff::create('petar@example.com', 'a-hash', 'Petar', $role);
        $this->repository()->save($staff);

        StaffModel::find($staff->id())->delete();

        $this->assertNotNull(StaffModel::withTrashed()->find($staff->id())?->deleted_at);
        $this->assertTrue($this->repository()->any());
    }
}
