<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
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
 * order-lifecycle-design.md §8 (its own §10 stage 7b) —
 * ViewOrder::getHeaderActions()'s first four write actions:
 * Confirm/Ship/Deliver/"Mark as received" (OrderResource::confirmAction()/
 * shipAction()/deliverAction()/markAsReceivedAction()). Fixture helpers
 * mirror OrderViewPageTest/OrderResourceTest's own established shapes.
 */
class OrderViewActionsTest extends TestCase
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

    /** ORDER_VIEW ONLY, deliberately no ORDER_MANAGE — no seeded system role has this exact shape (Administrator/Manager both carry both permissions), so a custom Role is built directly for T1's "without ORDER_MANAGE" half. */
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

        $product = Product::createSimple("{$name} {$suffix}", "SKU-{$suffix}", "order-actions-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00'): string
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

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    private function placeOrder(array $overrides = []): Order
    {
        $variationId = $this->pricedPurchasableVariation($overrides['price'] ?? '10.00');
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);

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

    private function latestPayment(string $orderId): Payment
    {
        $payments = app(PaymentRepository::class)->findByOrderId($orderId);

        return $payments[array_key_last($payments)];
    }

    /**
     * Walks an order from ITS OWN CURRENT status forward to $target,
     * calling only the steps still needed — safe to call more than once
     * on the same order (e.g. 'shipped' then later 'delivered'), unlike a
     * naive confirm-then-ship-then-deliver sequence that assumes every
     * call starts fresh from 'placed'.
     */
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

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    // --- T1: visibility -----------------------------------------------------

    public function test_confirm_is_visible_only_at_placed(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionVisible('confirm');

        $this->transitionOrderTo($order->id(), 'confirmed');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionHidden('confirm');
    }

    public function test_ship_is_visible_at_confirmed_regardless_of_settlement(): void
    {
        $this->actingAsStaffRole('Administrator');

        // D3: an UNSETTLED bank_transfer order — R9 would refuse the actual
        // transition, but the button itself must still be offered. NOT
        // actually shipped here (that would just re-throw R9's own
        // refusal, which is a separate test below) — this test is only
        // about the BUTTON's own visibility.
        $unsettled = $this->placeOrder(['paymentMethod' => 'bank_transfer']);
        $this->transitionOrderTo($unsettled->id(), 'confirmed');

        Livewire::test(ViewOrder::class, ['record' => $unsettled->id()])->assertActionVisible('ship');

        // A SEPARATE, cash_on_delivery order for the "hidden once shipped" half.
        $settled = $this->placeOrder();
        $this->transitionOrderTo($settled->id(), 'shipped');

        Livewire::test(ViewOrder::class, ['record' => $settled->id()])->assertActionHidden('ship');
    }

    public function test_deliver_is_visible_only_at_shipped(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionHidden('deliver');

        $this->transitionOrderTo($order->id(), 'shipped');
        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionVisible('deliver');

        $this->transitionOrderTo($order->id(), 'delivered');
        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertActionHidden('deliver');
    }

    /**
     * The eligible case, plus each of §4.5's other four states — already-
     * settled, unanswered-pending, failed, no payment at all — asserting
     * the button is absent for every one of them. A voided payment is a
     * fifth state Payment::isConfirmable()'s own truth table also covers
     * (D1), checked here too since it costs nothing extra.
     */
    public function test_mark_as_received_is_visible_only_when_the_latest_payment_is_confirmable(): void
    {
        $this->actingAsStaffRole('Administrator');

        // Eligible: a freshly-placed cash_on_delivery order's own payment
        // (PENDING, attempted_at set at placement, unconfirmed, unvoided).
        $eligible = $this->placeOrder();
        Livewire::test(ViewOrder::class, ['record' => $eligible->id()])->assertActionVisible('mark_as_received');

        // Already settled.
        $settled = $this->placeOrder();
        $payment = $this->latestPayment($settled->id());
        $payment->confirm(new DateTimeImmutable('2026-09-25 09:30:00'));
        app(PaymentRepository::class)->save($payment);
        Livewire::test(ViewOrder::class, ['record' => $settled->id()])->assertActionHidden('mark_as_received');

        // Unanswered pending (attempted_at NULL) — checkout always sets
        // it, so this is a direct row edit, the only way to reach it.
        $unanswered = $this->placeOrder();
        DB::table('payments')->where('order_id', $unanswered->id())->update(['attempted_at' => null]);
        Livewire::test(ViewOrder::class, ['record' => $unanswered->id()])->assertActionHidden('mark_as_received');

        // Failed.
        $failed = $this->placeOrder();
        DB::table('payments')->where('order_id', $failed->id())->update(['status' => 'failed', 'failure_reason' => 'declined']);
        Livewire::test(ViewOrder::class, ['record' => $failed->id()])->assertActionHidden('mark_as_received');

        // Voided.
        $voided = $this->placeOrder();
        DB::table('payments')->where('order_id', $voided->id())->update(['voided_at' => '2026-09-25 09:30:00']);
        Livewire::test(ViewOrder::class, ['record' => $voided->id()])->assertActionHidden('mark_as_received');

        // No payment at all.
        $noPayment = $this->placeOrder();
        DB::table('payments')->where('order_id', $noPayment->id())->delete();
        Livewire::test(ViewOrder::class, ['record' => $noPayment->id()])->assertActionHidden('mark_as_received');
    }

    public function test_no_action_is_visible_without_order_manage(): void
    {
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'shipped');

        $this->actingAsViewOnlyStaff();

        // ORDER_VIEW alone opens the page at all...
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        // ...but none of the four actions, regardless of the order's own status/eligibility.
        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->assertActionHidden('confirm');
        $component->assertActionHidden('ship');
        $component->assertActionHidden('deliver');
        $component->assertActionHidden('mark_as_received');
    }

    // --- T2: happy paths ------------------------------------------------------

    public function test_confirm_happy_path_calls_the_service_and_redirects(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('confirm', data: ['note' => 'accepted by phone'])
            ->assertNotified(__('orders.actions.confirm_done'))
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('confirmed', DB::table('orders')->where('id', $order->id())->value('status'));

        $event = DB::table('order_events')->where('order_id', $order->id())->orderBy('id')->first();
        $this->assertSame('status_changed', $event->type);
        $this->assertSame('placed', $event->from_status);
        $this->assertSame('confirmed', $event->to_status);
        $this->assertSame('accepted by phone', $event->reason);
    }

    public function test_ship_happy_path_calls_the_service(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'confirmed');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('ship')
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'));
    }

    public function test_deliver_happy_path_calls_the_service(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'shipped');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('deliver')
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('delivered', DB::table('orders')->where('id', $order->id())->value('status'));
    }

    public function test_mark_as_received_happy_path_calls_the_payment_confirmer(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $payment = $this->latestPayment($order->id());

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('mark_as_received')
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertNotNull(DB::table('payments')->where('id', $payment->id())->value('confirmed_at'));
        $this->assertSame('placed', DB::table('orders')->where('id', $order->id())->value('status'), 'confirming money never moves the order status (§3 item 1)');

        $event = DB::table('order_events')->where('order_id', $order->id())->first();
        $this->assertSame('payment_confirmed', $event->type);
    }

    // --- T3: refusal paths ------------------------------------------------------

    public function test_ship_refusal_shows_the_r9_translated_reason_and_changes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder(['paymentMethod' => 'bank_transfer']);
        $this->transitionOrderTo($order->id(), 'confirmed');

        Livewire::test(ViewOrder::class, ['record' => $order->id()])->callAction('ship');

        $this->assertSame('confirmed', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(
            __('orders.refusal_reasons.bank_transfer_not_settled'),
            $this->lastNotificationBody(),
        );
    }

    /**
     * Two staff members: this one loaded the page while the order was
     * still `placed`; the other confirmed it in the meantime via a direct
     * service call. A REAL FINDING FROM WRITING THIS TEST, WORTH RECORDING:
     * Filament's own mountAction() re-evaluates ->visible() against the
     * CURRENT record at click time, not just at render time — a status-
     * based guard like confirm()'s own therefore never reaches
     * runOrderAction() at all once the race has happened; the click
     * silently declines to mount the action (confirmed directly below:
     * mountedActions stays empty, nothing is written, no notification
     * fires) rather than reaching this Resource's own try/catch. That is
     * a STRONGER guarantee than "shows a notification" — refused cleanly,
     * not with a 500, exactly what T3 asks for, via a layer this Resource
     * gets for free rather than one it had to build. The one case where a
     * visible-but-refused click DOES reach runOrderAction()'s own
     * try/catch is R9's — Ship's visibility deliberately does NOT check
     * isSettled() (D3) — already covered above.
     */
    public function test_a_race_condition_leaves_the_button_declining_to_mount_rather_than_reaching_the_service(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->assertActionVisible('confirm');

        app(OrderStatusChanger::class)->confirm($order->id(), new DateTimeImmutable('2026-09-25 09:30:00'));

        $component->callAction('confirm');

        $this->assertSame([], $component->instance()->mountedActions, 'the action declined to mount once the record no longer matched its own ->visible() condition');
        $component->assertNotNotified();

        $this->assertSame('confirmed', DB::table('orders')->where('id', $order->id())->value('status'), 'the race\'s own confirmation stands; the refused click changed nothing further');
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id())->count(), 'only the race\'s own event exists — the refused click wrote nothing');
    }

    // --- T5: query count ----------------------------------------------------

    public function test_query_count_for_the_view_page_with_all_four_actions_present(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->transitionOrderTo($order->id(), 'shipped');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        fwrite(STDERR, "\n[query-count] order view page with the four actions' own visibility checks (shipped, mark_as_received eligible): {$count} queries\n");

        // Order-editing stage 4a: 23 -> 25 (+2, forOrder()'s current-lines
        // resolution: the EDITED-event read and the batched edited-away sum). The
        // ceiling was 25 with two of headroom; it is now met exactly, so the next
        // added read must update this number consciously.
        // Refunds R2b: 25 -> 26 (+1): the refunds section asks whether the order has any
        // refund (one read, shared by the section's visibility and its content; an order
        // with none stops there). Met exactly again.
        $this->assertLessThanOrEqual(26, $count);
    }
}
