<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Services\Exceptions\PendingPaymentRefundRuleException;
use App\Services\Exceptions\RefundCapExceededException;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use stdClass;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1c (shipping-domain-design.md §7.2.4): what a cancellation or a return
 * does to a PENDING payment. Nothing was paid, so no refund exists; the payment is
 * voided, and reissued for what is still owed ONLY after a partial return.
 */
class RefundPendingPaymentRulesTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function changer(): OrderStatusChanger
    {
        return app(OrderStatusChanger::class);
    }

    private function returning(string $lineId, int $quantity): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => $quantity, 'restock' => true]];
    }

    /** An unpaid, shipped order: one line of $quantity x 10.00 (less $discount), shipping $shippingMinor, one pending answered payment for the whole total. */
    private function unpaidOrder(int $shippingMinor = 300, string $method = 'cash_on_delivery', int $quantity = 2, int $discount = 0): array
    {
        $order = $this->refundableOrder([['quantity' => $quantity, 'unit' => 1000, 'discount' => $discount]], shippingMinor: $shippingMinor, method: $method, settle: false);
        $payment = Payment::create($order['orderId'], $method, $this->eur($quantity * 1000 - $discount + $shippingMinor), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new \DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $order;
    }

    /** @return list<array{amount: int, voided: bool}> */
    private function paymentsOf(string $orderId): array
    {
        return DB::table('payments')->where('order_id', $orderId)->orderBy('id')->get()
            ->map(fn ($p) => ['amount' => (int) $p->amount_minor, 'voided' => $p->voided_at !== null])->all();
    }

    private function lastPaymentAmount(string $orderId): int
    {
        $payments = $this->paymentsOf($orderId);

        return $payments[count($payments) - 1]['amount'];
    }

    // --- H1 -----------------------------------------------------------------------------------------------------------------

    public function test_h1_a_full_cancel_of_an_unpaid_order_voids_the_payment_and_reissues_nothing(): void
    {
        $order = $this->unpaidOrder(300);

        app(OrderStatusChanger::class)->cancel($order['orderId'], $this->at(), null, [$order['saleLineIds'][0] => true]);

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
        $this->assertSame(
            [['amount' => 2300, 'voided' => true]],
            $this->paymentsOf($order['orderId']),
            'a cancelled order owes nothing: the pending payment is voided and NO payment for the shipping is left behind',
        );
    }

    public function test_a_full_return_of_an_unpaid_order_also_voids_and_never_reissues(): void
    {
        $order = $this->unpaidOrder(300);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 2), $this->at());

        $this->assertSame([['amount' => 2300, 'voided' => true]], $this->paymentsOf($order['orderId']));
        $this->assertSame(['returned', 'status_changed', 'payment_voided'], $this->eventTypesOf($order['orderId']));
    }

    public function test_a_full_cancel_of_an_unpaid_order_whose_goods_were_all_free_still_voids_the_payment(): void
    {
        // Goods 2 x 10.00 fully discounted: the computed total is 0, only the shipping is owed — and a cancelled order owes nothing.
        $order = $this->unpaidOrder(300, discount: 2000);

        $this->changer()->cancel($order['orderId'], $this->at());

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
        $this->assertSame([['amount' => 300, 'voided' => true]], $this->paymentsOf($order['orderId']));
    }

    // --- partial returns ----------------------------------------------------------------------------------------------------

    public function test_a_partial_return_reissues_exactly_pending_minus_goods(): void
    {
        $order = $this->unpaidOrder(300);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at());

        $this->assertSame([['amount' => 2300, 'voided' => true], ['amount' => 1300, 'voided' => false]], $this->paymentsOf($order['orderId']), '2300 - 1000 goods - 0 shipping reduction');
        $this->assertSame('shipped', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_a_partial_return_with_a_shipping_reduction_reissues_pending_minus_goods_minus_the_reduction(): void
    {
        $order = $this->unpaidOrder(300);

        $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(shipping: $this->eur(100)));

        $this->assertSame([['amount' => 2300, 'voided' => true], ['amount' => 1200, 'voided' => false]], $this->paymentsOf($order['orderId']));
        $this->assertSame(0, DB::table('payment_refunds')->count(), 'nothing was paid, so no refund record exists');
    }

    public function test_the_shipping_reduction_is_capped_at_the_shipping_not_already_reduced(): void
    {
        $order = $this->unpaidOrder(300, quantity: 4);
        [$line] = $order['saleLineIds'];

        // One reduction larger than the whole shipping.
        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(301)));
            $this->fail('a reduction above the order shipping must be refused.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::PENDING_SHIPPING, $exception->cap());
            $this->assertSame(300, $exception->room()->minorValue());
            $this->assertStringContainsString('3.00 EUR', $exception->getMessage());
        }
        $this->assertSame([['amount' => 4300, 'voided' => false]], $this->paymentsOf($order['orderId']), 'refused: nothing written, nothing reissued');
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back');

        // 200 now, then 200 more: only 100 of the shipping is left.
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(200)));
        $this->assertSame(3100, $this->lastPaymentAmount($order['orderId']), '4300 - 1000 - 200');

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(200)));
            $this->fail('only 100 of the shipping has not been reduced yet.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::PENDING_SHIPPING, $exception->cap());
            $this->assertSame(100, $exception->room()->minorValue());
        }

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(100)));
        $this->assertSame(2000, $this->lastPaymentAmount($order['orderId']), '3100 - 1000 - the last 100 of the shipping');
    }

    public function test_a_derived_reduced_so_far_below_zero_is_a_broken_invariant_and_fails_loudly(): void
    {
        $order = $this->unpaidOrder(300, quantity: 4);
        // The pending payment now holds MORE than the order total (4300) less nothing credited: no return can produce that.
        DB::table('payments')->where('order_id', $order['orderId'])->update(['amount_minor' => 5000]);

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(shipping: $this->eur(100)));
            $this->fail('a negative derived figure must not be clamped to zero');
        } catch (\App\Services\Exceptions\PendingShippingInvariantBrokenException $e) {
            $this->assertSame(-700, $e->reducedSoFarMinor, 'order total 4300 - 0 credited - pending 5000');
            $this->assertStringContainsString('below zero', $e->getMessage());
        }

        $this->assertSame([['amount' => 5000, 'voided' => false]], $this->paymentsOf($order['orderId']), 'nothing was reissued');
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back');
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
    }

    // --- refusals -----------------------------------------------------------------------------------------------------------

    public function test_a_deduction_is_refused_on_a_pending_payment_and_nothing_is_written(): void
    {
        $order = $this->unpaidOrder(300);

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(deduction: $this->eur(100), deductionReason: 'damaged'));
            $this->fail('there is nothing to deduct from on an unpaid order.');
        } catch (PendingPaymentRefundRuleException $exception) {
            $this->assertSame(PendingPaymentRefundRuleException::DEDUCTION, $exception->rule());
            $this->assertStringContainsString('has not been paid', $exception->getMessage());
        }

        $this->assertSame([['amount' => 2300, 'voided' => false]], $this->paymentsOf($order['orderId']));
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
    }

    public function test_an_entered_goods_amount_other_than_the_computed_share_is_refused_on_a_pending_payment(): void
    {
        $order = $this->unpaidOrder(300);
        [$line] = $order['saleLineIds'];

        try {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(900)]));
            $this->fail('goods are read-only on an unpaid order.');
        } catch (PendingPaymentRefundRuleException $exception) {
            $this->assertSame(PendingPaymentRefundRuleException::GOODS, $exception->rule());
        }

        $this->assertSame([['amount' => 2300, 'voided' => false]], $this->paymentsOf($order['orderId']));
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));

        // Entering exactly the computed share is no deviation.
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(1000)]));
        $this->assertSame(1300, $this->lastPaymentAmount($order['orderId']));
    }

    public function test_the_pending_refusals_are_translated_into_bulgarian(): void
    {
        app()->setLocale('bg');

        $this->assertStringContainsString('не е платена', PendingPaymentRefundRuleException::deductionNotAllowed()->getMessage());
        $this->assertStringContainsString('не може да се променя', PendingPaymentRefundRuleException::goodsAmountIsReadOnly()->getMessage());
        $this->assertStringContainsString('3.00 EUR', RefundCapExceededException::forCap(RefundCapExceededException::PENDING_SHIPPING, $this->eur(300))->getMessage());
    }

    public function test_the_admin_action_catches_the_pending_refusals_instead_of_letting_them_escape(): void
    {
        $method = new ReflectionMethod(OrderResource::class, 'runOrderAction');
        $method->setAccessible(true);

        foreach ([PendingPaymentRefundRuleException::deductionNotAllowed(), RefundCapExceededException::forCap(RefundCapExceededException::PENDING_SHIPPING, $this->eur(100))] as $exception) {
            // Would throw out of the call if the action did not map it to a notice.
            $method->invoke(null, new OrderModel(), new stdClass(), static function () use ($exception): void {
                throw $exception;
            }, 'unused');
        }

        $this->assertTrue(true);
    }

    // --- H2: a full cancel of a SETTLED payment with an entered shipping refund -----------------------------------------------

    public function test_h2_a_full_cancel_on_a_settled_payment_refunds_goods_plus_the_entered_shipping(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], shippingMinor: 300);

        $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(shipping: $this->eur(300)));

        $refunds = $this->refundsOf($order['payment']);
        $this->assertCount(1, $refunds);
        $this->assertSame(2300, $refunds[0]->amount()->minorValue(), 'goods 2000 + shipping 300');
        $this->assertSame(2000, $refunds[0]->breakdown()->goods->minorValue());
        $this->assertSame(300, $refunds[0]->breakdown()->shipping->minorValue());
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_h2_the_entered_shipping_refund_of_a_full_cancel_is_capped_at_the_order_shipping(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]], shippingMinor: 300);

        try {
            $this->changer()->cancel($order['orderId'], $this->at(), null, [], new RefundRequest(shipping: $this->eur(301)));
            $this->fail('the shipping refund is capped at the shipping that was paid.');
        } catch (RefundCapExceededException $exception) {
            $this->assertSame(RefundCapExceededException::SHIPPING, $exception->cap());
            $this->assertSame(300, $exception->room()->minorValue());
        }

        $this->assertSame([], $this->refundsOf($order['payment']));
        $this->assertSame('shipped', DB::table('orders')->where('id', $order['orderId'])->value('status'), 'rolled back whole');
    }
}
