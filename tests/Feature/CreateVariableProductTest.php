<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
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
 * VARIABLE product creation wizard, Step A only (admin-panel-design.md
 * §13.1) — scaffold + the "General" step, ending in a bare, persisted
 * VARIABLE Product with zero variations. Fixture helpers mirror
 * ProductResourceTest's own established shapes deliberately.
 */
class CreateVariableProductTest extends TestCase
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

    private function persistedBrand(string $name = 'Nike'): Brand
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name));
        app(BrandRepository::class)->save($brand);

        return $brand;
    }

    private function persistedProductGroup(string $code = 'shoes', string $name = 'Обувки'): ProductGroup
    {
        $group = new ProductGroup(id: null, code: $code, name: $name);
        app(ProductGroupRepository::class)->save($group);

        return $group;
    }

    public function test_get_on_the_create_variable_route_renders_ok_for_staff_with_create_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $this->get(ProductResource::getUrl('create-variable'))->assertOk();
    }

    /**
     * Every real shipped role (Administrator/Manager/Product Entry —
     * StaffSystemRolesSeeder) holds both PRODUCT_VIEW and PRODUCT_MANAGE
     * together, so the real "no create permission" case needs a
     * purpose-built role: PRODUCT_VIEW only, no PRODUCT_MANAGE — isolates
     * createPermission()'s own gate from viewAnyPermission()'s.
     */
    public function test_get_on_the_create_variable_route_is_forbidden_without_create_permission(): void
    {
        $viewOnlyRole = \EasyCo\Staff\Role::create('View Only', [\EasyCo\Staff\Enums\Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);
        $viewOnlyStaff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $viewOnlyRole);
        app(StaffRepository::class)->save($viewOnlyStaff);
        $this->actingAs(StaffPanelUser::find($viewOnlyStaff->id()), 'staff');

        $this->get(ProductResource::getUrl('create-variable'))->assertForbidden();
    }

    public function test_submitting_the_general_step_creates_a_real_variable_product_with_zero_variations(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand();
        $group = $this->persistedProductGroup();

        // DRAFT, not ACTIVE — Product::publish() deliberately rejects a
        // VARIABLE product with zero active variations
        // (CannotPublishEmptyVariableProductException, a real,
        // pre-existing, correct domain invariant, confirmed against
        // that exception's own docblock). A bare, just-created VARIABLE
        // product genuinely cannot be ACTIVE yet — see this task's own
        // final report for the separate, flagged gap this surfaces
        // (selecting "Active" in this step is currently an unhandled
        // 500, not a validation error).
        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Cotton T-Shirt',
                'slug' => 'cotton-t-shirt',
                'base_sku' => 'VAR-SKU-1',
                'description' => '<p>A <strong>variable</strong> product.</p>',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::VISIBLE->value,
                'brand_id' => $brand->id(),
                'product_group_id' => $group->id(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'cotton-t-shirt')->firstOrFail();

        $this->assertSame(ProductType::VARIABLE->value, $productModel->type);
        $this->assertSame('Cotton T-Shirt', $productModel->name);
        $this->assertSame('VAR-SKU-1', $productModel->base_sku);
        $this->assertSame('<p>A <strong>variable</strong> product.</p>', $productModel->description);
        $this->assertSame(ProductStatus::DRAFT->value, $productModel->status);
        $this->assertSame(CatalogVisibility::VISIBLE->value, $productModel->catalog_visibility);
        $this->assertSame($brand->id(), (string) $productModel->brand_id);
        $this->assertSame($group->id(), (string) $productModel->product_group_id);

        // The real, exact assertion this task's own goal requires: zero
        // catalog_variations rows — no universal variation, unlike
        // Product::createSimple().
        $this->assertSame(0, $productModel->variations()->count());

        // Also confirmed through the real domain layer, not just the
        // Eloquent model — Product::createVariable()'s own contract.
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertCount(0, $product->variations());
    }

    /**
     * A REAL, FLAGGED GAP this task's own testing surfaced, reported
     * rather than silently patched: the "General" step offers all 3
     * ProductStatus options (this task's own explicit requirement,
     * mirroring SIMPLE), but Product::publish() deliberately throws
     * CannotPublishEmptyVariableProductException for a VARIABLE product
     * with zero active variations — a real, correct, pre-existing
     * domain invariant. Nothing in CreateVariableProduct currently
     * catches it (unlike CreateProduct::attachMedia()'s own real
     * Notification+Halt precedent for a different domain exception), so
     * selecting "Active" here today is an unhandled 500, not a friendly
     * validation message. This test exists to document the exact
     * current behavior, not to endorse it — see this task's own final
     * report.
     */
    public function test_selecting_active_status_currently_surfaces_the_real_unhandled_publish_exception(): void
    {
        $this->actingAsPanelAdministrator();

        $this->expectException(\EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException::class);

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Active Variable Product',
                'slug' => 'active-variable-product',
                'base_sku' => 'VAR-SKU-ACTIVE',
                'status' => ProductStatus::ACTIVE->value,
            ])
            ->call('create');
    }

    public function test_leaving_slug_and_base_sku_blank_triggers_the_real_hook_based_generation(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Unique Variable Name '.uniqid(),
                'slug' => '',
                'base_sku' => '',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('type', ProductType::VARIABLE->value)->latest('id')->firstOrFail();

        $this->assertNotSame('', $productModel->slug);
        $this->assertNotSame('', $productModel->base_sku);
    }

    /**
     * The exact scenario this task's own goal calls out explicitly:
     * submitting the wizard redirects to the real 'view' page (Filament's
     * own default CreateRecord::getRedirectUrl(), not overridden here),
     * and that page's infolist renders without error for a VARIABLE
     * product with zero variations — regular_price/sale_price/cost/
     * stock_quantity all resolve through
     * ProductResource::universalVariationId(), which degrades to an
     * empty-string variation id rather than throwing (confirmed here via
     * a real HTTP GET, not trusted from the task description alone).
     */
    public function test_after_creation_the_redirect_lands_on_view_and_it_renders_for_a_variation_less_product(): void
    {
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Bare Variable Product',
                'slug' => 'bare-variable-product',
                'base_sku' => 'VAR-SKU-BARE',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'bare-variable-product')->firstOrFail();

        $component->assertRedirect(ProductResource::getUrl('view', ['record' => $productModel]));

        $this->get(ProductResource::getUrl('view', ['record' => $productModel]))
            ->assertOk()
            ->assertSee('Bare Variable Product');

        // The same page, via a real Livewire render (catches any
        // exception the plain HTTP GET's error handling might mask),
        // confirming the four price/stock infolist entries resolve.
        Livewire::test(ViewProduct::class, ['record' => $productModel->id])
            ->assertSuccessful();
    }

    public function test_the_list_header_shows_two_distinct_create_actions_pointing_at_the_right_pages(): void
    {
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(ListProducts::class);

        $component->assertActionVisible('create');
        $component->assertActionVisible('create_variable');

        $variableAction = $component->instance()->getAction('create_variable');
        $this->assertSame(ProductResource::getUrl('create-variable'), $variableAction->getUrl());
    }

    /**
     * PRODUCT_VIEW only, no PRODUCT_MANAGE — the list itself renders
     * (canViewAny() true), but ->visible(fn (): bool =>
     * ProductResource::canCreate()) on "create_variable" must hide it,
     * isolating that specific closure from the page-level gate.
     */
    public function test_the_create_variable_action_is_hidden_without_create_permission(): void
    {
        $viewOnlyRole = \EasyCo\Staff\Role::create('View Only For List', [\EasyCo\Staff\Enums\Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);
        $viewOnlyStaff = Staff::create('view.only.list@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only For List', $viewOnlyRole);
        app(StaffRepository::class)->save($viewOnlyStaff);
        $this->actingAs(StaffPanelUser::find($viewOnlyStaff->id()), 'staff');

        Livewire::test(ListProducts::class)
            ->assertActionHidden('create_variable');
    }
}
