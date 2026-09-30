<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutLinePricer;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\StaleOrderEditException;
use App\Services\OrderDeliveryChange;
use App\Services\OrderEditor;
use App\Services\OrderPaymentConfirmer;
use App\Services\OrderPromotionCodeChange;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefundAttemptResult;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * order-editing-design.md §5-§9, stage 3b — App\Services\OrderEditor::apply()
 * against a REAL database, on orders placed through the REAL
 * CheckoutOrchestrator (so the promotion, redemption and payment state each
 * scenario starts from is genuine, not hand-built).
 *
 * Fixture: variation A at 10.00, B at 5.00, C at 8.00, D at 15.00, each with
 * stock 20. The default order is A x2 (20.00) + B x3 (15.00) = 35.00,
 * cash-on-delivery, so a real PENDING, answered payment for 35.00 exists.
 */
class OrderEditorTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    /** @var array<string, string> */
    private array $variations = [];

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-30 10:00:00');
    }

    private function money(int $minor): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    private function variation(string $key, string $price, int $stock = 20): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "order-editor-{$suffix}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $this->variations[$key] = $variationId;
    }

    private function fixtures(): void
    {
        $this->variation('A', '10.00');
        $this->variation('B', '5.00');
        $this->variation('C', '8.00');
        $this->variation('D', '15.00');
    }

    private function promotion(string $code, int $basisPoints = 1000, ?int $minimumSpendMinor = null): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $basisPoints,
            minimumSpend: $minimumSpendMinor !== null ? $this->money($minimumSpendMinor) : null,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    /**
     * @param  array<string, int>  $lines  fixture key => quantity
     */
    private function place(array $lines = ['A' => 2, 'B' => 3], ?string $code = null, string $method = 'cash_on_delivery'): Order
    {
        if ($this->variations === []) {
            $this->fixtures();
        }

        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));

        foreach ($lines as $key => $quantity) {
            app(CartLineAdder::class)->addLine($cart, $this->variations[$key], $quantity, null, null);
        }

        if ($code !== null) {
            $cart->applyPromotionCode($code);
            app(CartRepository::class)->save($cart);
        }

        $token = app(CartRepository::class)->findById($cart->id())?->sessionToken();

        $result = app(CheckoutOrchestrator::class)->place(new CheckoutInput(
            cartId: $cart->id(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: $method,
            accountId: null,
            guestCartToken: $token,
            addressId: null,
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        ), new DateTimeImmutable('2026-09-29 12:00:00'));

        return $result->order();
    }

    /** @return array<int, SaleLine> The order's current lines, read the way §4.4 defines them (test-side, from the raw tables). */
    private function currentLines(string $orderId): array
    {
        $txIds = [(string) OrderModel::findOrFail($orderId)->transaction_id];

        foreach (DB::table('order_events')->where('order_id', $orderId)->where('type', 'edited')
            ->whereNotNull('transaction_id')->orderBy('id')->pluck('transaction_id') as $id) {
            $txIds[] = (string) $id;
        }

        $out = [];

        foreach ($txIds as $txId) {
            foreach (app(TransactionRepository::class)->findByIdWithSaleLines($txId)->saleLines() as $line) {
                if ($line->type() !== SaleLineType::SALE) {
                    continue;
                }

                $away = (int) DB::table('operational_sales_sale_lines')
                    ->where('type', 'edit_reversal')->where('originating_sale_line_id', $line->id())->sum('quantity_returned');

                if ($line->quantity() - $away > 0) {
                    $out[] = $line;
                }
            }
        }

        return $out;
    }

    private function lineFor(string $orderId, string $key): SaleLine
    {
        foreach ($this->currentLines($orderId) as $line) {
            if ($line->priceableId() === $this->variations[$key]) {
                return $line;
            }
        }

        $this->fail("No current line for {$key}.");
    }

    /** @return array<string, array{int, int}> fixture key => [quantity, net minor], for the current lines. */
    private function state(string $orderId): array
    {
        $byVariation = array_flip($this->variations);
        $out = [];

        foreach ($this->currentLines($orderId) as $line) {
            $out[$byVariation[$line->priceableId()]] = [$line->quantity(), $line->netPaidAmount()->minorValue()];
        }

        ksort($out);

        return $out;
    }

    /** @return array{subtotal: int, discount: int, total: int, revision: int, code: ?string} */
    private function orderRow(string $orderId): array
    {
        $row = OrderModel::findOrFail($orderId);

        return [
            'subtotal' => (int) $row->subtotal_minor,
            'discount' => (int) $row->discount_minor,
            'total' => (int) $row->total_minor,
            'revision' => (int) $row->edit_revision,
            'code' => $row->applied_promotion_code,
        ];
    }

    private function stock(string $key): int
    {
        return app(StockLevelRepository::class)->findByVariationId($this->variations[$key])->quantity();
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function edit(
        Order $order,
        array $changes,
        ?int $revision = null,
        ?OrderDeliveryChange $delivery = null,
        ?OrderPromotionCodeChange $code = null,
        ?string $reason = null,
    ): void {
        app(OrderEditor::class)->apply(
            orderId: $order->id(),
            expectedRevision: $revision ?? (int) OrderModel::findOrFail($order->id())->edit_revision,
            lineChanges: $changes,
            delivery: $delivery,
            promotionCode: $code ?? OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: $reason,
            occurredAt: $this->at(),
        );
    }

    /** @return array<string, mixed> An "add" change for a fixture variation, priced by the real pricer. */
    private function addChange(string $key, int $quantity, int $discretionaryMinor = 0): array
    {
        $priced = app(CheckoutLinePricer::class)->priceLine($this->variations[$key], $quantity, 'EUR');

        return [
            'change' => 'add',
            'pricedLine' => [
                'variationId' => $priced->variationId(),
                'quantity' => $quantity,
                'regularUnitPrice' => $priced->regularUnitPrice(),
                'finalUnitPrice' => $priced->unitPrice(),
                'unitCost' => $priced->unitCost(),
                'productName' => $priced->productName(),
                'sku' => $priced->sku(),
                'promotionDiscountShare' => $this->money(0),
                'discretionaryDiscount' => $this->money($discretionaryMinor),
            ],
        ];
    }

    /** @return array<int, object> */
    private function payments(string $orderId): array
    {
        return DB::table('payments')->where('order_id', $orderId)->orderBy('id')->get()->all();
    }

    /** @return array<int, object> */
    private function events(string $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->where('type', 'edited')->orderBy('id')->get()->all();
    }

    private function counts(): array
    {
        return [
            'transactions' => DB::table('operational_sales_transactions')->count(),
            'lines' => DB::table('operational_sales_sale_lines')->count(),
            'events' => DB::table('order_events')->count(),
            'payments' => DB::table('payments')->count(),
            'redemptions' => DB::table('promotion_redemptions')->count(),
        ];
    }

    // --- one happy path per E2 category ---------------------------------------

    public function test_adding_a_line(): void
    {
        $order = $this->place();
        $tx = DB::table('operational_sales_transactions')->count();

        $this->edit($order, [$this->addChange('C', 1)], reason: 'customer asked for one more');

        $this->assertSame(['subtotal' => 4300, 'discount' => 0, 'total' => 4300, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [2, 2000], 'B' => [3, 1500], 'C' => [1, 800]], $this->state($order->id()));
        $this->assertSame(19, $this->stock('C'));
        $this->assertSame($tx + 1, DB::table('operational_sales_transactions')->count(), 'one edit = one new Transaction');

        $events = $this->events($order->id());
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->from_status);
        $this->assertNull($events[0]->to_status);
        $this->assertSame('customer asked for one more', $events[0]->reason);
        $this->assertNotNull($events[0]->transaction_id);
        $this->assertSame((string) $events[0]->transaction_id, (string) DB::table('operational_sales_transactions')->max('id'));
    }

    public function test_removing_a_line(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineFor($order->id(), 'B')]]);

        $this->assertSame(['subtotal' => 2000, 'discount' => 0, 'total' => 2000, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [2, 2000]], $this->state($order->id()));
        $this->assertSame(20, $this->stock('B'), 'the removed units went back on the shelf');
    }

    public function test_changing_a_quantity_up(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $this->lineFor($order->id(), 'A'), 'quantity' => 3]]);

        $this->assertSame(['subtotal' => 4500, 'discount' => 0, 'total' => 4500, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [3, 3000], 'B' => [3, 1500]], $this->state($order->id()));
        $this->assertSame(17, $this->stock('A'), '20 - 2 placed - 1 more');
    }

    public function test_changing_a_quantity_down(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $this->lineFor($order->id(), 'A'), 'quantity' => 1]]);

        $this->assertSame(['subtotal' => 2500, 'discount' => 0, 'total' => 2500, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [1, 1000], 'B' => [3, 1500]], $this->state($order->id()));
        $this->assertSame(19, $this->stock('A'));
    }

    public function test_giving_a_line_a_manual_discount(): void
    {
        $order = $this->place();

        $this->edit($order, [[
            'change' => 'discount',
            'originatingLine' => $this->lineFor($order->id(), 'B'),
            'discretionaryDiscount' => $this->money(300),
        ]]);

        // The order's discount now carries the manual discount (CheckoutOrchestrator's own formula, extended — see OrderEditor's docblock).
        $this->assertSame(['subtotal' => 3500, 'discount' => 300, 'total' => 3200, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [2, 2000], 'B' => [3, 1200]], $this->state($order->id()));
        $this->assertSame(20 - 3, $this->stock('B'), 'a discount-only edit touches no stock');
    }

    public function test_a_second_edit_sees_the_first_edits_lines_as_current(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $this->lineFor($order->id(), 'A'), 'quantity' => 3]]);
        $this->edit($order, [$this->addChange('C', 2)]);

        $this->assertSame(['subtotal' => 6100, 'discount' => 0, 'total' => 6100, 'revision' => 2, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [3, 3000], 'B' => [3, 1500], 'C' => [2, 1600]], $this->state($order->id()));
        $this->assertCount(2, $this->events($order->id()));
    }

    public function test_the_ledger_is_appended_never_rewritten(): void
    {
        $order = $this->place();
        $placement = DB::table('operational_sales_sale_lines')->orderBy('id')->get()->all();

        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $this->lineFor($order->id(), 'A'), 'quantity' => 1]]);

        foreach ($placement as $row) {
            $this->assertEquals($row, DB::table('operational_sales_sale_lines')->where('id', $row->id)->first(), 'a placement line is never touched');
        }

        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'edit_reversal')->count());
    }

    // --- refusals: nothing written --------------------------------------------

    public function test_a_stale_revision_is_refused_before_anything_runs(): void
    {
        $order = $this->place();
        $fired = 0;
        Hook::action('order.edited', function () use (&$fired): void {
            $fired++;
        });

        $lineA = $this->lineFor($order->id(), 'A');
        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $lineA, 'quantity' => 3]], revision: 0);

        $before = $this->counts();
        $stockBefore = [$this->stock('A'), $this->stock('C')];

        try {
            // The "second operator": still holding revision 0.
            $this->edit($order, [$this->addChange('C', 1)], revision: 0);
            $this->fail('A stale edit must be refused.');
        } catch (StaleOrderEditException $e) {
            $this->assertSame(0, $e->expectedRevision());
            $this->assertSame(1, $e->actualRevision());
            $this->assertSame($order->id(), $e->orderId());
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame($stockBefore, [$this->stock('A'), $this->stock('C')]);
        $this->assertSame(1, $this->orderRow($order->id())['revision']);
        $this->assertSame(1, $fired, 'the hook fired for the first edit only');
    }

    public function test_the_revision_is_checked_before_the_status(): void
    {
        $order = $this->place();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);

        $this->expectException(StaleOrderEditException::class);

        $this->edit($order, [$this->addChange('C', 1)], revision: 7);
    }

    /** @return array<string, array{string}> */
    public static function nonEditableStatuses(): array
    {
        return ['shipped' => ['shipped'], 'delivered' => ['delivered'], 'cancelled' => ['cancelled'], 'refunded' => ['refunded']];
    }

    #[DataProvider('nonEditableStatuses')]
    public function test_a_non_editable_status_is_refused_and_nothing_is_written(string $status): void
    {
        $order = $this->place();
        DB::table('orders')->where('id', $order->id())->update(['status' => $status]);
        $before = $this->counts();
        $stockBefore = $this->stock('C');

        try {
            $this->edit($order, [$this->addChange('C', 1)]);
            $this->fail('An order that is not placed/confirmed must not be editable.');
        } catch (OrderNotEditableException $e) {
            $this->assertSame(OrderStatus::from($status), $e->status());
            $this->assertFalse($e->isBecauseOfSettledPayment());
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame($stockBefore, $this->stock('C'));
        $this->assertSame(0, $this->orderRow($order->id())['revision']);
    }

    public function test_a_confirmed_order_is_editable(): void
    {
        $order = $this->place();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'confirmed']);

        $this->edit($order, [$this->addChange('C', 1)]);

        $this->assertSame(1, $this->orderRow($order->id())['revision']);
    }

    public function test_a_settled_payment_refuses_the_whole_edit(): void
    {
        $order = $this->place();
        $paymentId = $this->payments($order->id())[0]->id;
        app(OrderPaymentConfirmer::class)->confirm((string) $paymentId, $this->at());

        $this->assertTrue(app(PaymentRepository::class)->findById((string) $paymentId)->isSettled(), 'a real settled payment, via the real confirmation service');

        $before = $this->counts();

        try {
            $this->edit($order, [$this->addChange('C', 1)]);
            $this->fail('A settled payment must refuse the edit.');
        } catch (OrderNotEditableException $e) {
            $this->assertTrue($e->isBecauseOfSettledPayment());
            $this->assertSame(OrderStatus::PLACED, $e->status());
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame(20, $this->stock('C'));
    }

    public function test_an_unknown_or_empty_order_id_is_refused(): void
    {
        $this->place();

        foreach (['', '999999'] as $id) {
            try {
                app(OrderEditor::class)->apply($id, 0, [], null, OrderPromotionCodeChange::unchanged(), null, null, null, $this->at());
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- promotions ------------------------------------------------------------

    public function test_a_kept_valid_code_recomputes_its_discount_on_the_new_lines(): void
    {
        $promotion = $this->promotion('TEN', 1000);
        $order = $this->place(['A' => 2, 'B' => 3], 'TEN');
        $this->assertSame(['subtotal' => 3500, 'discount' => 350, 'total' => 3150, 'revision' => 0, 'code' => 'ten'], $this->orderRow($order->id()));

        $lineB = $this->lineFor($order->id(), 'B');
        $redemptions = DB::table('promotion_redemptions')->count();

        $this->edit($order, [['change' => 'change_quantity', 'originatingLine' => $this->lineFor($order->id(), 'A'), 'quantity' => 3]]);

        $this->assertSame(['subtotal' => 4500, 'discount' => 450, 'total' => 4050, 'revision' => 1, 'code' => 'ten'], $this->orderRow($order->id()));
        $this->assertSame(['A' => [3, 2700], 'B' => [3, 1350]], $this->state($order->id()));
        $this->assertSame($lineB->id(), $this->lineFor($order->id(), 'B')->id(), 'B\'s share did not move, so B was not rewritten');
        $this->assertSame($redemptions, DB::table('promotion_redemptions')->count(), 'a kept code is not redeemed again');
        $this->assertNull(app(PromotionRedemptionRepository::class)->findByOrderId($order->id())->releasedAt());
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
    }

    public function test_a_fixed_amount_code_is_reallocated_across_every_line(): void
    {
        $promotion = Promotion::create(
            code: 'FIVE',
            discountType: PromotionDiscountType::FIXED_AMOUNT,
            discountAmount: $this->money(500),
        );
        app(PromotionRepository::class)->save($promotion);
        $order = $this->place(['A' => 2, 'B' => 3], 'FIVE');

        // Removing B leaves A alone: the whole 5.00 now sits on A, so A must be re-written even though it was not named.
        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineFor($order->id(), 'B')]]);

        $this->assertSame(['subtotal' => 2000, 'discount' => 500, 'total' => 1500, 'revision' => 1, 'code' => 'five'], $this->orderRow($order->id()));
        $this->assertSame(['A' => [2, 1500]], $this->state($order->id()));
    }

    public function test_an_edit_that_invalidates_the_code_is_refused_unless_the_code_is_removed(): void
    {
        $promotion = $this->promotion('MIN30', 1000, minimumSpendMinor: 3000);
        $order = $this->place(['A' => 2, 'B' => 3], 'MIN30');
        $lineB = $this->lineFor($order->id(), 'B');
        $before = $this->counts();

        try {
            $this->edit($order, [['change' => 'remove', 'originatingLine' => $lineB]]);
            $this->fail('Dropping below the minimum spend must refuse the edit.');
        } catch (PromotionNoLongerValidException $e) {
            $this->assertStringContainsString('minimum_spend_not_met', $e->getMessage());
        }

        $this->assertSame($before, $this->counts(), 'nothing written');
        $this->assertSame(20 - 3, $this->stock('B'));
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));

        // The same edit, with the code explicitly removed in the same call.
        $this->edit($order, [['change' => 'remove', 'originatingLine' => $lineB]], code: OrderPromotionCodeChange::removed());

        $this->assertSame(['subtotal' => 2000, 'discount' => 0, 'total' => 2000, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(0, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()), 'the redemption was released');
    }

    public function test_removing_a_code_releases_its_redemption_and_clears_the_discount(): void
    {
        $promotion = $this->promotion('TEN', 1000);
        $order = $this->place(['A' => 2, 'B' => 3], 'TEN');
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));

        $this->edit($order, [], code: OrderPromotionCodeChange::removed());

        $this->assertSame(['subtotal' => 3500, 'discount' => 0, 'total' => 3500, 'revision' => 1, 'code' => null], $this->orderRow($order->id()));
        $this->assertSame(['A' => [2, 2000], 'B' => [3, 1500]], $this->state($order->id()), 'every line was re-written without its share');
        $this->assertSame(0, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
        $redemption = app(PromotionRedemptionRepository::class)->findByOrderId($order->id());
        $this->assertTrue($redemption->isReleased());
        $this->assertSame(3500, (int) $this->payments($order->id())[1]->amount_minor, 'the payment followed the new total');
    }

    public function test_setting_a_new_code_validates_it_and_writes_a_new_redemption(): void
    {
        $promotion = $this->promotion('TEN', 1000);
        $order = $this->place();
        $this->assertSame(0, DB::table('promotion_redemptions')->count());

        $this->edit($order, [], code: OrderPromotionCodeChange::set('TEN'));

        $this->assertSame(['subtotal' => 3500, 'discount' => 350, 'total' => 3150, 'revision' => 1, 'code' => 'ten'], $this->orderRow($order->id()));
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
        $this->assertSame((string) $promotion->id(), app(PromotionRedemptionRepository::class)->findByOrderId($order->id())->promotionId());
        $this->assertSame(3150, (int) $this->payments($order->id())[1]->amount_minor);
    }

    public function test_replacing_a_code_releases_the_old_redemption_and_writes_a_new_one(): void
    {
        $ten = $this->promotion('TEN', 1000);
        $twenty = $this->promotion('TWENTY', 2000);
        $order = $this->place(['A' => 2, 'B' => 3], 'TEN');

        $this->edit($order, [], code: OrderPromotionCodeChange::set('TWENTY'));

        $this->assertSame(['subtotal' => 3500, 'discount' => 700, 'total' => 2800, 'revision' => 1, 'code' => 'twenty'], $this->orderRow($order->id()));
        $this->assertSame(0, app(PromotionRedemptionRepository::class)->countForPromotion($ten->id()));
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($twenty->id()));
        $this->assertSame(2, DB::table('promotion_redemptions')->where('order_id', $order->id())->count());

        // findByOrderId() answers with the LIVE redemption, so a later removal/cancel releases the right row.
        $live = app(PromotionRedemptionRepository::class)->findByOrderId($order->id());
        $this->assertSame((string) $twenty->id(), $live->promotionId());
        $this->assertFalse($live->isReleased());

        $this->edit($order, [], code: OrderPromotionCodeChange::removed());
        $this->assertSame(0, app(PromotionRedemptionRepository::class)->countForPromotion($twenty->id()), 'the second edit released the row that was actually live');
    }

    public function test_setting_an_unknown_code_is_refused(): void
    {
        $order = $this->place();
        $before = $this->counts();

        $this->expectException(PromotionNoLongerValidException::class);

        try {
            $this->edit($order, [$this->addChange('C', 1)], code: OrderPromotionCodeChange::set('NOPE'));
        } finally {
            $this->assertSame($before, $this->counts());
        }
    }

    public function test_setting_the_code_the_order_already_has_is_treated_as_unchanged(): void
    {
        $promotion = $this->promotion('TEN', 1000);
        $order = $this->place(['A' => 2, 'B' => 3], 'TEN');
        $redemption = app(PromotionRedemptionRepository::class)->findByOrderId($order->id());

        $this->edit($order, [], code: OrderPromotionCodeChange::set('TEN'));

        $this->assertSame(1, DB::table('promotion_redemptions')->count());
        $this->assertSame($redemption->id(), app(PromotionRedemptionRepository::class)->findByOrderId($order->id())->id());
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
    }

    public function test_an_edit_with_no_code_involved_touches_no_redemption_row(): void
    {
        $order = $this->place();

        $this->edit($order, [$this->addChange('C', 1)]);

        $this->assertSame(0, DB::table('promotion_redemptions')->count());
    }

    public function test_a_delivery_only_edit_with_a_kept_code_touches_no_redemption_and_no_line(): void
    {
        $this->promotion('TEN', 1000);
        $order = $this->place(['A' => 2, 'B' => 3], 'TEN');
        $before = $this->counts();

        $this->edit($order, [], delivery: $this->streetDelivery('Plovdiv'));

        $after = $this->counts();
        $this->assertSame($before['redemptions'], $after['redemptions']);
        $this->assertSame($before['lines'], $after['lines']);
        $this->assertSame($before['transactions'], $after['transactions']);
        $this->assertSame($before['payments'], $after['payments']);
        $this->assertSame(['subtotal' => 3500, 'discount' => 350, 'total' => 3150, 'revision' => 1, 'code' => 'ten'], $this->orderRow($order->id()));
    }

    // --- money -------------------------------------------------------------------

    public function test_a_higher_total_voids_the_pending_payment_and_creates_a_new_one_for_the_higher_amount(): void
    {
        $order = $this->place();
        $this->assertSame(3500, (int) $this->payments($order->id())[0]->amount_minor);

        $this->edit($order, [$this->addChange('C', 1)]);

        $payments = $this->payments($order->id());
        $this->assertCount(2, $payments);
        $this->assertNotNull($payments[0]->voided_at);
        $this->assertSame(3500, (int) $payments[0]->amount_minor);
        $this->assertSame(4300, (int) $payments[1]->amount_minor);
        $this->assertNull($payments[1]->voided_at);
        $this->assertSame('pending', $payments[1]->status);
        $this->assertNotNull($payments[1]->attempted_at, 'the adapter really answered; attemptedAt is not left null');
        $this->assertSame('cash_on_delivery', $payments[1]->method);
    }

    public function test_a_lower_total_creates_a_new_payment_for_the_lower_amount(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineFor($order->id(), 'B')]]);

        $payments = $this->payments($order->id());
        $this->assertCount(2, $payments);
        $this->assertNotNull($payments[0]->voided_at);
        $this->assertSame(2000, (int) $payments[1]->amount_minor);
        $this->assertNotNull($payments[1]->attempted_at);
        $this->assertSame(1, count(array_filter($payments, static fn (object $p): bool => $p->voided_at === null)), 'only one payment is ever current');
    }

    public function test_an_unchanged_total_touches_the_payments_table_not_at_all(): void
    {
        $order = $this->place();
        $paymentsBefore = $this->payments($order->id());

        // Remove B (15.00) and add D x1 (15.00): the lines change, the total does not.
        $this->edit($order, [
            ['change' => 'remove', 'originatingLine' => $this->lineFor($order->id(), 'B')],
            $this->addChange('D', 1),
        ]);

        $this->assertSame(['A' => [2, 2000], 'D' => [1, 1500]], $this->state($order->id()));
        $this->assertSame(3500, $this->orderRow($order->id())['total']);
        $this->assertEquals($paymentsBefore, $this->payments($order->id()), 'zero new rows, zero void, zero updated columns');
    }

    public function test_an_order_with_no_payment_at_all_is_editable_and_touches_no_payment(): void
    {
        $order = $this->place();
        DB::table('payments')->where('order_id', $order->id())->delete();

        $this->edit($order, [$this->addChange('C', 1)]);

        $this->assertSame(4300, $this->orderRow($order->id())['total']);
        $this->assertSame([], $this->payments($order->id()));
    }

    public function test_a_voided_payment_is_not_reissued(): void
    {
        $order = $this->place();
        DB::table('payments')->where('order_id', $order->id())->update(['voided_at' => $this->at()]);

        $this->edit($order, [$this->addChange('C', 1)]);

        $this->assertCount(1, $this->payments($order->id()));
    }

    // --- delivery, events, hook ----------------------------------------------------

    private function streetDelivery(string $city): OrderDeliveryChange
    {
        return new OrderDeliveryChange(
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'New Recipient',
            phone: '+359888999999',
            country: 'BG',
            city: $city,
            addressLine1: 'Main Street 5',
            carrierCode: 'econt',
        );
    }

    public function test_a_delivery_only_edit_bumps_the_revision_once_and_writes_one_event(): void
    {
        $order = $this->place();
        $before = $this->counts();

        $this->edit($order, [], delivery: $this->streetDelivery('Plovdiv'));

        $row = OrderModel::findOrFail($order->id());
        $this->assertSame(1, (int) $row->edit_revision);
        $this->assertSame('Plovdiv', $row->city);
        $this->assertSame('New Recipient', $row->recipient_name);
        $this->assertSame('econt', $row->carrier_code, 'a street order may now carry a courier');
        $this->assertSame((string) $order->addressId(), (string) $row->address_id, 'addressId is provenance and is never re-pointed');

        $events = $this->events($order->id());
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->transaction_id, 'no line changed, so there is no edit Transaction');
        $this->assertSame($before['transactions'], DB::table('operational_sales_transactions')->count());
        $this->assertSame($before['payments'], DB::table('payments')->count());
        $this->assertSame(3500, $this->orderRow($order->id())['total']);
    }

    public function test_an_invalid_delivery_is_refused_and_rolls_the_line_changes_back(): void
    {
        $order = $this->place();
        $before = $this->counts();

        try {
            $this->edit($order, [$this->addChange('C', 1)], delivery: new OrderDeliveryChange(
                deliveryType: OrderDeliveryType::STREET_ADDRESS,
                recipientName: 'X',
                phone: '1',
                country: null,
                city: null,
                addressLine1: null,
            ));
            $this->fail('An invalid delivery must be refused.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame(20, $this->stock('C'));
        $this->assertSame(0, $this->orderRow($order->id())['revision']);
    }

    public function test_the_hook_fires_exactly_once_after_the_commit_with_the_new_revision(): void
    {
        $order = $this->place();
        $calls = [];
        $levelAtHook = null;
        Hook::action('order.edited', function (Order $edited, int $revision) use (&$calls, &$levelAtHook): void {
            $calls[] = [$edited->id(), $revision, $edited->total()->minorValue()];
            $levelAtHook = DB::transactionLevel();
        });

        $levelBefore = DB::transactionLevel();
        $this->edit($order, [$this->addChange('C', 1)]);

        $this->assertSame([[$order->id(), 1, 4300]], $calls);
        $this->assertSame($levelBefore, $levelAtHook, 'fired outside the edit\'s own transaction');

        $this->edit($order, [$this->addChange('C', 1)]);
        $this->assertSame(2, $calls[1][1]);
        $this->assertCount(2, $calls);
    }

    public function test_the_hook_does_not_fire_for_a_refused_edit(): void
    {
        $order = $this->place();
        $fired = 0;
        Hook::action('order.edited', function () use (&$fired): void {
            $fired++;
        });
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);

        try {
            $this->edit($order, [$this->addChange('C', 1)]);
        } catch (OrderNotEditableException) {
        }

        $this->assertSame(0, $fired);
    }

    // --- rollback --------------------------------------------------------------------

    public function test_a_failure_in_the_money_step_rolls_everything_back_together(): void
    {
        $this->promotion('TEN', 1000);
        $order = $this->place();
        $before = $this->counts();
        $orderBefore = $this->orderRow($order->id());
        $fired = 0;
        Hook::action('order.edited', function () use (&$fired): void {
            $fired++;
        });

        // From here on the adapter "dies" on charge(): the edit's LAST write step.
        $this->app->bind('payment.adapter.cash_on_delivery', fn () => new class implements PaymentMethodAdapter
        {
            public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
            {
                throw new RuntimeException('Simulated failure in the money step.');
            }

            public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
            {
                throw new RuntimeException('Not exercised.');
            }
        });

        try {
            // Lines + new code + delivery + a higher total, all in one edit.
            $this->edit(
                $order,
                [$this->addChange('C', 1)],
                delivery: $this->streetDelivery('Plovdiv'),
                code: OrderPromotionCodeChange::set('TEN'),
            );
            $this->fail('The simulated adapter failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure in the money step.', $e->getMessage());
        }

        $this->assertSame($before, $this->counts(), 'no line, transaction, event, redemption or payment row survived');
        $this->assertSame($orderBefore, $this->orderRow($order->id()), 'totals, code and revision are back');
        $this->assertSame('Sofia', OrderModel::findOrFail($order->id())->city, 'the delivery change was rolled back too');
        $this->assertSame(20, $this->stock('C'), 'stock was rolled back');
        $this->assertNull($this->payments($order->id())[0]->voided_at, 'the original payment is not voided');
        $this->assertSame(0, $fired, 'no hook for a rolled-back edit');
    }

    // --- query count -----------------------------------------------------------------

    private function queries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    private function representativeEdit(int $extraLines): int
    {
        $this->fixtures();
        $lines = ['A' => 2, 'B' => 3];

        for ($i = 0; $i < $extraLines; $i++) {
            $this->variation("X{$i}", '3.00');
            $lines["X{$i}"] = 1;
        }

        $this->promotion('TEN'.$extraLines, 1000);
        $order = $this->place($lines, 'TEN'.$extraLines);
        $currentA = $this->lineFor($order->id(), 'A');
        $currentB = $this->lineFor($order->id(), 'B');
        $revision = (int) OrderModel::findOrFail($order->id())->edit_revision;

        // 2 lines changed, delivery changed, the code kept.
        return $this->queries(fn () => $this->edit($order, [
            ['change' => 'change_quantity', 'originatingLine' => $currentA, 'quantity' => 3],
            ['change' => 'change_quantity', 'originatingLine' => $currentB, 'quantity' => 2],
        ], $revision, $this->streetDelivery('Plovdiv')));
    }

    public function test_the_query_count_is_bounded_and_does_not_grow_with_the_number_of_lines(): void
    {
        $small = $this->representativeEdit(0);

        // A second, independent order with 4 more lines in a fresh fixture set.
        $this->variations = [];
        $large = $this->representativeEdit(4);

        fwrite(STDERR, "\n[query-count] OrderEditor::apply() — 2 lines changed + delivery changed + code kept: 2-line order: {$small} queries, 6-line order: {$large} queries\n");

        // Bounded, not per-line: four extra lines add NOT ONE query (the reads are batched,
        // and the untouched lines' shares did not move, so none is rewritten).
        $this->assertSame($small, $large);
    }
}
