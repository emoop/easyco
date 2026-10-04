<?php

namespace App\Services;

use App\Services\Exceptions\PendingShippingInvariantBrokenException;
use App\Services\Exceptions\RefundCapExceededException;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Payment;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The hard caps of shipping-domain-design.md §7.2.2, checked on every refund
 * against everything refunded before it. ASSUMES IT RUNS INSIDE THE ORDER-LOCKED
 * TRANSACTION, BEFORE ANY REFUND WRITE (OrderRefunder calls it): the order lock
 * is what makes "refunded so far + this" a number nobody else can change
 * meanwhile. Nothing else may check a cap outside that transaction.
 *
 * A refund counts toward a cap while it is OWED, PAID_OUT, REQUESTED or
 * COMPLETED (PaymentRefundStatus::counting()); a CANCELLED or FAILED refund moved
 * no money and frees its room — including, per line, the room of its own rows.
 *
 *  - per line: goods refunded on that ORIGINAL sale line so far + this entry <=
 *    the line's net paid amount;
 *  - shipping: shipping refunded on the order so far + this <= orders.shipping;
 *  - total: refunded against the payment so far + this total <= the settled
 *    payment's amount;
 *  - deduction: <= the goods + shipping of this refund (so the total is never
 *    negative).
 * A break throws RefundCapExceededException naming the cap and the room left;
 * nothing has been written by then.
 */
final class RefundCapGuard
{
    public function __construct(
        private readonly PaymentRefundRepository $paymentRefunds,
        private readonly OrderRepository $orders,
        private readonly OrderCurrentLinesResolver $currentLines,
    ) {
    }

    /** @throws RefundCapExceededException */
    public function assertDeductionWithinGoodsAndShipping(Money $goods, Money $shipping, Money $deduction): void
    {
        $room = $goods->add($shipping);

        if ($deduction->subtract($room)->isPositive()) {
            throw RefundCapExceededException::forCap(RefundCapExceededException::DEDUCTION, $room);
        }
    }

    /** @throws RefundCapExceededException */
    public function assertWithinCaps(string $orderId, Payment $settledPayment, Money $total, RefundBreakdown $breakdown): void
    {
        $this->assertDeductionWithinGoodsAndShipping($breakdown->goods, $breakdown->shipping, $breakdown->deduction);

        $currency = $total->currency();

        // Per line.
        if ($breakdown->lines !== []) {
            $ids = array_map(static fn ($line): string => $line->saleLineId, $breakdown->lines);
            $netPaid = $this->netPaidByLine($ids, $currency->code());
            $refundedSoFar = $this->paymentRefunds->sumCountingLineAmounts($ids);

            foreach ($breakdown->lines as $line) {
                $limit = $netPaid[$line->saleLineId] ?? throw new InvalidArgumentException(
                    "RefundCapGuard: sale line \"{$line->saleLineId}\" has no net paid amount recorded, so a refund against it cannot be capped."
                );
                $room = $limit->subtract(Money::fromMinorUnits($refundedSoFar[$line->saleLineId] ?? 0, $currency));

                if ($line->amount->subtract($room)->isPositive()) {
                    throw RefundCapExceededException::forCap(RefundCapExceededException::LINE, $room, $line->saleLineId);
                }
            }
        }

        // Shipping — the order is read only when shipping is actually being refunded.
        if ($breakdown->shipping->isPositive()) {
            $order = $this->orders->findById($orderId);

            if ($order === null) {
                throw new InvalidArgumentException("RefundCapGuard: no order exists with id \"{$orderId}\".");
            }

            $shippingRoom = $order->shipping()->subtract(Money::fromMinorUnits($this->paymentRefunds->sumCountingShippingForOrder($orderId), $currency));

            if ($breakdown->shipping->subtract($shippingRoom)->isPositive()) {
                throw RefundCapExceededException::forCap(RefundCapExceededException::SHIPPING, $shippingRoom);
            }
        }

        // Total: against what the settled payment actually holds.
        $alreadyRefunded = $this->paymentRefunds->sumCountingForPayment((string) $settledPayment->id(), $currency->code());
        $totalRoom = $settledPayment->amount()->subtract($alreadyRefunded);

        if ($total->subtract($totalRoom)->isPositive()) {
            throw RefundCapExceededException::forCap(RefundCapExceededException::TOTAL, $totalRoom);
        }
    }

    /**
     * The shipping reduction of a PARTIAL return on a PENDING payment (§7.2.4) is
     * capped at the order's shipping that has not been reduced already. Nothing is
     * refunded there, so no refund record remembers earlier reductions; they are
     * read off the pending payment itself, which every earlier partial return
     * reissued for exactly `previous - goods - reduction`:
     *
     *   reduced so far = order total - goods credited by EARLIER returns - pending amount
     *
     * Goods credited by this return are already written (as REFUND lines) when this
     * runs, so they are taken out of the sum. Orders are not edited once a return is
     * possible, so the order total and the pending amount move only through returns.
     * A negative result is an invariant broken, never "zero": PendingShippingInvariantBrokenException.
     *
     * @throws RefundCapExceededException
     */
    public function assertPendingShippingReduction(string $orderId, Payment $pending, Money $goodsOfThisReturn, Money $reduction): void
    {
        if (! $reduction->isPositive()) {
            return;
        }

        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw new InvalidArgumentException("RefundCapGuard: no order exists with id \"{$orderId}\".");
        }

        $currency = $reduction->currency();
        $lineIds = array_map(static fn (array $entry): string => (string) $entry['row']->id, $this->currentLines->resolveRows($order));
        $creditedInTotal = $lineIds === [] ? 0 : (int) DB::table('operational_sales_sale_lines')
            ->where('type', 'refund')
            ->whereIn('originating_sale_line_id', $lineIds)
            ->sum('actual_refund_amount_minor');
        $creditedEarlier = Money::fromMinorUnits($creditedInTotal, $currency)->subtract($goodsOfThisReturn);

        $reducedSoFar = $order->total()->subtract($creditedEarlier)->subtract($pending->amount());

        // Below zero means an invariant is broken, not "nothing was reduced": fail loudly.
        if ($reducedSoFar->isNegative()) {
            throw new PendingShippingInvariantBrokenException($orderId, (string) $pending->id(), $reducedSoFar->minorValue());
        }

        $room = $order->shipping()->subtract($reducedSoFar);

        if ($reduction->subtract($room)->isPositive()) {
            throw RefundCapExceededException::forCap(RefundCapExceededException::PENDING_SHIPPING, $room);
        }
    }

    /**
     * @param  list<string>  $saleLineIds
     * @return array<string, Money>
     */
    private function netPaidByLine(array $saleLineIds, string $currency): array
    {
        $rows = DB::table('operational_sales_sale_lines')
            ->whereIn('id', $saleLineIds)
            ->get(['id', 'net_paid_amount_minor']);

        $byId = [];

        foreach ($rows as $row) {
            if ($row->net_paid_amount_minor !== null) {
                $byId[(string) $row->id] = Money::fromMinorUnits((int) $row->net_paid_amount_minor, $currency);
            }
        }

        return $byId;
    }
}
