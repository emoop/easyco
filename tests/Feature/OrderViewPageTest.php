<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
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
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
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
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the real, production Order View page (OrderResource's
 * infolist) — admin-panel-design.md §14, Commit 4. Fixture helpers
 * mirror OrderAdminReaderTest/OrderResourceTest's own established
 * shapes.
 */
class OrderViewPageTest extends TestCase
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

    private function variationId(string $name = 'Product'): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("{$name} {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function pricedPurchasableVariation(string $decimalAmount = '10.00', string $name = 'Product'): string
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

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    private function placeOrder(array $overrides = []): Order
    {
        $variationId = $this->pricedPurchasableVariation($overrides['price'] ?? '10.00', $overrides['productName'] ?? 'Product');
        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $variationId, $overrides['quantity'] ?? 1, null, null);

        if (isset($overrides['promotionCode'])) {
            $cart->applyPromotionCode($overrides['promotionCode']);
            app(CartRepository::class)->save($cart);
        }

        $get = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: $get('email', 'guest@example.com'),
            recipientName: $get('recipientName', 'Guest Buyer'),
            phone: $get('phone', '+359888000000'),
            paymentMethod: $get('paymentMethod', 'cash_on_delivery'),
            deliveryType: $get('deliveryType', AddressDeliveryType::STREET_ADDRESS),
            country: $get('country', 'BG'),
            city: $get('city', 'Sofia'),
            postalCode: $get('postalCode', null),
            addressLine1: $get('addressLine1', 'Vitosha Blvd 1'),
            carrierCode: $get('carrierCode', null),
            pickupPointReference: $get('pickupPointReference', null),
            settlement: $get('settlement', null),
        );

        return app(CheckoutOrchestrator::class)->place($input, $overrides['placedAt'] ?? new DateTimeImmutable('2026-09-20 10:00:00'))->order();
    }

    public function test_administrator_and_manager_can_view_an_order_product_entry_cannot(): void
    {
        $order = $this->placeOrder();

        $this->actingAsStaffRole('Administrator');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        $this->actingAsStaffRole('Manager');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk();

        $this->actingAsStaffRole('Product Entry');
        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertForbidden();
    }

    public function test_a_full_order_renders_promotion_payment_and_street_address(): void
    {
        app(PromotionRepository::class)->save(Promotion::create(code: 'save20', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000));
        $this->actingAsStaffRole('Administrator');

        $order = $this->placeOrder([
            'price' => '20.00',
            'quantity' => 2,
            'promotionCode' => 'save20',
            'productName' => 'Full Order Product',
            'city' => 'Plovdiv',
        ]);

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('Guest Buyer', $html);
        $this->assertStringContainsString('save20', $html);
        $this->assertStringContainsString('Full Order Product', $html);
        $this->assertStringContainsString('SKU-', $html);
        $this->assertStringContainsString('Plovdiv', $html);
    }

    /**
     * §14's sections are ONE UNDER ANOTHER. Filament's own default pairs
     * sections up from the `lg` breakpoint (a 2-column `--cols-lg`), which put
     * the very tall lines section beside a short one and left a large empty
     * gap under the short one before the next section began — reported from
     * the panel itself as "Промоция and Плащане are far below Доставка".
     * OrderResource::infolist() sets the page's section grid to a single
     * column; each section's OWN entries keep their own 3-/4-column grid,
     * which is why a 1-column and a 3-/4-column grid style both appear.
     */
    public function test_the_infolist_sections_stack_one_under_another(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('--cols-lg: repeat(1, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('--cols-lg: repeat(2, minmax(0, 1fr))', $html, 'no pair of sections may sit side by side');
    }

    public function test_a_minimal_pickup_point_order_with_no_promotion_renders_without_error(): void
    {
        $this->actingAsStaffRole('Administrator');

        $order = $this->placeOrder([
            'deliveryType' => AddressDeliveryType::PICKUP_POINT,
            'country' => 'BG',
            'city' => null,
            'addressLine1' => null,
            'carrierCode' => 'econt',
            'pickupPointReference' => 'EC-123',
            'settlement' => 'Office 1',
        ]);

        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))
            ->assertOk()
            ->assertSee('EC-123');
    }

    /** §3's own mandatory snapshot test, at the rendered View page level (see OrderAdminReaderTest for the same proof at the reader level). */
    public function test_the_view_page_still_shows_the_original_snapshot_after_the_product_changes(): void
    {
        $variationId = $this->pricedPurchasableVariation('25.00', 'Original Product');
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
        );

        $order = app(CheckoutOrchestrator::class)->place($input, new DateTimeImmutable('2026-09-20 10:00:00'))->order();

        // Captured BEFORE the rename/re-sku/re-price below — this is the
        // snapshot value the View page must still show afterward.
        $originalSku = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('sku');

        $productId = (string) VariationModel::find($variationId)->product_id;
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);
        $product->rename('Renamed After Order');
        $product->variations()[0]->setSku('SKU-RENAMED-AFTER');
        app(ProductRepository::class)->save($product);

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal('999.00', 'EUR'), 0),
        ));

        $this->actingAsStaffRole('Administrator');
        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('Original Product', $html);
        $this->assertStringNotContainsString('Renamed After Order', $html);
        $this->assertStringNotContainsString('SKU-RENAMED-AFTER', $html);
        $this->assertStringNotContainsString('999.00', $html);

        // Positive: the ORIGINAL sku and the formatted ORIGINAL unit
        // price / line total (25.00, qty 1, unaffected by the 999.00
        // re-price above) are actually rendered, not merely "the new
        // values are absent" (which a blank/broken render would also
        // satisfy).
        $this->assertStringContainsString($originalSku, $html);
        $this->assertStringContainsString('25.00 €', $html);
    }

    public function test_a_line_with_null_product_name_and_sku_renders_as_a_dash(): void
    {
        $order = $this->placeOrder(['productName' => 'Null Snapshot Product']);

        $originalSku = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('sku');

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->update(['product_name' => null, 'sku' => null]);

        $this->actingAsStaffRole('Administrator');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Null Snapshot Product', $html);
        $this->assertStringNotContainsString($originalSku, $html);

        // Positive, SCOPED TO THE LINE ROW ITSELF — the page has other
        // fields that also fall back to '—' (e.g. account_id), so a plain
        // "the page contains a dash somewhere" check would pass even if
        // the line row itself silently rendered blank. Slicing the html
        // from the Items section heading to the end of its table body
        // (</tbody>) isolates exactly the rendered rows lineRows() built
        // (see OrderResource::lineRows() — a null productName/sku maps to
        // orders.not_available directly). The boundary used to be the NEXT
        // section heading — (en) "Promotion" — which stopped working once
        // the Lines table's own column labels began with that same word
        // (§3.13 stage 5); those labels have since been renamed (§14's own
        // column pass), but </tbody> stays: it is locale-safe and a
        // tighter scope than any heading-based boundary.
        $linesSectionStart = strpos($html, __('orders.sections.lines'));
        // Since the order-view polish each line is a BLOCK, not a table row; the blocks end where the money summary begins.
        $linesSectionEnd = strpos($html, '<table style="margin-inline-start', $linesSectionStart);
        $this->assertNotFalse($linesSectionStart, 'Items section heading not found in the rendered page');
        $this->assertNotFalse($linesSectionEnd, 'the end of the item blocks (the money summary) was not found in the rendered page');

        $lineRowHtml = substr($html, $linesSectionStart, $linesSectionEnd - $linesSectionStart);

        $this->assertStringContainsString(__('orders.not_available'), $lineRowHtml);
    }

    /**
     * A real, confirmed gap found while adding this helper (see
     * OrderResource::optionLabel()'s own docblock): before, an unknown
     * stored value rendered as the literal translation KEY string
     * ("orders.payment_method_options.xyz"), not the raw value — because
     * Laravel's __() returns the key itself when no translation entry
     * exists (confirmed against installed source, not assumed).
     */
    public function test_an_unknown_payment_method_renders_its_raw_value_not_the_translation_key(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        DB::table('payments')
            ->where('order_id', $order->id())
            ->update(['method' => 'crypto_wallet_xyz']);

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString('crypto_wallet_xyz', $html);
        $this->assertStringNotContainsString('orders.payment_method_options.', $html);
    }

    public function test_profit_never_appears_in_the_rendered_view_html(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('profit', strtolower($html));
    }

    // --- D4: the History section, its first rendering ---------------------------

    /** History is scoped from its own heading to the end of the page — it is this Resource's LAST section, so nothing else can leak into the slice. */
    private function historyHtml(string $html): string
    {
        $start = strpos($html, __('orders.sections.history'));
        $this->assertNotFalse($start, 'History section heading not found in the rendered page');

        return substr($html, $start);
    }

    public function test_the_history_section_renders_a_status_changed_event_with_its_labels_and_the_acting_staff_name(): void
    {
        $order = $this->placeOrder();

        $staff = $this->actingAsStaffRole('Administrator');
        app(OrderStatusChanger::class)->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'), 'accepted by phone');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();
        $historyHtml = $this->historyHtml($html);

        $this->assertStringContainsString(__('orders.event_type_options.status_changed'), $historyHtml);
        $this->assertStringContainsString(__('orders.status_options.confirmed'), $historyHtml);
        $this->assertStringContainsString('accepted by phone', $historyHtml);
        $this->assertStringContainsString($staff->name, $historyHtml);
    }

    /** No staff is authenticated when confirm() runs — a console-style caller — so the row's staff_id/staff_name are NULL (OrderAdminEventView's own documented "System" wording). */
    public function test_the_history_section_shows_system_for_an_event_with_no_authenticated_actor(): void
    {
        $order = $this->placeOrder();

        app(OrderStatusChanger::class)->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'));

        $this->actingAsStaffRole('Administrator');
        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString(__('orders.system_actor'), $this->historyHtml($html));
    }

    public function test_an_order_with_no_history_still_renders_the_history_section_without_error(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))
            ->assertOk()
            ->assertSee(__('orders.sections.history'));
    }

    // --- stage 7c-1: the Payment section's own two facts (§8.4) -----------------

    /**
     * The Payment section's own slice: from its heading to History's heading.
     * Payment and History are this page's last two sections, so the closing
     * boundary is a heading that always exists. The opening heading is also the
     * FIRST place the word appears on the page — "Payment method"/"Payment
     * status" are labels INSIDE this section, and every earlier section is
     * about the order, the client, the address, the lines or the money totals —
     * so the slice cannot silently widen into another section.
     */
    private function paymentHtml(string $html): string
    {
        $start = strpos($html, __('orders.sections.payment'));
        $this->assertNotFalse($start, 'Payment section heading not found in the rendered page');

        $end = strpos($html, __('orders.sections.history'), $start);
        $this->assertNotFalse($end, 'History section heading not found after the Payment section');

        return substr($html, $start, $end - $start);
    }

    /**
     * The order's own payment row as a caller reads it BEFORE anything happens
     * to it — deliberately findByOrderId() and not findSettledForOrder(): the
     * row this helper is used to confirm is, by definition, not settled yet.
     */
    private function theOrdersPaymentRow(string $orderId): Payment
    {
        $payments = app(PaymentRepository::class)->findByOrderId($orderId);

        $this->assertCount(1, $payments, "Order {$orderId} was expected to carry exactly one payment row (its checkout attempt).");

        return $payments[0];
    }

    /**
     * §8.4's hole, closed: before stage 7c-1 NOTHING in this section changed
     * when the money was recorded as received — the page kept rendering
     * "Pending" and the staff member who recorded it was shown no evidence at
     * all that the click had done anything. Both halves of the fix are pinned
     * here: the MONEY fact ("Money held", read from Payment::isSettled() — §11
     * item 17's one predicate, so this entry cannot disagree with the guard
     * that decides whether the order may ship) and the INSTANT it was recorded
     * ("Received at", §4.3's confirmed_at, rendered Y-m-d H:i like every other
     * instant on this page).
     *
     * The adapter's own answer is pinned alongside them because the fix must
     * NOT collapse the two: confirm() moves $status nowhere (§4.2), so this
     * section genuinely holds two independent facts — the adapter said
     * "Pending" and the merchant recorded the money — and a page showing only
     * one of them would be lying about the other.
     */
    public function test_a_confirmed_offline_payment_shows_the_money_is_held_and_when_it_was_received(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $payment = $this->theOrdersPaymentRow($order->id());
        $payment->confirm(new DateTimeImmutable('2026-09-25 09:30:00'));
        app(PaymentRepository::class)->save($payment);

        // The write moved exactly one column and left the adapter's answer
        // alone — read back from storage, not from the object in hand.
        $row = DB::table('payments')->where('id', $payment->id())->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('2026-09-25 09:30:00', $row->confirmed_at);

        $paymentHtml = $this->paymentHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(__('orders.fields.payment_settled'), $paymentHtml);
        $this->assertStringContainsString(__('orders.payment_settled_yes'), $paymentHtml);
        $this->assertStringContainsString(__('orders.fields.payment_confirmed_at'), $paymentHtml);
        $this->assertStringContainsString('2026-09-25 09:30', $paymentHtml);
        $this->assertStringContainsString(
            __('orders.payment_status_options.pending'),
            $paymentHtml,
            "the adapter's own word is still rendered next to it — a second fact, not a replacement"
        );
    }

    /**
     * The adapter's half alone: a captured row IS settled money with no
     * merchant confirmation behind it, and the page must say the money is held
     * while claiming nothing about an instant nobody recorded. §4.5's "no
     * invented state" cuts both ways — the confirmed-at entry is HIDDEN (the
     * payment_status entry above it sets the precedent: an entry whose fact does
     * not exist is not rendered), rather than shown empty or with a dash that
     * would read as "there is a record here".
     */
    public function test_an_adapter_captured_payment_reads_as_settled_with_no_received_instant_invented(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        // A retry is a NEW row (payment-domain-design.md §1): the checkout's own
        // COD attempt stays exactly as it was, this captured row is a second one
        // — which is also what keeps the settled_order_id unique index happy.
        // Dated RELATIVE to now, like OrderAdminReaderTest's own retry fixtures
        // ('+1 hour'): the checkout's row is written at the test's real now, so a
        // hardcoded instant in the past would leave the COD row as the order's
        // latest payment and this test would assert nothing about the adapter
        // half at all.
        $captured = Payment::create($order->id(), 'bank_transfer', $order->total(), PaymentStatus::PENDING);
        $captured->recordAttemptResult(PaymentStatus::CAPTURED, 'ref-captured-001', null, new DateTimeImmutable('+1 hour'));
        app(PaymentRepository::class)->save($captured);

        $this->assertSame(2, DB::table('payments')->where('order_id', $order->id())->count(), 'the retry is a second row');

        $latestRow = DB::table('payments')
            ->where('order_id', $order->id())
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->first();

        $this->assertSame($captured->id(), (string) $latestRow->id, "the section reads the order's latest payment");
        $this->assertSame('captured', $latestRow->status, "the adapter's own answer, stored");
        $this->assertNull($latestRow->confirmed_at, 'and no merchant confirmation anywhere near it');

        $paymentHtml = $this->paymentHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(__('orders.payment_settled_yes'), $paymentHtml, 'the adapter settled it: the money is held');
        $this->assertStringContainsString(__('orders.payment_status_options.captured'), $paymentHtml);
        $this->assertStringContainsString('ref-captured-001', $paymentHtml);
        $this->assertStringNotContainsString(
            __('orders.fields.payment_confirmed_at'),
            $paymentHtml,
            'nothing is recorded on this row, so no entry claims a record exists'
        );
    }

    /**
     * The ordinary state of a cash-on-delivery order: one row, the adapter said
     * Pending, nobody has recorded anything. The section must SAY so — §4.5's
     * "no silence either": an absent fact reads exactly like a page that forgot
     * to ask, which is the failure stage 7c-1 exists to fix (and the negative
     * state must never be dressed up as "unpaid": this entry reports whether the
     * MERCHANT has recorded the money, so what is missing is the merchant's
     * entry, not the customer's money).
     */
    public function test_a_payment_with_nothing_recorded_says_so_instead_of_staying_silent(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $paymentHtml = $this->paymentHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(
            __('orders.fields.payment_settled'),
            $paymentHtml,
            'a payment row exists, so this entry always has an answer to give'
        );
        $this->assertMatchesRegularExpression('/>\s*'.preg_quote(__('orders.payment_settled_no'), '/').'\s*</', $paymentHtml);
        $this->assertDoesNotMatchRegularExpression('/>\s*'.preg_quote(__('orders.payment_settled_yes'), '/').'\s*</', $paymentHtml);
        $this->assertStringNotContainsString(__('orders.fields.payment_confirmed_at'), $paymentHtml);
    }

    /**
     * No payment row at all (the legacy or cancelled-before-checkout shapes):
     * there is no row to ask, so BOTH new entries are hidden. The two entries
     * above already say it in this page's own established words
     * (`orders.no_payment` on method and status), and a third "Money held: Not
     * recorded" would read as an attempt that happened and is being reported on.
     */
    public function test_an_order_with_no_payment_row_hides_both_new_entries(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        DB::table('payments')->where('order_id', $order->id())->delete();

        $paymentHtml = $this->paymentHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(__('orders.no_payment'), $paymentHtml);
        $this->assertStringNotContainsString(__('orders.fields.payment_settled'), $paymentHtml);
        $this->assertDoesNotMatchRegularExpression('/>\s*'.preg_quote(__('orders.payment_settled_yes'), '/').'\s*</', $paymentHtml);
        $this->assertDoesNotMatchRegularExpression('/>\s*'.preg_quote(__('orders.payment_settled_no'), '/').'\s*</', $paymentHtml);
    }

    /**
     * The two new facts cost NOTHING to read. Neither entry asks a repository
     * anything: both take their state off the SAME OrderAdminReader::forOrder()
     * view the rest of this page already builds (§8.4's "no second read"), which
     * is why this measures two DIFFERENT orders rather than one order twice —
     * a second render of the same order would be answered from the reader's own
     * per-order memo and would prove nothing about the query cost. The only
     * difference between the two orders is that one of them has its money
     * recorded, and the two renders must cost the same.
     */
    public function test_the_settled_and_received_facts_add_no_query_of_their_own(): void
    {
        $this->actingAsStaffRole('Administrator');

        $notRecorded = $this->placeOrder();
        $recorded = $this->placeOrder(['email' => 'settled@example.com']);

        $payment = $this->theOrdersPaymentRow($recorded->id());
        $payment->confirm(new DateTimeImmutable('2026-09-25 09:30:00'));
        app(PaymentRepository::class)->save($payment);

        // One discarded render first, so every cache that outlives a single
        // request (Filament's own, the permission registry, ...) is warm for
        // BOTH measurements. countViewPageQueries() drops the scoped instances
        // before each one, because a scoped memo lives as long as this test's
        // container does — and the reader's own memo is exactly what would make
        // a measured render skip the reads being measured.
        $this->countViewPageQueries($notRecorded->id());

        $withoutTheFact = $this->countViewPageQueries($notRecorded->id());
        $withTheFact = $this->countViewPageQueries($recorded->id());

        fwrite(STDERR, "\n[query-count] order view page — money not recorded: {$withoutTheFact} queries, money held: {$withTheFact} queries\n");

        $this->assertSame(
            $withoutTheFact,
            $withTheFact,
            'the settled/received pair is read off the viewer the page already built, so it cannot add a query'
        );
    }

    /** One render of the order view page, counted the same way OrderViewActionsTest counts the same page. */
    private function countViewPageQueries(string $orderId): int
    {
        $this->app->forgetScopedInstances();

        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get(OrderResource::getUrl('view', ['record' => $orderId]))->assertOk();

        return $queries;
    }

    // --- stage 7c-1: History's own order and its return reference (D2, §8.4) ----

    /**
     * §8.4: "newest first, since a merchant opening an order wants the last
     * thing that happened". The reversal is a DISPLAY decision taken by
     * historyRows() — OrderAdminReader::forOrder() still returns events
     * oldest-first, tie-broken by id, which OrderAdminReaderEventsTest pins —
     * so this asserts the ORDER OF THE RENDERED SECTION and nothing else: the
     * newest event's own words must appear before the older event's.
     */
    public function test_the_history_section_renders_the_newest_event_first(): void
    {
        $order = $this->placeOrder();

        $changer = app(OrderStatusChanger::class);
        $changer->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'), 'accepted by phone');
        $changer->ship($order->id(), new DateTimeImmutable('2026-09-22 10:00:00'), 'handed to courier');

        $this->actingAsStaffRole('Administrator');
        $historyHtml = $this->historyHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $newest = strpos($historyHtml, 'handed to courier');
        $oldest = strpos($historyHtml, 'accepted by phone');

        $this->assertNotFalse($newest, "the newest event's own reason was not rendered at all");
        $this->assertNotFalse($oldest, "the older event's own reason was not rendered at all");
        $this->assertLessThan($oldest, $newest, 'the newest event is rendered FIRST');
    }

    /**
     * §8.4's return reference, and this page's own word where there is none.
     * The RETURNED event is the one §6.1 event that points at a record
     * elsewhere (`transaction_id`), so its cell renders that reference as plain
     * text: §8.4's LINK half still cannot be honoured — there is still no
     * Transaction page to link to, and a link to nothing would be worse than
     * text — but on a returned row the id is the only thing that distinguishes
     * one of §7.2's several partial returns from another, which is a thing a
     * merchant acts on daily.
     *
     * This is also the regression guard for HOW the cell is filled: the row
     * array is keyed by the column spec's own key ('return_record'), because a
     * RepeatableEntry child resolves its state by its OWN name — a descriptive
     * key does not render a differently-named cell, it renders an EMPTY one, so
     * only a positive assertion on the reference itself can catch it.
     */
    public function test_the_history_section_renders_the_returns_own_reference(): void
    {
        $order = $this->placeOrder();

        $changer = app(OrderStatusChanger::class);
        $changer->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'), 'accepted by phone');
        $changer->ship($order->id(), new DateTimeImmutable('2026-09-22 10:00:00'), 'handed to courier');

        $saleLineId = (string) DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('id');

        $changer->recordReturn($order->id(), [
            ['originatingSaleLineId' => $saleLineId, 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-09-23 10:00:00'), 'damaged in transit');

        $returnedTransactionId = DB::table('order_events')
            ->where('order_id', $order->id())
            ->where('type', 'returned')
            ->value('transaction_id');

        $this->assertNotNull($returnedTransactionId, 'the return wrote its own transaction reference onto the event row');

        $this->actingAsStaffRole('Administrator');
        $historyHtml = $this->historyHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(__('orders.fields.return_record'), $historyHtml, "the column's own header");
        $this->assertStringContainsString('#'.$returnedTransactionId, $historyHtml, "the returning transaction's own reference");
        $this->assertStringContainsString(__('orders.event_type_options.returned'), $historyHtml);
        $this->assertStringContainsString('damaged in transit', $historyHtml);
    }

    /**
     * The negative half of the same column, on an order where nothing ever came
     * back: every event names no record elsewhere and each says so ONCE, in its
     * own cell (orders.not_available — the word Reason and the line columns
     * already use for an absent value).
     *
     * The assertion is a COUNT rather than a presence, because "the page
     * contains a dash somewhere" cannot tell a correct page apart from a cell
     * that renders empty (the key mismatch the sibling test guards against, in
     * its less obvious direction) or from a dash borrowed from another column.
     * The fixture is chosen so that no other dash can exist: both of its events
     * are status changes with both statuses set to real labels, an operator's
     * own reason and an authenticated actor — so every dash in this section
     * belongs to this one column.
     */
    public function test_the_return_record_column_says_no_record_for_events_that_are_not_returns(): void
    {
        $order = $this->placeOrder();

        $changer = app(OrderStatusChanger::class);
        $changer->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'), 'accepted by phone');
        $changer->ship($order->id(), new DateTimeImmutable('2026-09-22 10:00:00'), 'handed to courier');

        $eventCount = DB::table('order_events')->where('order_id', $order->id())->count();

        $this->assertSame(2, $eventCount, "the fixture's own two calls are this order's whole history");
        $this->assertSame(
            0,
            DB::table('order_events')->where('order_id', $order->id())->whereNotNull('transaction_id')->count(),
            'no event here names a record elsewhere'
        );

        $this->actingAsStaffRole('Administrator');
        $historyHtml = $this->historyHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString(__('orders.fields.return_record'), $historyHtml);
        $this->assertSame(
            $eventCount,
            substr_count($historyHtml, __('orders.not_available')),
            'one dash per event and no more: the return-record cell of each row'
        );
    }

    // --- history goods cell and payment history ---------------------------------------------

    /** @return string the id of the order's first placement sale line */
    private function firstSaleLineId(Order $order): string
    {
        return (string) DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->orderBy('id')
            ->value('id');
    }

    /** Places an order, ships it, and records $returns returns of one unit each. */
    private function orderWithReturns(int $returns, string $productName = 'Alpha Widget'): Order
    {
        $order = $this->placeOrder(['quantity' => 5, 'productName' => $productName]);
        $changer = app(OrderStatusChanger::class);
        $changer->confirm($order->id(), new DateTimeImmutable('2026-09-21 10:00:00'), 'accepted by phone');
        $changer->ship($order->id(), new DateTimeImmutable('2026-09-22 10:00:00'), 'handed to courier');

        for ($i = 1; $i <= $returns; $i++) {
            $changer->recordReturn($order->id(), [
                ['originatingSaleLineId' => $this->firstSaleLineId($order), 'quantityReturned' => 1, 'restock' => true],
            ], new DateTimeImmutable('2026-09-23 1'.$i.':00:00'), 'damaged in transit');
        }

        return $order;
    }

    public function test_a_returns_history_cell_names_the_goods_with_name_sku_and_quantity_and_keeps_the_record_id(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->orderWithReturns(1);

        $placed = DB::table('operational_sales_sale_lines')->where('id', $this->firstSaleLineId($order))->first();
        $sku = (string) $placed->sku;
        $name = (string) $placed->product_name;
        $transactionId = (string) DB::table('order_events')->where('order_id', $order->id())->where('type', 'returned')->value('transaction_id');

        $this->assertNotSame('', $sku);

        $historyHtml = $this->historyHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $this->assertStringContainsString($name.' ('.$sku.') × 1', $historyHtml, 'the goods that came back: name, sku and quantity');
        $this->assertStringContainsString('#'.$transactionId, $historyHtml, 'and the record id the merchant quotes is still in the cell');
    }

    public function test_the_goods_read_is_batched_so_more_transaction_events_cost_no_more_queries(): void
    {
        $this->actingAsStaffRole('Administrator');
        $one = $this->orderWithReturns(1);
        $several = $this->orderWithReturns(3);

        $this->assertGreaterThan(
            3,
            DB::table('order_events')->where('order_id', $several->id())->whereNotNull('transaction_id')->count(),
            'the fixture really has several transaction-carrying events',
        );

        $costOne = $this->countViewPageQueries($one->id());
        $costSeveral = $this->countViewPageQueries($several->id());

        fwrite(STDERR, "\n[query-count] order view page, history goods: 1 return = {$costOne} queries, 3 returns = {$costSeveral} queries\n");

        $this->assertSame($costOne, $costSeveral);
    }

    /** An older payment row (so the order's current payment is unchanged): 'failed' or 'voided'. */
    private function extraPayment(Order $order, string $kind, string $attemptedAt): void
    {
        $payment = Payment::create($order->id(), 'cash_on_delivery', Money::fromMinorUnits(1000, 'EUR'), PaymentStatus::PENDING);

        if ($kind === 'failed') {
            $payment->recordAttemptResult(PaymentStatus::FAILED, null, 'card declined', new DateTimeImmutable($attemptedAt));
        } else {
            $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable($attemptedAt));
            $payment->void(new DateTimeImmutable('2020-02-01 10:00:00'));
        }

        app(PaymentRepository::class)->save($payment);
    }

    public function test_the_payment_attempts_count_is_gone_from_the_page(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->extraPayment($order, 'failed', '2020-01-01 10:00:00');
        $this->extraPayment($order, 'voided', '2020-01-02 10:00:00');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Payment attempts', $html);
        $this->assertStringNotContainsString('3 attempts', $html);
    }

    public function test_the_payment_history_lists_failed_and_superseded_rows_with_the_right_label_and_leaves_the_current_one_alone(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();
        $this->extraPayment($order, 'failed', '2020-01-01 10:00:00');
        $this->extraPayment($order, 'voided', '2020-01-02 10:00:00');

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringContainsString(__('orders.payment_status_options.pending'), $this->paymentHtml($html), 'the current payment renders as before');
        $this->assertStringContainsString(__('orders.payment_history.heading'), $html);
        $this->assertStringContainsString(__('orders.payment_history.failed'), $html, 'a genuine failed attempt');
        $this->assertStringContainsString(__('orders.payment_history.superseded'), $html, 'a voided row');
        $this->assertStringContainsString('2020-01-01 10:00', $html);
        $this->assertStringContainsString('2020-01-02 10:00', $html);
    }

    public function test_an_order_with_one_payment_shows_no_payment_history_disclosure(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->placeOrder();

        $html = $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();

        $this->assertStringNotContainsString(__('orders.payment_history.heading'), $html);
    }

    public function test_the_new_payment_history_keys_exist_in_both_languages(): void
    {
        foreach (['en', 'bg'] as $locale) {
            foreach (['heading', 'amount', 'why', 'failed', 'superseded'] as $key) {
                $this->assertNotSame("orders.payment_history.{$key}", __("orders.payment_history.{$key}", [], $locale), "{$key} missing in {$locale}");
            }
        }
    }

    public function test_goods_are_listed_on_returned_and_refunded_rows_but_not_on_the_payment_voided_row(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->orderWithReturns(1);

        $types = DB::table('order_events')->where('order_id', $order->id())->whereNotNull('transaction_id')->pluck('type')->all();

        $this->assertContains('returned', $types);
        $this->assertContains('payment_voided', $types, 'this return voids the still-pending payment, which is the event under test');

        $placed = DB::table('operational_sales_sale_lines')->where('id', $this->firstSaleLineId($order))->first();
        $goods = $placed->product_name.' ('.$placed->sku.') × 1';
        $transactionId = (string) DB::table('order_events')->where('order_id', $order->id())->where('type', 'returned')->value('transaction_id');

        $historyHtml = $this->historyHtml(
            $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent()
        );

        $goodsEvents = count(array_filter($types, static fn (string $type): bool => in_array($type, ['returned', 'refunded', 'refund_owed'], true)));

        $this->assertSame($goodsEvents, substr_count($historyHtml, $goods), 'the goods appear on the returned (and refunded) rows only');
        $this->assertSame(count($types), substr_count($historyHtml, '#'.$transactionId), 'while every transaction-carrying row, payment_voided included, keeps its record id');
    }
}
