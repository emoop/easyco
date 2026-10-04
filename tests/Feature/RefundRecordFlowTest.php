<?php

namespace Tests\Feature;

use App\Services\Exceptions\NonOfflineRefundAdapterException;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentAttemptResult;
use EasyCo\Payment\PaymentContext;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Payment\PaymentRefundAttemptResult;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Refunds R1a (shipping-domain-design.md §7.2): the refund RECORD, end to end
 * through OrderStatusChanger — what an offline refund is created as (OWED), what
 * it is made of, what the ledger records, and that the DEFAULT request changes
 * nothing about the goods side of a cancel or a return.
 */
class RefundRecordFlowTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Recording a refund on a settled payment needs the permission of its channel (R1b).
        $this->actingAsAdministrator();
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function money(int $minor): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-28 12:00:00');
    }

    private function variationWithStock(int $stock): string
    {
        self::$counter++;
        $product = Product::createSimple('Refund Product '.self::$counter, 'RFD-'.self::$counter, 'refund-flow-'.self::$counter);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function stock(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    /**
     * A SHIPPED order of one line: $quantity units at $unitMinor, with a SETTLED payment of the whole.
     *
     * @return array{orderId: string, saleLineId: string, variationId: string, payment: Payment}
     */
    private function shippedOrder(int $quantity, int $unitMinor, string $method = 'cash_on_delivery', ?int $stock = null, int $shippingMinor = 0): array
    {
        $variationId = $this->variationWithStock($stock ?? 10);
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $total = $quantity * $unitMinor;
        $paid = $total + $shippingMinor;
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $client->id(),
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: $quantity,
            amount: $this->money($total),
            profit: $this->money(200 * $quantity),
            recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            productName: 'Product',
            sku: 'SKU-1',
            regularUnitPrice: $this->money($unitMinor),
            finalUnitPrice: $this->money($unitMinor),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: $this->money($total),
            soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: $this->money($total),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            status: OrderStatus::SHIPPED,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shipping: $this->money($shippingMinor),
            shippingMethodName: $shippingMinor > 0 ? 'Test courier' : null,
        );
        app(OrderRepository::class)->save($order);

        $payment = Payment::create($order->id(), $method, $this->money($paid), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        $payment->confirm(new DateTimeImmutable('2026-09-28 09:30:00'));
        app(PaymentRepository::class)->save($payment);

        return [
            'orderId' => $order->id(),
            'saleLineId' => (string) $transaction->saleLines()[0]->id(),
            'variationId' => $variationId,
            'payment' => $payment,
        ];
    }

    /** @return list<PaymentRefund> */
    private function refunds(Payment $payment): array
    {
        return app(PaymentRefundRepository::class)->findByPaymentId($payment->id());
    }

    /** @return list<object> the REFUND rows the return wrote, in order */
    private function refundLineRows(): array
    {
        return DB::table('operational_sales_sale_lines')->where('type', 'refund')->orderBy('id')->get()->all();
    }

    private function eventTypes(string $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->pluck('type')->all();
    }

    // --- today's flows: the same goods, stock and totals --------------------------------------------

    public function test_the_default_request_changes_nothing_about_a_return_except_the_refund_state_and_the_history_label(): void
    {
        $withNull = $this->shippedOrder(5, 1000);
        $withDefaults = $this->shippedOrder(5, 1000);

        $this->changer()->recordReturn($withNull['orderId'], [['originatingSaleLineId' => $withNull['saleLineId'], 'quantityReturned' => 2, 'restock' => true]], $this->at(), 'wrong size');
        $this->changer()->recordReturn($withDefaults['orderId'], [['originatingSaleLineId' => $withDefaults['saleLineId'], 'quantityReturned' => 2, 'restock' => true]], $this->at(), 'wrong size', RefundRequest::defaults());

        // Stock: 10 + 2 restocked, both ways.
        $this->assertSame(12, $this->stock($withNull['variationId']));
        $this->assertSame(12, $this->stock($withDefaults['variationId']));

        // The goods lines: computed share floor(5000 * 2 / 5) = 2000 in all three amount fields.
        $rows = $this->refundLineRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(2000, (int) $row->amount_minor);
            $this->assertSame(2000, (int) $row->actual_refund_amount_minor);
            $this->assertSame(2000, (int) $row->default_refund_amount_minor);
            $this->assertSame(2, (int) $row->quantity_returned);
        }

        // The refund: total 2000, all goods, the only differences from before being the state and the label.
        foreach ([$withNull, $withDefaults] as $fixture) {
            $refunds = $this->refunds($fixture['payment']);
            $this->assertCount(1, $refunds);
            $refund = $refunds[0];

            $this->assertSame(2000, $refund->amount()->minorValue());
            $this->assertSame(2000, $refund->breakdown()->goods->minorValue());
            $this->assertTrue($refund->breakdown()->shipping->isZero());
            $this->assertTrue($refund->breakdown()->deduction->isZero());
            $this->assertSame(PaymentRefundStatus::OWED, $refund->status(), 'an offline refund is OWED, not COMPLETED');
            $this->assertSame($fixture['orderId'], $refund->orderId());
            $this->assertSame(RefundChannel::CASH, $refund->channel(), 'cash on delivery pays back in cash');
            $this->assertSame(['returned', 'refund_owed'], $this->eventTypes($fixture['orderId']), 'the history says "refund owed", not "refunded"');
            $this->assertCount(1, $refund->breakdown()->lines);
            $this->assertSame($fixture['saleLineId'], $refund->breakdown()->lines[0]->saleLineId);
            $this->assertSame(2000, $refund->breakdown()->lines[0]->amount->minorValue());
        }
    }

    public function test_a_cancel_with_the_default_request_still_refunds_the_whole_goods_amount_as_owed(): void
    {
        $fixture = $this->shippedOrder(2, 1500);

        $this->changer()->cancel($fixture['orderId'], $this->at(), 'changed mind');

        $this->assertSame(12, $this->stock($fixture['variationId']));
        $refunds = $this->refunds($fixture['payment']);
        $this->assertCount(1, $refunds);
        $this->assertSame(3000, $refunds[0]->amount()->minorValue());
        $this->assertSame(PaymentRefundStatus::OWED, $refunds[0]->status());
        $this->assertSame(['returned', 'status_changed', 'refund_owed'], $this->eventTypes($fixture['orderId']));
    }

    public function test_a_bank_transfer_refund_defaults_to_the_bank_channel(): void
    {
        $fixture = $this->shippedOrder(2, 1000, 'bank_transfer');

        $this->changer()->recordReturn($fixture['orderId'], [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 1, 'restock' => true]], $this->at());

        $this->assertSame(RefundChannel::BANK, $this->refunds($fixture['payment'])[0]->channel());
    }

    public function test_the_refund_recorded_hook_receives_the_owed_refund_and_the_paid_out_hook_does_not_fire(): void
    {
        $fixture = $this->shippedOrder(1, 1000);
        $received = null;
        $paidOut = 0;
        Hook::action('order.refund_recorded', function ($order, $refund) use (&$received): void {
            $received = $refund;
        });
        Hook::action('order.refund_paid_out', function () use (&$paidOut): void {
            $paidOut++;
        });

        // A DELIVERED order's full return reaches REFUNDED; the refund it records is only OWED.
        // (the status is moved to delivered below)
        DB::table('orders')->where('id', $fixture['orderId'])->update(['status' => 'delivered']);
        $this->changer()->recordReturn($fixture['orderId'], [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 1, 'restock' => true]], $this->at());

        $this->assertInstanceOf(PaymentRefund::class, $received);
        $this->assertSame(PaymentRefundStatus::OWED, $received->status());
        $this->assertSame(0, $paidOut, 'money was not returned yet: an extension must not be told it was');
    }

    // --- what the merchant ENTERED ----------------------------------------------------------------------

    public function test_the_ledger_records_the_entered_amount_and_the_computed_share_and_the_refund_is_the_entered_total(): void
    {
        $fixture = $this->shippedOrder(5, 1000);

        $this->changer()->recordReturn(
            $fixture['orderId'],
            [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 2, 'restock' => true]],
            $this->at(),
            null,
            new RefundRequest(enteredGoodsByLine: [$fixture['saleLineId'] => $this->money(1500)]),
        );

        [$row] = $this->refundLineRows();
        $this->assertSame(1500, (int) $row->amount_minor, 'the line\'s amount is what the merchant entered');
        $this->assertSame(1500, (int) $row->actual_refund_amount_minor);
        $this->assertSame(2000, (int) $row->default_refund_amount_minor, 'the computed share is kept beside it');

        $refund = $this->refunds($fixture['payment'])[0];
        $this->assertSame(1500, $refund->amount()->minorValue());
        $this->assertSame(1500, $refund->breakdown()->lines[0]->amount->minorValue());
    }

    public function test_a_zero_total_creates_no_payment_refund_but_the_goods_still_move(): void
    {
        $fixture = $this->shippedOrder(5, 1000);

        $this->changer()->recordReturn(
            $fixture['orderId'],
            [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 2, 'restock' => true]],
            $this->at(),
            'goods back, nothing to pay',
            new RefundRequest(enteredGoodsByLine: [$fixture['saleLineId'] => Money::zero('EUR')]),
        );

        $this->assertSame([], $this->refunds($fixture['payment']), 'no money moves, so no refund exists');
        $this->assertSame(12, $this->stock($fixture['variationId']), 'the goods are back in stock');

        [$row] = $this->refundLineRows();
        $this->assertSame(0, (int) $row->amount_minor);
        $this->assertSame(0, (int) $row->actual_refund_amount_minor);
        $this->assertSame(2000, (int) $row->default_refund_amount_minor);
        $this->assertSame(2, (int) $row->quantity_returned, 'the units count as returned');
        $this->assertSame(['returned'], $this->eventTypes($fixture['orderId']), 'a return event, and no refund event');
    }

    public function test_the_deduction_and_the_shipping_refund_are_on_the_refund_and_on_no_sale_line(): void
    {
        $fixture = $this->shippedOrder(5, 1000, shippingMinor: 300);

        $this->changer()->recordReturn(
            $fixture['orderId'],
            [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 2, 'restock' => true]],
            $this->at(),
            null,
            new RefundRequest(shipping: $this->money(300), deduction: $this->money(200), deductionReason: 'damaged on return'),
        );

        $refund = $this->refunds($fixture['payment'])[0];
        $this->assertSame(2100, $refund->amount()->minorValue(), 'goods 2000 + shipping 300 - deduction 200');
        $this->assertSame(2000, $refund->breakdown()->goods->minorValue());
        $this->assertSame(300, $refund->breakdown()->shipping->minorValue());
        $this->assertSame(200, $refund->breakdown()->deduction->minorValue());
        $this->assertSame('damaged on return', $refund->breakdown()->deductionReason);

        // The REFUND lines add up to the goods component only: no line was reduced, none carries the shipping.
        $rows = $this->refundLineRows();
        $this->assertSame(2000, array_sum(array_map(static fn (object $r): int => (int) $r->amount_minor, $rows)));
        foreach ($rows as $row) {
            $this->assertSame(2000, (int) $row->amount_minor);
            $this->assertSame(2000, (int) $row->actual_refund_amount_minor);
            $this->assertNotContains((int) $row->amount_minor, [1800, 2300, 2100, 300, 200]);
        }
    }

    public function test_a_deduction_without_a_reason_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deduction requires a reason');

        new RefundRequest(deduction: $this->money(100));
    }

    public function test_an_entered_amount_for_a_line_that_is_not_being_returned_is_refused_and_nothing_is_written(): void
    {
        $fixture = $this->shippedOrder(5, 1000);

        try {
            $this->changer()->recordReturn(
                $fixture['orderId'],
                [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 1, 'restock' => true]],
                $this->at(),
                null,
                new RefundRequest(enteredGoodsByLine: ['999999' => $this->money(100)]),
            );
            $this->fail('an entered amount that names no returned line must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not one of the lines being returned', $exception->getMessage());
        }

        $this->assertSame(10, $this->stock($fixture['variationId']), 'rolled back');
        $this->assertSame([], $this->refundLineRows());
        $this->assertSame([], $this->refunds($fixture['payment']));
    }

    // --- offline only ------------------------------------------------------------------------------------

    public function test_a_non_offline_adapter_in_the_refund_path_is_refused_by_name_and_everything_rolls_back(): void
    {
        $fixture = $this->shippedOrder(5, 1000);

        $this->app->bind('payment.adapter.cash_on_delivery', fn () => new class implements PaymentMethodAdapter
        {
            public function charge(Money $amount, PaymentContext $context): PaymentAttemptResult
            {
                return PaymentAttemptResult::pending();
            }

            public function refund(Payment $original, Money $amount, PaymentContext $context): PaymentRefundAttemptResult
            {
                throw new \LogicException('an online adapter must never be called inside the transaction');
            }

            public function isOffline(): bool
            {
                return false;
            }
        });

        try {
            $this->changer()->recordReturn($fixture['orderId'], [['originatingSaleLineId' => $fixture['saleLineId'], 'quantityReturned' => 2, 'restock' => true]], $this->at());
            $this->fail('a non-offline adapter must be refused.');
        } catch (NonOfflineRefundAdapterException $exception) {
            $this->assertStringContainsString('"cash_on_delivery" is not offline', $exception->getMessage());
        }

        $this->assertSame(10, $this->stock($fixture['variationId']), 'the goods half rolled back with it');
        $this->assertSame([], $this->refundLineRows());
        $this->assertSame([], $this->refunds($fixture['payment']));
        $this->assertSame([], $this->eventTypes($fixture['orderId']));
    }
}
