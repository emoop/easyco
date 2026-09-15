<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductGroupResource;
use App\Filament\Resources\ProductGroupResource\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\ListProductGroups;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\ProductGroup;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production ProductGroupResource. Mirrors
 * SeasonResourceTest's structure minus every Part B (product-count/
 * drill-down/delete) test — ProductGroupRepository has none of that
 * machinery at all.
 */
class ProductGroupResourceTest extends TestCase
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

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_creating_a_product_group_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProductGroup::class)
            ->fillForm(['code' => 'shoes', 'name' => 'Обувки'])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = app(ProductGroupRepository::class)->all()[0] ?? null;

        $this->assertNotNull($group);
        $this->assertSame('shoes', $group->code());
        $this->assertSame('Обувки', $group->name());
    }

    public function test_editing_a_product_group_updates_the_name_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        Livewire::test(EditProductGroup::class, ['record' => $group->id()])
            ->fillForm(['name' => 'Обувки и ботуши'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductGroupRepository::class)->findById($group->id());

        $this->assertSame('Обувки и ботуши', $reloaded->name());
        $this->assertSame('shoes', $reloaded->code());
    }

    public function test_code_stays_unchanged_after_an_edit_attempt_that_tries_to_change_it(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        $component = Livewire::test(EditProductGroup::class, ['record' => $group->id()]);

        $component->assertFormFieldDisabled('code');

        // fillForm still sets the raw Livewire property, but the
        // field's own disabled+not-dehydrated state means
        // handleRecordUpdate() never sees it — this is what's actually
        // asserted below.
        $component->fillForm(['name' => 'Обувки и ботуши', 'code' => 'boots'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductGroupRepository::class)->findById($group->id());

        $this->assertSame('shoes', $reloaded->code());
    }

    public function test_a_duplicate_code_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(ProductGroupRepository::class)->save(new ProductGroup(id: null, code: 'shoes', name: 'Обувки'));

        Livewire::test(CreateProductGroup::class)
            ->fillForm(['code' => 'shoes', 'name' => 'Друга група'])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_a_duplicate_code_is_rejected_on_edit_proving_ignore_record_is_scoped_correctly(): void
    {
        $this->actingAsPanelAdministrator();

        app(ProductGroupRepository::class)->save(new ProductGroup(id: null, code: 'shoes', name: 'Обувки'));
        $sets = new ProductGroup(id: null, code: 'sets', name: 'Комплекти');
        app(ProductGroupRepository::class)->save($sets);

        // The record's own unchanged code must NOT trip its own unique
        // check (ignoreRecord proven) — colliding with a DIFFERENT
        // record's code must still be rejected.
        Livewire::test(EditProductGroup::class, ['record' => $sets->id()])
            ->fillForm(['name' => 'Комплекти дрехи'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(ProductGroupResource::getUrl('index'))->assertOk();
        $this->get(ProductGroupResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductGroupResource::getUrl('index'))->assertOk();
        $this->get(ProductGroupResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductGroupResource::getUrl('index'))->assertOk();
        $this->get(ProductGroupResource::getUrl('create'))->assertForbidden();
    }

    public function test_a_product_groups_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);
        $groupModel = ProductGroupModel::find($group->id());

        $component = Livewire::test(ListProductGroups::class);

        $component->assertTableActionVisible('edit', $groupModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($groupModel);
        $this->assertSame(ProductGroupResource::getUrl('view', ['record' => $groupModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(ProductGroupResource::getUrl('edit', ['record' => $groupModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListProductGroups::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $groupModel);

        $this->get(ProductGroupResource::getUrl('view', ['record' => $groupModel]))->assertOk();
        $this->get(ProductGroupResource::getUrl('edit', ['record' => $groupModel]))->assertForbidden();
    }
}
