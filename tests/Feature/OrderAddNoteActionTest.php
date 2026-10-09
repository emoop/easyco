<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §8.1's "Add internal note" (its own §10 stage
 * 7c-3) — OrderResource::addNoteAction() and App\Services\
 * OrderNoteRecorder. Fixture helpers mirror OrderCancelReturnActionsTest's
 * own established shapes.
 */
class OrderAddNoteActionTest extends TestCase
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

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "order-add-note-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function placeOrder(): Order
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
            Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shippingMethodId: $this->shippingMethodId(), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        );

        return app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-25 09:00:00'))->order();
    }

    /** A pure DB-level status write — the same simplification OrderCancelReturnActionsTest's own visibility sweep uses, to exercise every one of the six statuses without walking the real lifecycle for each. */
    private function forceStatus(string $orderId, string $status): void
    {
        DB::table('orders')->where('id', $orderId)->update(['status' => $status]);
    }

    // --- visibility -----------------------------------------------------------

    public function test_add_note_is_visible_for_every_status_with_order_manage(): void
    {
        $this->actingAsStaffRole('Administrator');

        foreach (['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'] as $status) {
            $order = $this->placeOrder();
            $this->forceStatus($order->id(), $status);

            Livewire::test(ViewOrder::class, ['record' => $order->id()])
                ->assertActionVisible('add_note');
        }
    }

    public function test_add_note_is_hidden_without_order_manage(): void
    {
        $order = $this->placeOrder();

        $this->actingAsViewOnlyStaff();
        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->assertActionHidden('add_note');
    }

    // --- submission -------------------------------------------------------------

    public function test_submitting_a_note_through_the_real_action_writes_exactly_one_note_added_event(): void
    {
        $staff = $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('add_note', data: ['note' => 'Customer called about delivery time.'])
            ->assertNotified(__('orders.actions.add_note_done'))
            ->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));

        $this->assertSame('placed', DB::table('orders')->where('id', $order->id())->value('status'), 'a note moves no status (§8.1)');

        $events = DB::table('order_events')->where('order_id', $order->id())->get();
        $this->assertCount(1, $events);
        $this->assertSame('note_added', $events[0]->type);
        $this->assertNull($events[0]->from_status);
        $this->assertNull($events[0]->to_status);
        $this->assertNull($events[0]->transaction_id);
        $this->assertSame('Customer called about delivery time.', $events[0]->reason);
        $this->assertSame($staff->name, $events[0]->staff_name);
    }

    /**
     * NOT CHAINED ONTO THE TEST ABOVE — A REAL FINDING: OrderAdminReader is
     * bound scoped() and memoizes per instance (§8.3 item 5's own reason
     * for redirecting after a write in the first place); within ONE
     * PHPUnit test method, resolving it again after a Livewire::test()
     * mount — even via a genuinely separate $this->get() call — can still
     * return that SAME memoized (pre-write) instance, because neither
     * Livewire's own testing harness nor a same-method $this->get() call
     * necessarily resets Laravel's scoped container bindings the way a
     * real, separate production HTTP request does. Confirmed empirically:
     * chaining both in one test left OrderAdminReader::forOrder() reporting
     * ZERO events even though the row plainly existed in the database.
     * Sidestepped exactly the way OrderViewPageTest's own History tests
     * already do it for every other event type: write the fact directly
     * (OrderNoteRecorder, the same class the real action calls), with NO
     * prior Livewire mount in this test method to poison the cache, then
     * one isolated $this->get().
     */
    public function test_a_recorded_note_renders_in_the_history_section(): void
    {
        $staff = $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        app(\App\Services\OrderNoteRecorder::class)->record($order->id(), 'Customer called about delivery time.', new DateTimeImmutable('2026-09-25 10:00:00'));

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();
        $historyStart = strpos($html, __('orders.sections.history'));
        $this->assertNotFalse($historyStart, 'History section heading not found in the rendered page');
        $historyHtml = substr($html, $historyStart);

        $this->assertStringContainsString(__('orders.event_type_options.note_added'), $historyHtml);
        $this->assertStringContainsString('Customer called about delivery time.', $historyHtml);
        $this->assertStringContainsString($staff->name, $historyHtml);
    }

    // --- refusal ------------------------------------------------------------

    public function test_submitting_a_blank_note_is_refused_client_side_and_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->callAction('add_note', data: ['note' => '']);

        // The action never actually ran — Filament's own required() rule
        // refused the submission before fillForm()/callMountedAction() could
        // reach this Resource's own ->action() closure at all.
        $this->assertNotEmpty($component->instance()->mountedActions, 'still mounted — the required() validation error left it open, exactly like every other field-level rule on this page');
        $this->assertSame(0, DB::table('order_events')->where('order_id', $order->id())->count());
    }

    /**
     * A FIRST DRAFT SUSPECTED THIS WOULD BE A REACHABLE EXCEPTION BEYOND
     * \Throwable (D1 asked to report one if found) — a whitespace-only
     * note satisfies Laravel's `required` rule (it is not empty/null), so
     * the theory was that it would pass the field's own client-side guard
     * and still reach OrderEventRecorder::record()'s own `trim($reason)
     * === ''` guard for NOTE_ADDED, surfacing through runOrderAction()'s
     * existing generic InvalidArgumentException branch. TESTED DIRECTLY
     * AGAINST THE REAL ACTION AND FOUND WRONG: Filament's own required()
     * refuses a whitespace-only Textarea submission too (confirmed via the
     * action's own mountedActions state — it never reaches this Resource's
     * ->action() closure at all, the same shape as the blank case above).
     * So the domain's own blank-reason guard is proven here directly
     * against OrderNoteRecorder instead — real defense-in-depth, genuinely
     * unreachable through the admin action itself.
     */
    public function test_a_whitespace_only_note_is_refused_by_the_domain_guard_directly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-blank reason');

        app(\App\Services\OrderNoteRecorder::class)->record('does-not-matter', '   ', new DateTimeImmutable());
    }
}
