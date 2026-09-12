<?php

namespace Tests\Feature;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentRoleRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): RoleRepository
    {
        return app(RoleRepository::class);
    }

    public function test_the_real_staff_roles_table_has_the_documented_columns(): void
    {
        // Confirms the actual column list this repository depends on —
        // not just trusting the migration file (CLAUDE.md rule 2/
        // project convention).
        $createTable = DB::select('SHOW CREATE TABLE staff_roles')[0]->{'Create Table'};

        $this->assertStringContainsString('`id`', $createTable);
        $this->assertStringContainsString('`name`', $createTable);
        $this->assertStringContainsString('`permissions`', $createTable);
        $this->assertStringContainsString('`is_system`', $createTable);
        $this->assertStringContainsString('`created_at`', $createTable);
        $this->assertStringContainsString('`updated_at`', $createTable);
    }

    public function test_save_insert_then_find_by_id_round_trips(): void
    {
        $permissions = [Permission::PRODUCT_VIEW, Permission::COST_VIEW];
        $role = Role::create('Custom Role', $permissions);

        $this->repository()->save($role);

        $this->assertNotNull($role->id());

        $reloaded = $this->repository()->findById($role->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($role->id(), $reloaded->id());
        $this->assertSame('Custom Role', $reloaded->name());
        $this->assertEqualsCanonicalizing($permissions, $reloaded->permissions());
        $this->assertFalse($reloaded->isSystem());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_system_role_by_name_finds_a_system_role(): void
    {
        $role = Role::createSystemRole('Administrator', [Permission::STAFF_MANAGE]);
        $this->repository()->save($role);

        $found = $this->repository()->findSystemRoleByName('Administrator');

        $this->assertNotNull($found);
        $this->assertSame($role->id(), $found->id());
    }

    public function test_find_system_role_by_name_returns_null_for_a_nonexistent_name(): void
    {
        $this->assertNull($this->repository()->findSystemRoleByName('Nonexistent'));
    }

    public function test_find_system_role_by_name_returns_null_when_the_matching_name_is_not_a_system_role(): void
    {
        $custom = Role::create('Manager', [Permission::PRODUCT_VIEW]);
        $this->repository()->save($custom);

        $system = Role::createSystemRole('Manager', [Permission::PRODUCT_VIEW, Permission::COST_VIEW]);
        $this->repository()->save($system);

        $found = $this->repository()->findSystemRoleByName('Manager');

        $this->assertNotNull($found);
        $this->assertSame($system->id(), $found->id());
        $this->assertTrue($found->isSystem());
    }
}
