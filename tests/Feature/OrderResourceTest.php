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
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
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
        // 'item_count' used to be asserted here; that column was removed
        // from the list by D1, so 'status' — a real `orders` column that
        // took its place in the five-column set — is asserted instead.
        $component->assertTableColumnStateSet('status', OrderStatus::PLACED->value, OrderModel::find($order->id()));
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

    /**
     * Two orders with the IDENTICAL placed_at — proves the real tie-break
     * (Filament's own hasDefaultKeySort, see OrderResource::table()'s own
     * comment on ->defaultSort() for the quoted installed source), not just
     * that placed_at DESC alone happens to look right on unique timestamps.
     */
    public function test_two_orders_with_identical_placed_at_are_tie_broken_by_id_desc(): void
    {
        $this->actingAsStaffRole('Administrator');

        $sameInstant = new DateTimeImmutable('2026-09-15 12:00:00');
        $first = $this->placeGuestOrder(['email' => 'tie-first@example.com', 'placedAt' => $sameInstant]);
        $second = $this->placeGuestOrder(['email' => 'tie-second@example.com', 'placedAt' => $sameInstant]);

        $this->assertGreaterThan((int) $first->id(), (int) $second->id(), 'fixture assumption: ids increase with placement order');

        $records = Livewire::test(ListOrders::class)->instance()->getTable()->getRecords();

        $this->assertSame($second->id(), (string) $records->first()->id);
        $this->assertSame($first->id(), (string) $records->get(1)->id);
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
     * The FULL page query count, no exclusions — previously this test
     * had to filter out `staff`/`staff_roles` queries, because
     * Filament calls canView($record) per row once a 'view' page
     * exists, and AuthorizesViaStaffPermission reloaded the full Staff
     * aggregate on every single call (a real, separate cost from
     * anything OrderAdminReader does). That reload is now memoized per
     * request via App\Services\AuthenticatedStaffResolver (see its own
     * docblock, and AuthorizesViaStaffPermission's updated one) — the
     * per-row authorization cost is gone too, so the raw, unfiltered
     * count is the real assertion now.
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
        DB::listen(function () use (&$count): void {
            $count++;
        });
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

    /**
     * D1 — exactly the five columns, in this order. Asserted on the
     * table's own column set rather than the rendered HTML: the two new
     * quick FILTERS reuse the Channel and Payment method labels above the
     * table, so a plain "this string is absent" check on the page would be
     * meaningless.
     */
    public function test_the_list_shows_exactly_the_five_decision_columns_in_order(): void
    {
        $this->actingAsStaffRole('Administrator');
        $this->placeGuestOrder();

        $columns = array_keys(Livewire::test(ListOrders::class)->instance()->getTable()->getColumns());

        $this->assertSame(['id', 'placed_at', 'recipient_name', 'status', 'total'], $columns);

        foreach (['client_name', 'channel', 'payment_method', 'payment_status', 'item_count'] as $removed) {
            $this->assertNotContains($removed, $columns, "[{$removed}] was removed from the Orders list");
        }
    }

    /**
     * D2 — the payment-method filter matches the order's LATEST payment row
     * (the same D6 definition the View page shows), never "any row": an
     * order whose earlier attempt used method A and whose latest used
     * method B appears under B only.
     */
    public function test_the_payment_method_filter_matches_the_latest_payment_row_only(): void
    {
        $this->actingAsStaffRole('Administrator');

        // Payment method is the ONLY filter — a Channel filter was
        // deliberately not built, since the orders table only ever holds
        // online orders and it would always offer a single value.
        $this->assertSame(
            ['payment_method'],
            array_keys(Livewire::test(ListOrders::class)->instance()->getTable()->getFilters()),
        );

        $order = $this->placeGuestOrder(['email' => 'latest-b@example.com']);
        $other = $this->placeGuestOrder(['email' => 'still-a@example.com']);

        // A LATER attempt with a different method — a real, NEW Payment row,
        // per payment-domain-design.md §1's "a retry is a NEW row" rule.
        $latest = Payment::create($order->id(), 'bank_transfer', $order->total(), PaymentStatus::PENDING);
        $latest->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-latest', null, new DateTimeImmutable('+1 hour'));
        app(PaymentRepository::class)->save($latest);

        $orderRecord = OrderModel::find($order->id());
        $otherRecord = OrderModel::find($other->id());

        // Found by its LATEST method...
        Livewire::test(ListOrders::class)
            ->filterTable('payment_method', 'bank_transfer')
            ->assertCanSeeTableRecords([$orderRecord])
            ->assertCanNotSeeTableRecords([$otherRecord]);

        // ...and NOT by the earlier one, which only the other order still
        // has as its latest attempt.
        Livewire::test(ListOrders::class)
            ->filterTable('payment_method', 'cash_on_delivery')
            ->assertCanSeeTableRecords([$otherRecord])
            ->assertCanNotSeeTableRecords([$orderRecord]);
    }

    /**
     * Clearing the filter restores every row it had hidden — both through
     * the table's own remove action and by setting the value back to blank.
     */
    public function test_clearing_the_payment_method_filter_restores_the_full_list(): void
    {
        $this->actingAsStaffRole('Administrator');

        $cash = $this->placeGuestOrder(['email' => 'cash@example.com']);
        $bank = $this->placeGuestOrder(['email' => 'bank@example.com']);

        // A LATER attempt with a different method — a real, NEW Payment row,
        // per payment-domain-design.md §1's "a retry is a NEW row" rule.
        $latest = Payment::create($bank->id(), 'bank_transfer', $bank->total(), PaymentStatus::PENDING);
        $latest->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-bank', null, new DateTimeImmutable('+1 hour'));
        app(PaymentRepository::class)->save($latest);

        $cashRecord = OrderModel::find($cash->id());
        $bankRecord = OrderModel::find($bank->id());

        $component = Livewire::test(ListOrders::class)
            ->filterTable('payment_method', 'bank_transfer')
            ->assertCanSeeTableRecords([$bankRecord])
            ->assertCanNotSeeTableRecords([$cashRecord]);

        // Clearing through the table's own remove action...
        $component->removeTableFilter('payment_method')->assertCanSeeTableRecords([$cashRecord, $bankRecord]);

        // ...and clearing by setting the value back to blank both restore
        // the full list.
        $component->filterTable('payment_method', 'bank_transfer')->assertCanNotSeeTableRecords([$cashRecord]);
        $component->filterTable('payment_method', null)->assertCanSeeTableRecords([$cashRecord, $bankRecord]);
    }

    /**
     * The page's query count does not grow with the number of rows
     * rendered — WITH or WITHOUT an active filter, and an active filter
     * adds no queries of its own (D1/D3: the list has no per-row
     * correlated subquery any more, and each filter is a nested condition
     * inside the same single query).
     */
    public function test_list_query_count_is_identical_for_five_and_twenty_five_rows_with_and_without_a_filter(): void
    {
        $this->actingAsStaffRole('Administrator');

        for ($i = 0; $i < 25; $i++) {
            $this->placeGuestOrder(['email' => "buyer{$i}@example.com"]);
        }

        // ONE already-mounted component reused for every measurement — a
        // second, separate Livewire::test() call re-mounts from scratch
        // (its own auth/session bookkeeping), which is noise unrelated to
        // the actual table query this test cares about.
        $component = Livewire::test(ListOrders::class);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $component->set('tableRecordsPerPage', 5)->call('$refresh');
        $unfilteredForFive = $count;
        $count = 0;

        $component->set('tableRecordsPerPage', 25)->call('$refresh');
        $unfilteredForTwentyFive = $count;
        $count = 0;

        // Choosing the filter is not part of the measurement — only the
        // refresh that renders the filtered page is.
        $component->filterTable('payment_method', 'cash_on_delivery');
        $count = 0;

        $component->set('tableRecordsPerPage', 5)->call('$refresh');
        $filteredForFive = $count;
        $count = 0;

        $component->set('tableRecordsPerPage', 25)->call('$refresh');
        $filteredForTwentyFive = $count;

        DB::flushQueryLog();

        fwrite(STDERR, "\n[query-count] orders list page — unfiltered 5/25 rows: {$unfilteredForFive}/{$unfilteredForTwentyFive} queries; filtered 5/25 rows: {$filteredForFive}/{$filteredForTwentyFive} queries\n");

        $this->assertSame($unfilteredForFive, $unfilteredForTwentyFive, 'unfiltered: query count must not grow with the number of rows rendered');
        $this->assertSame($filteredForFive, $filteredForTwentyFive, 'filtered: query count must not grow with the number of rows rendered');
        $this->assertSame($unfilteredForFive, $filteredForFive, 'an active filter must not add queries to the page');
    }
}
