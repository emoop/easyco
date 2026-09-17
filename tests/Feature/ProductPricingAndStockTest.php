<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises Phase 2 — Price + Stock for SIMPLE products. Fixture
 * helpers mirror ProductResourceTest's own established shapes
 * deliberately (same staff/role construction), not reinvented.
 */
class ProductPricingAndStockTest extends TestCase
{
    use RefreshDatabase;

    /** Real dev-DB precedent for this task: PricingSystemListsSeeder must actually run once — see this task's own report on that gap. */
    protected function setUp(): void
    {
        parent::setUp();

        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
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

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
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

    private function universalVariationId(ProductModel $product): string
    {
        return (string) $product->variations()->value('id');
    }

    public function test_creating_a_product_with_regular_sale_price_cost_and_stock_persists_all_four_correctly(): void
    {
        $this->actingAsStaffRole('Administrator');

        $product = $this->createSimpleProduct('Air Max', 'air-max', [
            'regular_price' => '29.99',
            'sale_price' => '19.99',
            'cost' => '10.00',
            'stock_quantity' => '50',
        ]);

        $variationId = $this->universalVariationId($product);

        $regularList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');
        $regularItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($regularList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertNotNull($regularItem);
        $this->assertSame(PriceListItemTargetType::VARIATION, $regularItem->targetType());
        $this->assertSame($variationId, $regularItem->targetId());
        $this->assertSame('29.99', $regularItem->price()->gross()->decimalValue());

        $saleList = app(PriceListRepository::class)->findSystemListByName('Manual Sale');
        $saleItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($saleList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertNotNull($saleItem);
        $this->assertSame('19.99', $saleItem->price()->gross()->decimalValue());

        $cost = app(ProductCostRepository::class)->findByPriceableIdAndCurrency($variationId, DefaultCurrency::get()->code());
        $this->assertNotNull($cost);
        $this->assertSame('10.00', $cost->cost()->decimalValue());

        $stock = app(StockLevelRepository::class)->findByVariationId($variationId);
        $this->assertSame(50, $stock->quantity());
    }

    public function test_editing_changes_only_what_actually_changed(): void
    {
        // The activity log is OFF by default (LocaleSettings' own
        // Activity Log tab) — turned on here specifically because this
        // test's own diff-guard confirmation reads it.
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->actingAsStaffRole('Administrator');

        $product = $this->createSimpleProduct('Stan Smith', 'stan-smith', [
            'regular_price' => '80.00',
            'cost' => '30.00',
            'stock_quantity' => '5',
        ]);
        $variationId = $this->universalVariationId($product);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['sale_price' => '60.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $regularList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');
        $regularItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($regularList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertSame('80.00', $regularItem->price()->gross()->decimalValue());

        $saleList = app(PriceListRepository::class)->findSystemListByName('Manual Sale');
        $saleItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($saleList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertSame('60.00', $saleItem->price()->gross()->decimalValue());

        $cost = app(ProductCostRepository::class)->findByPriceableIdAndCurrency($variationId, DefaultCurrency::get()->code());
        $this->assertSame('30.00', $cost->cost()->decimalValue());

        $stock = app(StockLevelRepository::class)->findByVariationId($variationId);
        $this->assertSame(5, $stock->quantity());

        // Real diff-guard confirmation: only sale_price was actually
        // different, so only sale_price should have logged an 'updated'
        // entry — regular_price/cost/stock_quantity, resubmitted
        // unchanged, must not appear at all.
        $updatedFields = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->where('action', 'updated')
            ->pluck('field')
            ->all();
        $this->assertSame(['sale_price'], $updatedFields);
    }

    public function test_clearing_the_sale_price_removes_the_price_list_item_and_leaves_regular_price_and_cost_untouched(): void
    {
        $this->actingAsStaffRole('Administrator');

        $product = $this->createSimpleProduct('Chelsea Boot', 'chelsea-boot', [
            'regular_price' => '150.00',
            'sale_price' => '120.00',
            'cost' => '70.00',
        ]);
        $variationId = $this->universalVariationId($product);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['sale_price' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $saleList = app(PriceListRepository::class)->findSystemListByName('Manual Sale');
        $saleItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($saleList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertNull($saleItem);

        $regularList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');
        $regularItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($regularList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertSame('150.00', $regularItem->price()->gross()->decimalValue());

        $cost = app(ProductCostRepository::class)->findByPriceableIdAndCurrency($variationId, DefaultCurrency::get()->code());
        $this->assertSame('70.00', $cost->cost()->decimalValue());
    }

    public function test_a_price_manage_less_staff_member_sees_price_fields_but_cannot_change_them(): void
    {
        $this->actingAsStaffRole('Administrator');
        $product = $this->createSimpleProduct('Superstar', 'superstar', ['regular_price' => '99.00']);
        $variationId = $this->universalVariationId($product);

        $this->actingAsStaffRole('Product Entry');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldExists('regular_price')
            ->assertFormFieldDisabled('regular_price')
            ->assertFormFieldExists('sale_price')
            ->assertFormFieldDisabled('sale_price')
            ->fillForm(['regular_price' => '1.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $regularList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');
        $regularItem = app(PriceListItemRepository::class)->findByPriceListIdAndTarget($regularList->id(), PriceListItemTargetType::VARIATION, $variationId);
        $this->assertSame('99.00', $regularItem->price()->gross()->decimalValue());
    }

    public function test_a_cost_view_less_staff_member_never_sees_the_cost_field_on_edit_or_view(): void
    {
        $this->actingAsStaffRole('Administrator');
        $product = $this->createSimpleProduct('Gazelle', 'gazelle', ['cost' => '40.00']);

        $this->actingAsStaffRole('Product Entry');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldDoesNotExist('cost');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertSchemaComponentHidden('cost');
    }

    public function test_the_real_permission_matrix_for_price_and_cost_on_edit_and_view(): void
    {
        // staffWithRole()/actingAsStaffRole() always create a NEW staff
        // row keyed to a role-derived email (mirrors ProductResourceTest's
        // own established helper shape) — 'Administrator' is therefore
        // acted-as only ONCE here, not re-created inside the loop below.
        $this->actingAsStaffRole('Administrator');
        $product = $this->createSimpleProduct('Matrix Product', 'matrix-product', [
            'regular_price' => '50.00',
            'cost' => '20.00',
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldEnabled('regular_price')
            ->assertFormFieldEnabled('sale_price')
            ->assertFormFieldExists('cost')
            ->assertFormFieldEnabled('cost');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertSchemaComponentVisible('cost');

        foreach (['Manager'] as $roleName) {
            $this->actingAsStaffRole($roleName);

            Livewire::test(EditProduct::class, ['record' => $product->id])
                ->assertFormFieldEnabled('regular_price')
                ->assertFormFieldEnabled('sale_price')
                ->assertFormFieldExists('cost')
                ->assertFormFieldEnabled('cost');

            Livewire::test(ViewProduct::class, ['record' => $product->id])
                ->assertSchemaComponentVisible('cost');
        }

        $this->actingAsStaffRole('Product Entry');

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldDisabled('regular_price')
            ->assertFormFieldDisabled('sale_price')
            ->assertFormFieldDoesNotExist('cost');

        Livewire::test(ViewProduct::class, ['record' => $product->id])
            ->assertSchemaComponentHidden('cost');
    }
}
