<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivityLogJournal;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production ActivityLogJournal page — the global,
 * non-product-scoped browse view. Fixture helpers mirror
 * ProductActivityLogTest's own established shapes.
 */
class ActivityLogJournalTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    public function test_the_nav_link_and_direct_access_are_both_hidden_and_forbidden_when_the_setting_is_off_regardless_of_role(): void
    {
        app(SiteSettingsRepository::class)->forget('admin.activity_log_enabled');

        $this->actingAsStaffRole('Administrator');
        $this->assertFalse(ActivityLogJournal::shouldRegisterNavigation());
        $this->get(ActivityLogJournal::getUrl())->assertForbidden();

        $this->actingAsStaffRole('Manager');
        $this->assertFalse(ActivityLogJournal::shouldRegisterNavigation());
        $this->get(ActivityLogJournal::getUrl())->assertForbidden();
    }

    public function test_the_nav_link_is_visible_and_access_is_ok_for_administrator_when_the_setting_is_on(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->actingAsStaffRole('Administrator');

        $this->assertTrue(ActivityLogJournal::shouldRegisterNavigation());
        $this->get(ActivityLogJournal::getUrl())->assertOk();
    }

    public function test_manager_and_product_entry_are_forbidden_even_when_the_setting_is_on(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->actingAsStaffRole('Manager');
        $this->assertFalse(ActivityLogJournal::shouldRegisterNavigation());
        $this->get(ActivityLogJournal::getUrl())->assertForbidden();

        $this->actingAsStaffRole('Product Entry');
        $this->assertFalse(ActivityLogJournal::shouldRegisterNavigation());
        $this->get(ActivityLogJournal::getUrl())->assertForbidden();
    }

    public function test_the_table_shows_rows_across_every_entity_type(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        $this->actingAsStaffRole('Administrator');

        ActivityLogModel::create([
            'entity_type' => 'product',
            'entity_id' => '1',
            'action' => 'created',
            'occurred_at' => now(),
        ]);
        ActivityLogModel::create([
            'entity_type' => 'price_list_item',
            'entity_id' => '42',
            'action' => 'updated',
            'field' => 'amount',
            'old_value' => '10.00',
            'new_value' => '12.00',
            'occurred_at' => now(),
        ]);

        $component = Livewire::test(ActivityLogJournal::class);
        $rows = $component->instance()->getTable()->getRecords();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['price_list_item', 'product'],
            $rows->pluck('entity_type')->sort()->values()->all(),
        );
    }

    public function test_searching_by_entity_id_returns_only_that_products_rows(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        $this->actingAsStaffRole('Administrator');

        ActivityLogModel::create([
            'entity_type' => 'product',
            'entity_id' => 'product-aaa',
            'action' => 'created',
            'occurred_at' => now(),
        ]);
        ActivityLogModel::create([
            'entity_type' => 'product',
            'entity_id' => 'product-bbb',
            'action' => 'created',
            'occurred_at' => now(),
        ]);

        $component = Livewire::test(ActivityLogJournal::class)
            ->searchTable('product-aaa');

        $rows = $component->instance()->getTable()->getRecords();

        $this->assertCount(1, $rows);
        $this->assertSame('product-aaa', $rows->first()->entity_id);
    }
}
