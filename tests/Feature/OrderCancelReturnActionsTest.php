<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\ReturnExceedsRemainingQuantityException;
use App\Services\OrderStatusChanger;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
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
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §8.1/§8.2/§8.4 (its own §10 stage 7c-2) —
 * OrderResource::cancelAction()/recordReturnAction() and the shared line
 * form buildLineFormSchema() they rely on.
 * Fixture helpers mirror OrderViewActionsTest's own established shapes.
 */
class OrderCancelReturnActionsTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

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

    /** ORDER_VIEW only — no seeded role has this exact shape (see OrderViewActionsTest's own identical helper). */
    private function actingAsViewOnlyStaff(): StaffPanelUser
    {
        $role = Role::create('Order Viewer', [Permission::ORDER_VIEW]);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('order.viewer@example.com', app(PasswordHasher::class)->hash('password123'), 'Order Viewer', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    private function variationId(string $name = 'Product'): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("{$name} {$suffix}", "SKU-{$suffix}", "order-cancel-return-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function pricedPurchasableVariation(string $name, string $decimalAmount): string
    {
        $variationId = $this->variationId($name);

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

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 20));

        return $variationId;
    }

    /**
     * @param list<array{name: string, price: string, quantity: int}> $lines
     */
    private function placeOrderWithLines(array $lines, array $overrides = []): Order
    {
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));

        foreach ($lines as $line) {
            $variationId = $this->pricedPurchasableVariation($line['name'], $line['price']);
            app(CartLineAdder::class)->addLine($cart, $variationId, $line['quantity'], null, null);
        }

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: $overrides['paymentMethod'] ?? 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shippingMethodId: $this->shippingMethodId(), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        );

        return app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-25 09:00:00'))->order();
    }

    private function placeOrder(array $overrides = []): Order
    {
        return $this->placeOrderWithLines([['name' => 'Solo Product', 'price' => $overrides['price'] ?? '10.00', 'quantity' => 1]], $overrides);
    }

    private function saleLineIds(string $orderId): array
    {
        $transactionId = DB::table('orders')->where('id', $orderId)->value('transaction_id');

        return DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $transactionId)
            ->where('type', 'sale')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function variationIdForSaleLine(string $saleLineId): string
    {
        return (string) DB::table('operational_sales_sale_lines')->where('id', $saleLineId)->value('priceable_id');
    }

    private function stock(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    private function transitionOrderTo(string $orderId, string $target): void
    {
        $changer = app(OrderStatusChanger::class);
        $now = new DateTimeImmutable('2026-09-25 10:00:00');
        $rank = ['placed' => 0, 'confirmed' => 1, 'shipped' => 2, 'delivered' => 3];

        $steps = [
            'confirmed' => fn () => $changer->confirm($orderId, $now),
            'shipped' => fn () => $changer->ship($orderId, $now),
            'delivered' => fn () => $changer->deliver($orderId, $now),
        ];

        foreach ($steps as $status => $step) {
            $current = DB::table('orders')->where('id', $orderId)->value('status');

            if ($rank[$current] >= $rank[$status]) {
                continue;
            }

            if ($rank[$target] < $rank[$status]) {
                break;
            }

            $step();
        }
    }

    /** A pure DB-level status write — used ONLY for T1's visibility sweep, which exercises OrderResource's own ->visible() closures in isolation, not the domain's own transition legality (OrderStatusChangerTest's matrix tests already cover that exhaustively). */
    private function forceStatus(string $orderId, string $status): void
    {
        DB::table('orders')->where('id', $orderId)->update(['status' => $status]);
    }

    private function applyPaymentState(string $orderId, string $state): void
    {
        match ($state) {
            'none' => DB::table('payments')->where('order_id', $orderId)->delete(),
            'settled_cod' => DB::table('payments')->where('order_id', $orderId)->update(['method' => 'cash_on_delivery', 'confirmed_at' => '2026-09-25 09:30:00']),
            'settled_bank' => DB::table('payments')->where('order_id', $orderId)->update(['method' => 'bank_transfer', 'confirmed_at' => '2026-09-25 09:30:00']),
            'unsettled' => null, // already unsettled right after placement
        };
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    // --- T1: the visibility matrix ------------------------------------------

    /**
     * Six statuses × four payment states, for a staff member WITH ORDER_MANAGE
     * (Manager — ORDER_MANAGE + REFUND_CASH, but deliberately NOT REFUND_BANK,
     * per StaffSystemRolesSeeder — chosen over Administrator specifically
     * BECAUSE that asymmetry lets one sweep prove the REFUND_CASH-vs-
     * REFUND_BANK distinction too, not just "any settled payment blocks it")
     * and WITHOUT it (a custom ORDER_VIEW-only role) — D2/D3's own rules,
     * computed independently here and compared against the real component.
     */
    public function test_cancel_and_record_return_visibility_matrix(): void
    {
        $statuses = ['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'];
        $paymentStates = ['none', 'settled_cod', 'settled_bank', 'unsettled'];

        // Refunds R3 part 2: the dialog is offered for EVERY payment state to anyone with ORDER_MANAGE. What the
        // money permission decides is which payout CHANNELS the dialog offers (a Manager holds REFUND_CASH, so a
        // settled bank order now opens with the cash channel only), and staff with no channel at all are shown
        // a notice and no submit button (OrderRefundDialogTest). It used to hide the button for a Manager on a
        // settled bank-transfer order, which told them nothing.
        $moneyClauseForManager = static fn (string $state): bool => true;

        // Built ONCE — staffWithRole() creates a real, uniquely-emailed
        // Staff row, so calling it once per iteration inside the sweep
        // below would collide on the second pass with "already
        // registered". Both models are stable stand-ins for two staff
        // members re-authenticating per iteration.
        $manager = $this->staffWithRole('Manager');
        $viewOnlyRole = Role::create('Order Viewer', [Permission::ORDER_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);
        $viewOnlyStaff = Staff::create('order.viewer@example.com', app(PasswordHasher::class)->hash('password123'), 'Order Viewer', $viewOnlyRole);
        app(StaffRepository::class)->save($viewOnlyStaff);
        $viewOnly = StaffPanelUser::find($viewOnlyStaff->id());

        foreach ($statuses as $status) {
            foreach ($paymentStates as $paymentState) {
                $order = $this->placeOrder();
                $this->applyPaymentState($order->id(), $paymentState);
                $this->forceStatus($order->id(), $status);

                $expectedCancel = in_array($status, ['placed', 'confirmed', 'shipped'], true)
                    && $moneyClauseForManager($paymentState);
                $expectedReturn = in_array($status, ['shipped', 'delivered'], true)
                    && $moneyClauseForManager($paymentState);

                $this->actingAs($manager, 'staff');
                session()->forget('password_hash_staff');
                $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
                $expectedCancel
                    ? $component->assertActionVisible('cancel')
                    : $component->assertActionHidden('cancel');
                $expectedReturn
                    ? $component->assertActionVisible('record_return')
                    : $component->assertActionHidden('record_return');

                // Without ORDER_MANAGE at all: hidden regardless of status/payment.
                $this->actingAs($viewOnly, 'staff');
                session()->forget('password_hash_staff');
                $viewOnlyComponent = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
                $viewOnlyComponent->assertActionHidden('cancel');
                $viewOnlyComponent->assertActionHidden('record_return');
            }
        }
    }

    public function test_administrator_sees_cancel_even_with_a_settled_bank_transfer_payment(): void
    {
        $order = $this->placeOrder(['paymentMethod' => 'bank_transfer']);
        $this->applyPaymentState($order->id(), 'settled_bank');

        $this->actingAsStaffRole('Administrator');
        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionVisible('cancel');
    }

    // --- T2: the three form variants -----------------------------------------

    /**
     * A REAL FINDING FROM WRITING THIS SUITE: a mounted modal's own content
     * is NOT included in Livewire::test()'s own html()/assertSee() output
     * in this harness — confirmed empirically (a first draft's assertSee()
     * calls "passed" only by coincidence, matching the SAME product name
     * and field labels that ALSO legitimately appear in the page's own,
     * always-rendered Items table; once checked against markers that could
     * ONLY come from inside the modal itself — Fieldset's own literal
     * `fi-fieldset` class, Toggle's own `role="switch"` — NEITHER appeared,
     * even though the mounted action's own $data plainly proves the field
     * exists: `mountedActions[0]['data']` correctly showed
     * `{"restock":{"<id>":true}}` for a shipped cancel). So the three form
     * variants are verified against that DATA shape instead — the same
     * state Filament itself used to seed the form, and the same state
     * submitAction() below reads back — rather than rendered text.
     */
    private function mountedActionData(string $actionName, string $orderId): array
    {
        $component = Livewire::test(ViewOrder::class, ['record' => $orderId]);
        $component->mountAction($actionName);
        $mounted = $component->instance()->mountedActions;

        $this->assertNotEmpty($mounted, "{$actionName} did not mount — check its own ->visible() first.");

        return $mounted[0]['data'];
    }

    public function test_cancel_from_placed_seeds_no_restock_key_at_all(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $data = $this->mountedActionData('cancel', $order->id());

        $this->assertArrayHasKey('reason', $data);
        $this->assertArrayNotHasKey('restock', $data, 'from placed/confirmed the toggle is not offered at all (§8.4)');
        $this->assertArrayNotHasKey('quantity', $data, 'cancel() never collects an editable quantity');
    }

    public function test_cancel_from_shipped_seeds_a_restock_entry_for_the_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'shipped');
        [$lineId] = $this->saleLineIds($order->id());

        $data = $this->mountedActionData('cancel', $order->id());

        $this->assertArrayHasKey('restock', $data);
        $this->assertArrayHasKey($lineId, $data['restock']);
        $this->assertTrue($data['restock'][$lineId], 'default ON (R3)');
        $this->assertArrayNotHasKey('quantity', $data, 'the quantity display is read-only, never collected');
    }

    public function test_record_return_seeds_a_blank_quantity_and_a_restock_entry_for_the_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'shipped');
        [$lineId] = $this->saleLineIds($order->id());

        $data = $this->mountedActionData('record_return', $order->id());

        $this->assertArrayHasKey('quantity', $data);
        $this->assertArrayHasKey($lineId, $data['quantity']);
        $this->assertNull($data['quantity'][$lineId], 'blank by default — sparse, §8.4/§5.2');
        $this->assertArrayHasKey('restock', $data);
        $this->assertTrue($data['restock'][$lineId]);
    }

    /**
     * A fully-returned line is not among the lines buildLineFormSchema()
     * builds a block for — "there is nothing left to ask about it" (D4).
     * NOT PROVABLE VIA assertDontSee($productName) ON THE WHOLE PAGE: the
     * Items section (a permanent, separate part of the same page) shows
     * every SALE line regardless of remainingReturnable, so the fully-
     * returned line's own name is legitimately present on the page either
     * way — a whole-page text search cannot distinguish "in the Items
     * table" from "in the dialog". Proven instead at the level that
     * actually matters: even if a caller submitted a quantity for the
     * fully-returned line's own id (exactly what a stale/crafted request
     * would do, since the form never offers it), recordReturnAction()'s
     * own submission loop skips it via the SAME remainingReturnable > 0
     * check buildLineFormSchema() uses to decide what to render — so it
     * can never reach OrderStatusChanger at all, safe by construction on
     * both sides of the same condition.
     */
    public function test_a_fully_returned_lines_id_is_ignored_even_if_submitted(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([
            ['name' => 'Fully Returned', 'price' => '10.00', 'quantity' => 1],
            ['name' => 'Still Returnable', 'price' => '5.00', 'quantity' => 2],
        ]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineA, $lineB] = $this->saleLineIds($order->id());

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $lineA, 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-09-25 11:00:00'));

        // The FORM itself never offers line A — proven the same way T2 is.
        $data = $this->mountedActionData('record_return', $order->id());
        $this->assertArrayNotHasKey($lineA, $data['quantity'] ?? [], 'a fully-returned line gets no quantity field at all');
        $this->assertArrayHasKey($lineB, $data['quantity'] ?? []);

        $variationA = $this->variationIdForSaleLine($lineA);
        $stockABefore = $this->stock($variationA);

        // A crafted submission naming BOTH lines, including the already
        // fully-returned one — the form itself never offers it, but the
        // server must still be safe against it.
        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: [
                'quantity' => [$lineA => 1, $lineB => 1],
                'restock' => [$lineA => true, $lineB => true],
            ]);

        // Only line B's unit was ever passed to the service — line A
        // contributes no second REFUND line and no second restock.
        $this->assertSame(2, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), '1 from the fixture + 1 from line B only');
        $this->assertSame($stockABefore, $this->stock($variationA), 'line A must not restock a second time');
    }

    // --- T3: submission — real service calls, real resulting state -------------

    public function test_cancel_from_placed_restocks_everything_and_reaches_cancelled(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([
            ['name' => 'Line A', 'price' => '10.00', 'quantity' => 2],
            ['name' => 'Line B', 'price' => '5.00', 'quantity' => 3],
        ]);

        [$lineA, $lineB] = $this->saleLineIds($order->id());
        $variationA = $this->variationIdForSaleLine($lineA);
        $variationB = $this->variationIdForSaleLine($lineB);
        $stockABefore = $this->stock($variationA);
        $stockBBefore = $this->stock($variationB);

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('cancel', data: ['reason' => 'changed mind'])
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame($stockABefore + 2, $this->stock($variationA));
        $this->assertSame($stockBBefore + 3, $this->stock($variationB));
    }

    public function test_cancel_from_shipped_respects_the_restock_toggle_per_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([
            ['name' => 'Line A', 'price' => '10.00', 'quantity' => 2],
            ['name' => 'Line B', 'price' => '5.00', 'quantity' => 3],
        ]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineA, $lineB] = $this->saleLineIds($order->id());
        $variationA = $this->variationIdForSaleLine($lineA);
        $variationB = $this->variationIdForSaleLine($lineB);
        $stockABefore = $this->stock($variationA);
        $stockBBefore = $this->stock($variationB);

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('cancel', data: [
                'restock' => [$lineA => false, $lineB => true],
            ])
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame($stockABefore, $this->stock($variationA), 'restock off: no stock added');
        $this->assertSame($stockBBefore + 3, $this->stock($variationB), 'restock on (default): stock added');
    }

    public function test_a_partial_record_return_leaves_the_order_shipped_and_writes_the_refund_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Bulk', 'price' => '10.00', 'quantity' => 5]]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineId] = $this->saleLineIds($order->id());
        $variationId = $this->variationIdForSaleLine($lineId);
        $stockBefore = $this->stock($variationId);

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: [
                'quantity' => [$lineId => 2],
                'restock' => [$lineId => true],
            ])
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame($stockBefore + 2, $this->stock($variationId));
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    public function test_a_record_return_emptying_every_line_reaches_refunded_from_delivered(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder(['price' => '10.00']);
        $this->transitionOrderTo($order->id(), 'delivered');

        [$lineId] = $this->saleLineIds($order->id());

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: [
                'quantity' => [$lineId => 1],
                'restock' => [$lineId => true],
            ])
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('refunded', DB::table('orders')->where('id', $order->id())->value('status'));
    }

    public function test_a_cancel_from_shipped_emptying_every_line_reaches_cancelled(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder(['price' => '10.00']);
        $this->transitionOrderTo($order->id(), 'shipped');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('cancel')
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id())->value('status'));
    }

    // --- T4: refusals -------------------------------------------------------

    /**
     * Exercises ReturnGoodsRecorder's own refusal directly through
     * OrderStatusChanger::recordReturn() (the exact real path the admin
     * action itself calls) — the accessors this test pins are what
     * runOrderAction()'s own new catch branch (D7) reads to build its
     * translated message, proven end to end in the next test.
     */
    public function test_return_exceeds_remaining_quantity_carries_the_real_accessors_and_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Small Batch', 'price' => '10.00', 'quantity' => 2]]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineId] = $this->saleLineIds($order->id());

        try {
            app(OrderStatusChanger::class)->recordReturn($order->id(), [
                ['originatingSaleLineId' => $lineId, 'quantityReturned' => 5, 'restock' => true],
            ], new DateTimeImmutable('2026-09-25 11:00:00'));
            $this->fail('expected ReturnExceedsRemainingQuantityException');
        } catch (ReturnExceedsRemainingQuantityException $e) {
            $this->assertSame($lineId, $e->originatingSaleLineId());
            $this->assertSame(5, $e->requestedQuantity());
            $this->assertSame(2, $e->remainingQuantity());
        }

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
    }

    /** The same exception, surfaced through the REAL admin action end to end, with its own translated notification body. */
    public function test_return_exceeds_remaining_quantity_surfaces_through_the_real_action(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Small Batch', 'price' => '10.00', 'quantity' => 2]]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineId] = $this->saleLineIds($order->id());

        // A REAL FINDING FROM WRITING THIS TEST: TextInput::maxValue() is
        // genuinely enforced server-side (confirmed empirically — a first
        // draft submitting straight past it via ->callAction() left the
        // action MOUNTED with a validation error, never reaching this
        // page's own ->action() closure at all), so a value already over
        // the render-time max cannot reach ReturnGoodsRecorder this way —
        // the only realistic path is the GENUINE RACE §8.2 itself
        // describes: the form is mounted while 2 remain (max=2), another
        // operator's own recordReturn() call reduces the REAL remaining to
        // 1 in between, and THIS submission (quantity=2, still valid
        // against the STALE max its own already-mounted schema was built
        // with) is what reaches the service and is refused there.
        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->mountAction('record_return');

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $lineId, 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-09-25 10:30:00'));

        $component->fillForm(['quantity' => [$lineId => 2], 'restock' => [$lineId => true]]);
        $component->callMountedAction();

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'only the OTHER operator\'s own return exists — this submission wrote nothing');
        $lineProductName = DB::table('operational_sales_sale_lines')->where('id', $lineId)->value('product_name');
        $this->assertSame(
            __('orders.actions.return_exceeds_remaining_body', ['line' => $lineProductName, 'requested' => 2, 'remaining' => 1]),
            $this->lastNotificationBody(),
        );
    }

    /** Refunds R1b: a broken refund cap reaches the admin as a translated refusal notice (never a 500) and writes nothing. */
    public function test_a_refund_cap_refusal_surfaces_as_a_translated_notice_through_the_real_action(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Capped', 'price' => '10.00', 'quantity' => 5]]);
        $this->transitionOrderTo($order->id(), 'shipped');
        $this->applyPaymentState($order->id(), 'settled_cod');
        // The payment that settled holds only 15.00 of the order's 50.00: returning 2 units (20.00) breaks the total cap.
        DB::table('payments')->where('order_id', $order->id())->update(['amount_minor' => 1500]);

        [$lineId] = $this->saleLineIds($order->id());
        $stockBefore = $this->stock($this->variationIdForSaleLine($lineId));

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: ['quantity' => [$lineId => 2], 'restock' => [$lineId => true]]);

        $this->assertStringContainsString('at most 15.00 EUR can still be refunded', (string) $this->lastNotificationBody());
        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count(), 'the goods half rolled back');
        $this->assertSame($stockBefore, $this->stock($this->variationIdForSaleLine($lineId)));
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    /** Refunds R1b: submitting the dialog twice with its one key is one return, through the real action. */
    public function test_the_dialogs_hidden_key_makes_a_double_submit_one_return(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Twice', 'price' => '10.00', 'quantity' => 5]]);
        $this->transitionOrderTo($order->id(), 'shipped');
        $this->applyPaymentState($order->id(), 'settled_cod');

        [$lineId] = $this->saleLineIds($order->id());
        $stockBefore = $this->stock($this->variationIdForSaleLine($lineId));
        $data = ['quantity' => [$lineId => 2], 'restock' => [$lineId => true], 'operation_key' => 'one-opening-of-the-dialog'];

        Livewire::test(ViewOrder::class, ['record' => $order->id()])->callAction('record_return', data: $data);
        Livewire::test(ViewOrder::class, ['record' => $order->id()])->callAction('record_return', data: $data);

        $this->assertSame($stockBefore + 2, $this->stock($this->variationIdForSaleLine($lineId)), 'the units came back once');
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(1, DB::table('payment_refunds')->count());
    }

    public function test_the_dialog_form_carries_one_hidden_operation_key(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Keyed', 'price' => '10.00', 'quantity' => 2]]);
        $this->transitionOrderTo($order->id(), 'shipped');

        $data = $this->mountedActionData('record_return', (string) $order->id());

        $this->assertArrayHasKey('operation_key', $data);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $data['operation_key']);
    }

    /** Mirrors stage 7b's own race test: Filament's own mountAction() re-check absorbs a status-based race before it ever reaches the service. */
    public function test_a_wrong_status_race_declines_to_mount_rather_than_reaching_the_service(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->assertActionVisible('cancel');

        app(OrderStatusChanger::class)->confirm($order->id(), new DateTimeImmutable('2026-09-25 09:30:00'));
        app(OrderStatusChanger::class)->ship($order->id(), new DateTimeImmutable('2026-09-25 09:31:00'));
        app(OrderStatusChanger::class)->deliver($order->id(), new DateTimeImmutable('2026-09-25 09:32:00'));

        // delivered is NOT one of cancel()'s legal starting statuses.
        $component->callAction('cancel');

        $this->assertSame([], $component->instance()->mountedActions);
        $component->assertNotNotified();
        $this->assertSame('delivered', DB::table('orders')->where('id', $order->id())->value('status'));
    }

    // --- T5: the success notification's exact wording -------------------------

    /** D6's own rule, proven directly: a PARTIAL return names what was asked for, and never claims a status the order did not reach. */
    public function test_the_success_notification_for_a_partial_return_names_the_quantity_not_a_status(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([['name' => 'Bulk', 'price' => '10.00', 'quantity' => 5]]);
        $this->transitionOrderTo($order->id(), 'shipped');

        [$lineId] = $this->saleLineIds($order->id());

        // The success message is the notification's TITLE (runOrderAction()'s
        // own shape — a fixed body-less success toast, matching every other
        // action on this page), never its body — assertNotified(title)
        // matches by title.
        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: [
                'quantity' => [$lineId => 2],
                'restock' => [$lineId => true],
            ])
            ->assertNotified(__('orders.actions.record_return_done', ['id' => $order->id(), 'count' => 2]));

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'), 'sanity: still not a terminal status — D6\'s own rule, the notification named the quantity, not a status');
    }

    public function test_record_return_refuses_a_submission_with_every_line_left_at_zero(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder(['price' => '10.00']);
        $this->transitionOrderTo($order->id(), 'shipped');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('record_return', data: ['restock' => []]);

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame(__('orders.actions.nothing_to_return'), $this->lastNotificationBody());
    }

    // --- T6: query count ------------------------------------------------------

    public function test_query_count_for_rendering_the_cancel_and_record_return_dialogs(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrderWithLines([
            ['name' => 'Line A', 'price' => '10.00', 'quantity' => 2],
            ['name' => 'Line B', 'price' => '5.00', 'quantity' => 3],
        ]);
        $this->transitionOrderTo($order->id(), 'shipped');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $pageLoadCount = $count;
        $count = 0;

        $component->mountAction('cancel');
        $cancelMountCount = $count;
        $count = 0;

        $component->unmountAction();
        $component->mountAction('record_return');
        $recordReturnMountCount = $count;

        fwrite(STDERR, "\n[query-count] order view page (2-line, shipped): page load {$pageLoadCount} queries, mount cancel +{$cancelMountCount}, mount record_return +{$recordReturnMountCount}\n");

        // REFUNDS R3 PART 2 CHANGED THESE NUMBERS ON PURPOSE (the pin was 10 for both; measured then: cancel +1,
        // record_return +3). The dialogs now read the money of the order as they open — the payment kind, the cap
        // rooms from RefundCapGuard, the payout channels the staff member may use — and a RETURN also reads the
        // return facts (one query) and, on an unpaid order, the shipping-reduction room (the service's own
        // derivation, several reads). None of it grows with the number of lines (the line rooms are two batched
        // reads), and a CANCEL skips the shipping-reduction room because a cancel voids everything.
        // Measured now: cancel +3, record_return +15.
        $this->assertLessThanOrEqual(10, $cancelMountCount);
        $this->assertLessThanOrEqual(16, $recordReturnMountCount);
    }
}
