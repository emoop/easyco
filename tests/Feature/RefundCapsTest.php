<?php

namespace Tests\Feature;

use App\Services\Exceptions\RefundCapExceededException;
use App\Services\OrderStatusChanger;
use App\Services\RefundRequest;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R1b (shipping-domain-design.md §7.2.2): the hard caps, cumulative
 * across every refund of the order, checked under the order lock before any
 * refund write — and the whole operation rolled back when one is broken.
 */
class RefundCapsTest extends TestCase
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

    /** @return list<array{originatingSaleLineId: string, quantityReturned: int, restock: bool}> */
    private function returning(string $lineId, int $quantity): array
    {
        return [['originatingSaleLineId' => $lineId, 'quantityReturned' => $quantity, 'restock' => true]];
    }

    private function refusal(callable $operation): RefundCapExceededException
    {
        try {
            $operation();
        } catch (RefundCapExceededException $exception) {
            return $exception;
        }

        $this->fail('the cap should have refused this refund.');
    }

    // --- per line, cumulative across two partial returns ---------------------------------------------

    public function test_the_per_line_cap_holds_across_two_partial_returns(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]]);
        [$line] = $order['saleLineIds'];

        // First return: 2 units, the merchant enters 3000 for them (computed share 2000). 3000 of 5000 used.
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(3000)]));

        // Second return: 2 units at 2500 would make 5500 > the line's 5000 net paid: only 2000 is left.
        $exception = $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(2500)])));

        $this->assertSame(RefundCapExceededException::LINE, $exception->cap());
        $this->assertSame(2000, $exception->room()->minorValue());
        $this->assertStringContainsString('20.00 EUR', $exception->getMessage(), 'the sentence names the room left');

        // Everything of the refused second return rolled back: stock, REFUND lines, refunds, events.
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'only the first return restocked');
        $this->assertCount(1, DB::table('operational_sales_sale_lines')->where('type', 'refund')->get());
        $this->assertCount(1, $this->refundsOf($order['payment']));
        $this->assertSame(['returned', 'refund_owed'], $this->eventTypesOf($order['orderId']));

        // Exactly the room left is accepted.
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(2000)]));
        $this->assertCount(2, $this->refundsOf($order['payment']));
    }

    public function test_the_default_shares_of_consecutive_returns_never_trip_the_per_line_cap(): void
    {
        $order = $this->refundableOrder([['quantity' => 3, 'unit' => 333]]); // 999, shares 333 each
        [$line] = $order['saleLineIds'];

        foreach ([1, 1, 1] as $unused) {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at());
        }

        $sum = array_sum(array_map(static fn ($r): int => $r->amount()->minorValue(), $this->refundsOf($order['payment'])));
        $this->assertSame(999, $sum, 'the telescoping shares add up to exactly the net paid, and the cap lets them through');
    }

    // --- shipping, cumulative ---------------------------------------------------------------------------

    public function test_the_shipping_cap_holds_across_two_partial_returns(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(300)));

        $exception = $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(300))));

        $this->assertSame(RefundCapExceededException::SHIPPING, $exception->cap());
        $this->assertSame(200, $exception->room()->minorValue(), '500 shipped - 300 already refunded');
        $this->assertSame(['returned', 'refund_owed'], $this->eventTypesOf($order['orderId']), 'nothing of the refused return remains');

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(shipping: $this->eur(200)));
        $this->assertCount(2, $this->refundsOf($order['payment']));
    }

    public function test_a_shipping_refund_on_an_order_with_no_shipping_is_refused(): void
    {
        $order = $this->refundableOrder([['quantity' => 2, 'unit' => 1000]]);

        $exception = $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($order['saleLineIds'][0], 1), $this->at(), null, new RefundRequest(shipping: $this->eur(1))));

        $this->assertSame(RefundCapExceededException::SHIPPING, $exception->cap());
        $this->assertSame(0, $exception->room()->minorValue());
    }

    // --- total, cumulative against the payment ----------------------------------------------------------------

    public function test_the_total_cap_is_what_the_settled_payment_actually_holds(): void
    {
        // The order is worth 5000 but the payment that settled holds only 3000.
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], paymentMinor: 3000);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at()); // 2000 of 3000

        $exception = $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at())); // 2000 more

        $this->assertSame(RefundCapExceededException::TOTAL, $exception->cap());
        $this->assertSame(1000, $exception->room()->minorValue());
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]));
    }

    public function test_the_total_cap_counts_every_counting_state_but_not_cancelled_or_failed(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], paymentMinor: 2000);
        [$line] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at()); // OWED 2000: the payment is used up
        $refundId = (int) $this->refundsOf($order['payment'])[0]->id();

        foreach (['paid_out', 'requested', 'completed'] as $state) {
            DB::table('payment_refunds')->where('id', $refundId)->update(['status' => $state, 'paid_out_at' => $state === 'paid_out' ? now() : null]);
            $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at()));
        }

        // A FAILED or CANCELLED refund moved no money: its room is free again.
        foreach (['failed', 'cancelled'] as $state) {
            DB::table('payment_refunds')->where('id', $refundId)->update(['status' => $state, 'paid_out_at' => null]);
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at());
            // put the new refund out of the way again, for the next iteration
            DB::table('payment_refunds')->where('id', '!=', $refundId)->update(['status' => 'cancelled']);
        }

        $this->assertSame(PaymentRefundStatus::CANCELLED, $this->refundsOf($order['payment'])[0]->status());
    }

    public function test_a_failed_or_cancelled_refund_frees_its_per_line_and_shipping_room(): void
    {
        $order = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]], shippingMinor: 400);
        [$line] = $order['saleLineIds'];

        // 2 units entered at 4000? No: the line holds 4000 net paid; take 3000 of it and 300 of the shipping.
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(3000)], shipping: $this->eur(300)));
        $refundId = (int) $this->refundsOf($order['payment'])[0]->id();

        // While it counts, asking for 2000 more on the line is over the cap (1000 left).
        $exception = $this->refusal(fn () => $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(2000)])));
        $this->assertSame(RefundCapExceededException::LINE, $exception->cap());
        $this->assertSame(1000, $exception->room()->minorValue());

        // Cancelled: its rows no longer count, so the full 4000 of the line is free again — and the shipping too.
        DB::table('payment_refunds')->where('id', $refundId)->update(['status' => 'cancelled']);
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at(), null, new RefundRequest(enteredGoodsByLine: [$line => $this->eur(3500)], shipping: $this->eur(400)));

        $this->assertCount(2, $this->refundsOf($order['payment']));
    }

    // --- the deduction ---------------------------------------------------------------------------------------

    public function test_a_deduction_larger_than_goods_plus_shipping_is_refused_by_name_and_nothing_is_written(): void
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500);
        [$line] = $order['saleLineIds'];

        // goods 2000 + shipping 300 = 2300; a deduction of 2301 is one cent too many.
        $exception = $this->refusal(fn () => $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning($line, 2),
            $this->at(),
            null,
            new RefundRequest(shipping: $this->eur(300), deduction: $this->eur(2301), deductionReason: 'too much'),
        ));

        $this->assertSame(RefundCapExceededException::DEDUCTION, $exception->cap());
        $this->assertSame(2300, $exception->room()->minorValue());
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]), 'the goods half rolled back');
        $this->assertSame([], $this->refundsOf($order['payment']));
        $this->assertSame([], $this->eventTypesOf($order['orderId']));

        // Exactly goods + shipping is the largest legal deduction (a total of 0 records no refund).
        $this->changer()->recordReturn($order['orderId'], $this->returning($line, 2), $this->at(), null, new RefundRequest(shipping: $this->eur(300), deduction: $this->eur(2300), deductionReason: 'all retained'));
        $this->assertSame([], $this->refundsOf($order['payment']), 'a total of 0 creates no refund');
        $this->assertSame(12, $this->stockOf($order['variationIds'][0]), 'but the goods moved');
    }

    // --- fully discounted lines ----------------------------------------------------------------------------------

    public function test_a_fully_discounted_line_is_returnable_with_no_money_and_the_goods_restock(): void
    {
        // A gift (1 x 500, 100% discount => net paid 0) next to a paid line (2 x 1000).
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 500, 'discount' => 500], ['quantity' => 2, 'unit' => 1000]]);
        [$gift, $paid] = $order['saleLineIds'];

        $this->changer()->recordReturn($order['orderId'], $this->returning($gift, 1), $this->at());

        $row = DB::table('operational_sales_sale_lines')->where('type', 'refund')->where('originating_sale_line_id', $gift)->first();
        $this->assertSame(0, (int) $row->amount_minor);
        $this->assertSame(0, (int) $row->default_refund_amount_minor, 'a computed share of exactly 0');
        $this->assertSame(0, (int) $row->actual_refund_amount_minor);
        $this->assertSame(1, (int) $row->quantity_returned);
        $this->assertSame(11, $this->stockOf($order['variationIds'][0]), 'the gift went back on the shelf');
        $this->assertSame([], $this->refundsOf($order['payment']), 'no money moves, so no refund exists');
        $this->assertSame(['returned'], $this->eventTypesOf($order['orderId']));

        // Returning the gift together with a paid line refunds only the paid line's money.
        $order2 = $this->refundableOrder([['quantity' => 1, 'unit' => 500, 'discount' => 500], ['quantity' => 2, 'unit' => 1000]]);
        $this->changer()->recordReturn($order2['orderId'], [
            ['originatingSaleLineId' => $order2['saleLineIds'][0], 'quantityReturned' => 1, 'restock' => true],
            ['originatingSaleLineId' => $order2['saleLineIds'][1], 'quantityReturned' => 2, 'restock' => true],
        ], $this->at());

        $refund = $this->refundsOf($order2['payment'])[0];
        $this->assertSame(2000, $refund->amount()->minorValue());
        $this->assertSame([0, 2000], array_map(static fn ($l): int => $l->amount->minorValue(), $refund->breakdown()->lines));
    }

    public function test_a_computed_share_of_zero_is_legal_so_every_unit_of_a_one_cent_line_is_returnable(): void
    {
        // 1 cent paid over 3 units (gross 3, 2 off): the cumulative shares are 0, 0, 1 — and every unit must be returnable.
        $order = $this->refundableOrder([['quantity' => 3, 'unit' => 1, 'discount' => 2]]);
        [$line] = $order['saleLineIds'];

        foreach ([1, 2, 3] as $unit) {
            $this->changer()->recordReturn($order['orderId'], $this->returning($line, 1), $this->at());
        }

        $shares = DB::table('operational_sales_sale_lines')->where('type', 'refund')->orderBy('id')->pluck('default_refund_amount_minor')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([0, 0, 1], $shares);
        $this->assertSame(13, $this->stockOf($order['variationIds'][0]), 'all three units went back on the shelf');
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order['orderId'])->value('status'), 'a shipped order that is returned in full is cancelled');

        $refunds = $this->refundsOf($order['payment']);
        $this->assertCount(1, $refunds, 'only the third return moved money: the two zero shares created no refund');
        $this->assertSame(1, array_sum(array_map(static fn ($r): int => $r->amount()->minorValue(), $refunds)), 'the money totals 1 cent across the three returns');
    }

    public function test_a_negative_share_is_always_refused_by_the_domain(): void
    {
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 500]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be negative');

        \EasyCo\OperationalSales\SaleLine::createRefund(
            originatingLine: $this->originLine($order['saleLineIds'][0]), transactionId: '', quantityReturned: 1, defaultRefundAmount: $this->eur(-1),
            returnedBy: null, returnedByName: null, returnReason: null, displayPriceAtReturn: null,
            recordedAt: $this->at(), effectiveAt: $this->at(),
        );
    }

    /**
     * R1c: the per-line cap reads the net paid of the line the return NAMES, and a return can only name a CURRENT line
     * (OrderCurrentLinesResolver) — after an edit that is the replacement line, whose own net paid is what was really paid.
     */
    public function test_the_per_line_cap_reads_the_post_edit_net_paid_of_a_line_edited_while_unpaid(): void
    {
        // A: 3 x 10.00, B: 1 x 20.00, placed and unpaid; shipping 0.
        $order = $this->refundableOrder([['quantity' => 3, 'unit' => 1000], ['quantity' => 1, 'unit' => 2000]], status: \EasyCo\Order\Enums\OrderStatus::PLACED, settle: false);
        $pending = \EasyCo\Payment\Payment::create($order['orderId'], 'cash_on_delivery', $this->eur(5000), \EasyCo\Payment\Enums\PaymentStatus::PENDING);
        $pending->recordAttemptResult(\EasyCo\Payment\Enums\PaymentStatus::PENDING, null, null, new \DateTimeImmutable('2026-09-28 09:00:00'));
        app(\EasyCo\Payment\Contracts\PaymentRepository::class)->save($pending);
        [$lineA] = $order['saleLineIds'];

        // Edit while pending: A 3 -> 2 (reverses the old line, writes a replacement; the pending payment is reissued for 4000).
        $origin = $this->originLine($lineA);
        app(\App\Services\OrderEditor::class)->apply(
            orderId: $order['orderId'],
            expectedRevision: 0,
            lineChanges: [['change' => 'change_quantity', 'originatingLine' => $origin, 'quantity' => 2]],
            delivery: null,
            promotionCode: \App\Services\OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: null,
            occurredAt: $this->at(),
        );

        $replacement = DB::table('operational_sales_sale_lines')->where('type', 'sale')->where('priceable_id', $order['variationIds'][0])->where('id', '!=', $lineA)->first();
        $this->assertNotNull($replacement, 'the edit wrote a replacement line for A');
        $this->assertSame(2000, (int) $replacement->net_paid_amount_minor, 'what was really paid for A after the edit: 2 x 10.00');
        $this->assertSame(3000, (int) DB::table('operational_sales_sale_lines')->where('id', $lineA)->value('net_paid_amount_minor'), 'the pre-edit line still says 30.00');

        // The customer pays the reissued 40.00 and the order ships.
        $payments = app(\EasyCo\Payment\Contracts\PaymentRepository::class);
        $current = collect($payments->findByOrderId($order['orderId']))->first(fn ($p) => ! $p->isVoided());
        $this->assertSame(4000, $current->amount()->minorValue());
        $current->confirm(new \DateTimeImmutable('2026-09-28 10:00:00'));
        $payments->save($current);
        DB::table('orders')->where('id', $order['orderId'])->update(['status' => 'shipped']);

        // Return A in full with 25.00 entered: above the post-edit 20.00, below the pre-edit 30.00 and within the 40.00 payment.
        $exception = $this->refusal(fn () => $this->changer()->recordReturn(
            $order['orderId'],
            $this->returning((string) $replacement->id, 2),
            $this->at(),
            null,
            new RefundRequest(enteredGoodsByLine: [(string) $replacement->id => $this->eur(2500)]),
        ));

        $this->assertSame(RefundCapExceededException::LINE, $exception->cap());
        $this->assertSame(2000, $exception->room()->minorValue(), 'the room is the post-edit net paid, not the pre-edit 30.00');
        $this->assertSame([], $this->refundsOf($current));

        // Entering exactly what was paid is accepted.
        $this->changer()->recordReturn($order['orderId'], $this->returning((string) $replacement->id, 2), $this->at(), null, new RefundRequest(enteredGoodsByLine: [(string) $replacement->id => $this->eur(2000)]));
        $this->assertSame(2000, $this->refundsOf($current)[0]->amount()->minorValue());
    }

    private function originLine(string $id): \EasyCo\OperationalSales\SaleLine
    {
        $transactionId = DB::table('operational_sales_sale_lines')->where('id', $id)->value('transaction_id');

        foreach (app(\EasyCo\OperationalSales\Contracts\TransactionRepository::class)->findByIdWithSaleLines((string) $transactionId)->saleLines() as $line) {
            if ((string) $line->id() === $id) {
                return $line;
            }
        }

        $this->fail('origin line not found');
    }
}
