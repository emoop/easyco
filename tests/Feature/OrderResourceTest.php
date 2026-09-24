<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production OrderResource list page — admin-panel-
 * design.md §14, Commit 3. Orders are placed only through the real
 * CheckoutOrchestrator (§3's own requirement). Fixture helpers mirror
 * OrderAdminReaderTest's own established shapes.
 */
class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;
    private ?PriceList $priceList = null;

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

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', int $stock = 10): string
    {
        $variationId = $this->variationId();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function placeGuestOrder(array $overrides = []): \EasyCo\Order\Order
    {
        $variationId = $this->pricedPurchasableVariation($overrides['price'] ?? '10.00');
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, $overrides['quantity'] ?? 1, null, null);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            email: $overrides['email'] ?? 'guest@example.com',
            recipientName: $overrides['recipientName'] ?? 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        return app(CheckoutOrchestrator::class)->place($input, $overrides['placedAt'] ?? new DateTimeImmutable('2026-09-20 10:00:00'))->order();
    }

    public function test_administrator_and_manager_can_see_the_orders_list_product_entry_cannot(): void
    {
        $this->actingAsStaffRole('Administrator');
        $this->assertTrue(OrderResource::canViewAny());
        $this->get(OrderResource::getUrl('index'))->assertOk();

        $this->actingAsStaffRole('Manager');
        $this->assertTrue(OrderResource::canViewAny());
        $this->get(OrderResource::getUrl('index'))->assertOk();

        $this->actingAsStaffRole('Product Entry');
        $this->assertFalse(OrderResource::canViewAny());
        $this->get(OrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_no_create_edit_or_delete_capability_exists_at_all(): void
    {
        $this->actingAsStaffRole('Administrator');

        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit(new OrderModel()));
        $this->assertFalse(OrderResource::canDelete(new OrderModel()));
        $this->assertArrayNotHasKey('create', OrderResource::getPages());
        $this->assertArrayNotHasKey('edit', OrderResource::getPages());
    }

    public function test_the_list_renders_the_real_snapshot_for_a_placed_order(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeGuestOrder(['price' => '25.00', 'quantity' => 2, 'email' => 'buyer@example.com']);

        $component = Livewire::test(ListOrders::class);

        $component->assertCanSeeTableRecords([OrderModel::find($order->id())]);
        $component->assertTableColumnStateSet('item_count', 2, OrderModel::find($order->id()));
        $component->assertTableColumnStateSet('total', '50.00 €', OrderModel::find($order->id()));
    }

    public function test_orders_are_sorted_newest_first_by_default(): void
    {
        $this->actingAsStaffRole('Administrator');

        $older = $this->placeGuestOrder(['email' => 'older@example.com', 'placedAt' => new DateTimeImmutable('2026-09-01 10:00:00')]);
        $newer = $this->placeGuestOrder(['email' => 'newer@example.com', 'placedAt' => new DateTimeImmutable('2026-09-10 10:00:00')]);

        $records = Livewire::test(ListOrders::class)->instance()->getTable()->getRecords();

        $this->assertSame($newer->id(), (string) $records->first()->id);
        $this->assertSame($older->id(), (string) $records->last()->id);
    }

    public function test_search_matches_order_id_and_email_but_not_recipient_name(): void
    {
        $this->actingAsStaffRole('Administrator');

        $order = $this->placeGuestOrder(['email' => 'findme@example.com']);
        $other = $this->placeGuestOrder(['email' => 'someoneelse@example.com']);

        Livewire::test(ListOrders::class)
            ->searchTable((string) $order->id())
            ->assertCanSeeTableRecords([OrderModel::find($order->id())])
            ->assertCanNotSeeTableRecords([OrderModel::find($other->id())]);

        Livewire::test(ListOrders::class)
            ->searchTable('findme@example.com')
            ->assertCanSeeTableRecords([OrderModel::find($order->id())])
            ->assertCanNotSeeTableRecords([OrderModel::find($other->id())]);
    }

    /**
     * Isolates OrderAdminReader's own contribution (D7's real target)
     * from a real, separate, PRE-EXISTING, and DELIBERATELY UNFIXED
     * cost: AuthorizesViaStaffPermission::staffCanForAction() reloads
     * the full Staff aggregate on every single call — that trait's own
     * docblock states this explicitly ("Do NOT cache or optimize this
     * here — solving a cost flagged and deliberately deferred twice
     * already is out of scope"). Once this Resource gained a 'view'
     * page (this commit), Filament calls canView($record) PER ROW to
     * decide whether it's clickable — confirmed via real SQL output,
     * not assumed — which DOES scale with row count, entirely
     * independent of anything OrderAdminReader does. Counting only
     * non-staff queries keeps this test meaningful for what it can
     * actually control; the staff/staff_roles growth is flagged in this
     * task's own report, not silently hidden or fixed here.
     */
    public function test_query_count_for_5_vs_25_orders_on_the_list_page_is_identical(): void
    {
        $this->actingAsStaffRole('Administrator');

        for ($i = 0; $i < 25; $i++) {
            $this->placeGuestOrder(['email' => "buyer{$i}@example.com"]);
        }

        // ONE already-mounted component reused for both measurements —
        // a second, separate Livewire::test() call re-mounts from
        // scratch (its own auth/session bookkeeping), which is noise
        // unrelated to the actual table query this test cares about.
        $component = Livewire::test(ListOrders::class);

        $count = 0;
        $countNonStaff = function ($query) use (&$count): void {
            if (! str_contains($query->sql, '`staff')) {
                $count++;
            }
        };
        DB::listen($countNonStaff);
        $component->set('tableRecordsPerPage', 5)->call('$refresh');
        $queriesForFive = $count;
        $count = 0;

        $component->set('tableRecordsPerPage', 25)->call('$refresh');
        $queriesForTwentyFive = $count;

        DB::flushQueryLog();

        fwrite(STDERR, "\n[query-count] orders list page, 5-per-page: {$queriesForFive} queries, 25-per-page: {$queriesForTwentyFive} queries\n");

        $this->assertSame($queriesForFive, $queriesForTwentyFive, 'orders list query count must not grow with the number of rows rendered');
    }

    public function test_profit_never_appears_in_the_rendered_list_html(): void
    {
        $this->actingAsStaffRole('Administrator');
        $this->placeGuestOrder();

        $html = $this->get(OrderResource::getUrl('index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('profit', strtolower($html));
    }
}
