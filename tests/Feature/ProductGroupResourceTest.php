<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductGroupResource;
use App\Filament\Resources\ProductGroupResource\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroupResource\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroupResource\Pages\RelatedProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Product;
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
 * SeasonResourceTest's structure exactly, including its Part B
 * (product-count/drill-down/delete) tests.
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

    public function test_the_count_column_shows_the_real_number_of_products_using_this_group(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignProductGroup($group->id());
            app(ProductRepository::class)->save($product);
        }

        $component = Livewire::test(ListProductGroups::class);

        $component->assertTableColumnStateSet('products_count', 3, record: ProductGroupModel::find($group->id()));
    }

    public function test_delete_is_blocked_when_the_group_is_still_in_use(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignProductGroup($group->id());
        app(ProductRepository::class)->save($product);

        Livewire::test(ListProductGroups::class)
            ->callTableAction('delete', ProductGroupModel::find($group->id()))
            ->assertNotified();

        $this->assertNotNull(app(ProductGroupRepository::class)->findById($group->id()));
    }

    public function test_delete_succeeds_when_the_group_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        Livewire::test(ListProductGroups::class)
            ->callTableAction('delete', ProductGroupModel::find($group->id()));

        $this->assertNull(app(ProductGroupRepository::class)->findById($group->id()));
    }

    public function test_bulk_unlink_actually_detaches_the_selected_products(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        $productIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignProductGroup($group->id());
            app(ProductRepository::class)->save($product);
            $productIds[] = $product->id();
        }

        Livewire::test(RelatedProducts::class, ['record' => $group->id()])
            ->callTableBulkAction('detach', $productIds);

        foreach ($productIds as $productId) {
            $reloaded = app(ProductRepository::class)->findById($productId);
            $this->assertNull($reloaded->productGroupId());
        }

        $this->assertSame(0, app(ProductGroupRepository::class)->countProductsUsing($group->id()));
    }

    public function test_bulk_unlink_skips_an_already_detached_product_without_erroring(): void
    {
        $this->actingAsPanelAdministrator();

        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        app(ProductGroupRepository::class)->save($group);

        $stillAttached = Product::createSimple('Still Attached', 'SKU-1', 'still-attached');
        $stillAttached->assignProductGroup($group->id());
        app(ProductRepository::class)->save($stillAttached);

        $alreadyDetached = Product::createSimple('Already Detached', 'SKU-2', 'already-detached');
        app(ProductRepository::class)->save($alreadyDetached);

        Livewire::test(RelatedProducts::class, ['record' => $group->id()])
            ->callTableBulkAction('detach', [$stillAttached->id(), $alreadyDetached->id()]);

        $reloadedAttached = app(ProductRepository::class)->findById($stillAttached->id());
        $this->assertNull($reloadedAttached->productGroupId());
    }
}
