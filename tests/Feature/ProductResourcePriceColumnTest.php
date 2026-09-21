<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Pricing\Contracts\PriceListRepository;
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
 * Exercises ProductResource's table price column — regular price alone,
 * or struck-through regular + sale price when a sale price exists.
 * Fixture helpers mirror ProductPricingAndStockTest's own established
 * shapes (same staff/role and createSimpleProduct() construction).
 */
class ProductResourcePriceColumnTest extends TestCase
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

    public function test_a_product_with_only_a_regular_price_shows_it_plainly(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Air Max', 'air-max', ['regular_price' => '29.99']);

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'air-max');

        $this->assertSame('29.99', ProductResource::priceDisplayHtml($row));
    }

    public function test_a_product_with_both_prices_shows_the_regular_price_struck_through_plus_the_sale_price(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Stan Smith', 'stan-smith', [
            'regular_price' => '80.00',
            'sale_price' => '60.00',
        ]);

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'stan-smith');

        $html = ProductResource::priceDisplayHtml($row);

        $this->assertSame('<s>80.00</s> 60.00', $html);
    }

    public function test_a_product_with_neither_price_shows_a_dash_not_an_error(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Gazelle', 'gazelle');

        $row = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->firstWhere('slug', 'gazelle');

        $this->assertNull($row->getAttribute('regular_price_minor'));
        $this->assertSame('—', ProductResource::priceDisplayHtml($row));
    }

    /**
     * priceMinorSubquery() was rewritten (see its own docblock) from a
     * plain join on catalog_variations.id = target_id to a raw
     * correlated subquery comparing target_id (CAST to CHAR) against
     * the resolved variation id — a performance fix only, no intended
     * behavior change, proven correct via real EXPLAIN ANALYZE against
     * a ~17,000+ SIMPLE product scale (see this task's own report, not
     * reproducible at this test's tiny seeded scale). Raw correlated
     * SQL is more fragile to get wrong than the previous
     * ->whereColumn() form (e.g. a missing/broken correlation would
     * silently return the WRONG product's price, or the first
     * unrelated row, rather than erroring), so this test specifically
     * asserts several SIMPLE products with DISTINCT regular/sale prices
     * each resolve their own, correct values in the same list render —
     * a test that would fail plainly if the correlation were ever
     * broken, unlike the single-product tests above.
     */
    public function test_multiple_products_each_resolve_their_own_correct_prices_in_the_same_list(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Ultraboost', 'ultraboost', ['regular_price' => '150.00']);
        $this->createSimpleProduct('Forum Low', 'forum-low', [
            'regular_price' => '90.00',
            'sale_price' => '70.00',
        ]);
        $this->createSimpleProduct('Samba', 'samba', ['regular_price' => '85.00']);

        $records = Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords();

        $this->assertSame('150.00', ProductResource::priceDisplayHtml($records->firstWhere('slug', 'ultraboost')));
        $this->assertSame('<s>90.00</s> 70.00', ProductResource::priceDisplayHtml($records->firstWhere('slug', 'forum-low')));
        $this->assertSame('85.00', ProductResource::priceDisplayHtml($records->firstWhere('slug', 'samba')));
    }

    public function test_a_product_on_a_fresh_unseeded_store_renders_the_list_without_error(): void
    {
        // Deliberately NOT running PricingSystemListsSeeder — neither
        // reserved system list exists, exactly the fresh-store case
        // priceMinorSubquery()'s own docblock documents.
        $this->actingAsStaffRole('Administrator');

        $this->createSimpleProduct('Superstar', 'superstar');

        $response = Livewire::test(ListProducts::class);
        $response->assertOk();

        $row = $response->instance()->getTable()->getRecords()->firstWhere('slug', 'superstar');
        $this->assertSame('—', ProductResource::priceDisplayHtml($row));
    }
}
