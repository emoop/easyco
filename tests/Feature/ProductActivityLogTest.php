<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ProductActivityLog;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Tag;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production ActivityLogger + ProductActivityLog
 * page. Fixture helpers mirror ProductResourceTest's own established
 * shapes deliberately (same staff/role/brand/category/tag
 * construction), not reinvented.
 */
class ProductActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The log is OFF by default (LocaleSettings' own Activity Log tab)
     * — every test in this file except the dedicated "disabled" one
     * below is specifically exercising logging mechanics, so it's
     * turned on here rather than repeated in each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

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

    private function persistedBrand(string $name = 'Nike'): Brand
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name));
        app(BrandRepository::class)->save($brand);

        return $brand;
    }

    private function persistedCategory(string $name): Category
    {
        $category = new Category(id: null, parentId: null, name: $name, slug: strtolower($name));
        app(CategoryRepository::class)->save($category);

        return $category;
    }

    private function persistedTag(string $name): Tag
    {
        $tag = new Tag(id: null, name: $name, slug: strtolower($name));
        app(TagRepository::class)->save($tag);

        return $tag;
    }

    private function createSimpleProduct(string $name, string $slug, array $overrides = []): ProductModel
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(array_merge([
                'name' => $name,
                'slug' => $slug,
                'base_sku' => 'SKU-'.strtoupper($slug),
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ], $overrides))
            ->call('create')
            ->assertHasNoFormErrors();

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    public function test_creating_a_product_logs_exactly_one_created_entry_with_the_real_staff_name(): void
    {
        $admin = $this->actingAsPanelAdministrator();

        $product = $this->createSimpleProduct('Air Max', 'air-max');

        $entries = ActivityLogModel::where('entity_type', 'product')->where('entity_id', $product->id)->get();

        $this->assertCount(1, $entries);
        $this->assertSame('created', $entries[0]->action);
        $this->assertSame($admin->id, $entries[0]->staff_id);
        $this->assertSame($admin->name, $entries[0]->staff_name);
    }

    public function test_editing_and_changing_three_unrelated_fields_logs_three_updated_entries(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand('Adidas');
        $product = $this->createSimpleProduct('Stan Smith', 'stan-smith');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm([
                'name' => 'Stan Smith Classic',
                'status' => ProductStatus::ACTIVE->value,
                'brand_id' => $brand->id(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('action', 'updated')
            ->get()
            ->keyBy('field');

        $this->assertCount(3, $updated);

        $this->assertSame('Stan Smith', $updated['name']->old_value);
        $this->assertSame('Stan Smith Classic', $updated['name']->new_value);

        $this->assertSame(ProductStatus::DRAFT->value, $updated['status']->old_value);
        $this->assertSame(ProductStatus::ACTIVE->value, $updated['status']->new_value);

        $this->assertNull($updated['brand_id']->old_value);
        $this->assertSame($brand->id(), $updated['brand_id']->new_value);
    }

    public function test_editing_without_changing_anything_logs_nothing_new(): void
    {
        $this->actingAsPanelAdministrator();

        $product = $this->createSimpleProduct('Superstar', 'superstar');

        $countAfterCreate = ActivityLogModel::where('entity_type', 'product')->where('entity_id', $product->id)->count();
        $this->assertSame(1, $countAfterCreate);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm([
                'name' => 'Superstar',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $countAfterNoopEdit = ActivityLogModel::where('entity_type', 'product')->where('entity_id', $product->id)->count();
        $this->assertSame(1, $countAfterNoopEdit);
    }

    public function test_adding_one_category_and_removing_one_tag_logs_one_entry_each(): void
    {
        $this->actingAsPanelAdministrator();

        $sneakers = $this->persistedCategory('Sneakers');
        $boots = $this->persistedCategory('Boots');
        $summer = $this->persistedTag('Summer');

        $product = $this->createSimpleProduct('Chelsea Boot', 'chelsea-boot', [
            'categories' => [$sneakers->id()],
            'tags' => [$summer->id()],
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm([
                'categories' => [$sneakers->id(), $boots->id()],
                'tags' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $categoryEntries = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('field', 'categories')
            ->get();
        $this->assertCount(1, $categoryEntries);
        $this->assertNull($categoryEntries[0]->old_value);
        $this->assertSame('Boots', $categoryEntries[0]->new_value);

        $tagEntries = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('field', 'tags')
            ->get();
        $this->assertCount(1, $tagEntries);
        $this->assertSame('Summer', $tagEntries[0]->old_value);
        $this->assertNull($tagEntries[0]->new_value);
    }

    public function test_the_history_page_shows_entries_for_the_right_product_only_ordered_newest_first(): void
    {
        $this->actingAsPanelAdministrator();

        $productOne = $this->createSimpleProduct('Product One', 'product-one');
        Livewire::test(EditProduct::class, ['record' => $productOne->id])
            ->fillForm(['name' => 'Product One Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $productTwo = $this->createSimpleProduct('Product Two', 'product-two');
        Livewire::test(EditProduct::class, ['record' => $productTwo->id])
            ->fillForm(['name' => 'Product Two Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $component = Livewire::test(ProductActivityLog::class, ['record' => $productOne->id]);

        $rows = $component->instance()->getTable()->getRecords();

        $this->assertCount(2, $rows);
        // Newest-first: the 'updated' rename entry (the most recent
        // write to THIS product) comes before its own 'created' entry.
        $this->assertSame('updated', $rows[0]->action);
        $this->assertSame('created', $rows[1]->action);

        foreach ($rows as $row) {
            $this->assertSame((string) $productOne->id, $row->entity_id);
        }
    }

    public function test_the_real_permission_matrix_for_viewing_the_history_page(): void
    {
        $this->actingAsPanelAdministrator();
        $product = $this->createSimpleProduct('Matrix Product', 'matrix-product');

        $this->get(ProductResource::getUrl('activity-log', ['record' => $product->id]))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('activity-log', ['record' => $product->id]))->assertOk();

        // Product Entry holds PRODUCT_VIEW but NOT COST_VIEW
        // (StaffSystemRolesSeeder's own real permission list) — the real
        // gap this gate closes: the activity log records every changed
        // field including cost, so PRODUCT_VIEW alone must no longer be
        // enough to reach it.
        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('activity-log', ['record' => $product->id]))->assertForbidden();

        // Same viewPermission()/PRODUCT_VIEW (+ COST_VIEW) gate as the
        // rest of this Resource — a staff member holding neither is
        // genuinely denied, mirroring ProductResourceTest's own
        // "View Only" role regression guard, inverted (a role with NO
        // product permission at all).
        $noAccessRole = Role::create('No Product Access', []);
        app(RoleRepository::class)->save($noAccessRole);
        $noAccessStaff = Staff::create('no.access@example.com', app(PasswordHasher::class)->hash('password123'), 'No Product Access', $noAccessRole);
        app(StaffRepository::class)->save($noAccessStaff);
        $this->actingAs(StaffPanelUser::find($noAccessStaff->id()), 'staff');
        session()->forget('password_hash_staff');

        $this->get(ProductResource::getUrl('activity-log', ['record' => $product->id]))->assertForbidden();
    }

    /**
     * The real, direct scenario this task closes: History is correctly
     * hidden from the live Cost field for a COST_VIEW-less staff member
     * everywhere already (Phase 2's own gate) — this confirms the
     * SAME staff member also can't reach past cost values through the
     * "History" header action, on both surfaces (action hidden, direct
     * URL 403s), while both COST_VIEW-holding roles (Administrator,
     * Manager) retain full access.
     */
    public function test_a_cost_view_less_staff_member_sees_no_history_action_and_a_direct_url_hit_403s(): void
    {
        $this->actingAsPanelAdministrator();
        $product = $this->createSimpleProduct('History Gate Product', 'history-gate-product');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertActionVisible('history');

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertActionVisible('history');

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertActionHidden('history');

        $this->get(ProductResource::getUrl('activity-log', ['record' => $product->id]))->assertForbidden();
    }

    /**
     * Overrides setUp()'s own opt-in for this one test — the real
     * default-off state (nothing has ever called ->set() on the key).
     */
    public function test_creating_and_editing_a_product_logs_nothing_while_the_setting_is_off_by_default(): void
    {
        app(SiteSettingsRepository::class)->forget('admin.activity_log_enabled');

        $this->actingAsPanelAdministrator();

        $product = $this->createSimpleProduct('Disabled Log Product', 'disabled-log-product');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['name' => 'Disabled Log Product Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $entries = ActivityLogModel::where('entity_type', 'product')->where('entity_id', $product->id)->get();

        $this->assertCount(0, $entries);
    }
}
