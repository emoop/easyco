<?php

namespace App\Services;

use App\Services\Exceptions\PendingShippingInvariantBrokenException;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * The refund dialogs' ONE reader (refunds R3 part 2): what kind of payment an order has, the rooms
 * (line, shipping, total — from RefundCapGuard, the service's own source), the payout channels the
 * acting staff member may use, and the computed share of a returned quantity (from
 * CumulativeRefundShareCalculator, the very calculator ReturnGoodsRecorder uses).
 *
 * It holds NO cap arithmetic and decides nothing: a refusal is the service's. The payment
 * classification is OrderRefunder's own (settledOf / pendingEligibleOf).
 */
final class RefundFormReader
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly RefundCapGuard $caps,
        private readonly RefundPermissionPolicy $permissions,
        private readonly CumulativeRefundShareCalculator $shares,
    ) {
    }

    /**
     * @param  list<string>  $saleLineIds  the lines the dialog shows (their rooms are read; settled only)
     * @param  bool  $withPendingShippingRoom  a pending payment's shipping-reduction room costs several reads and only a RETURN can use it (a cancel voids everything), so a cancel passes false
     */
    public function forOrder(string $orderId, array $saleLineIds = [], bool $withPendingShippingRoom = true): RefundFormContext
    {
        $order = $this->orders->findById($orderId)
            ?? throw new InvalidArgumentException("RefundFormReader: no order exists with id \"{$orderId}\".");

        $currency = $order->currency()->code();
        $zero = Money::zero($currency);
        $payments = $this->payments->findByOrderId($orderId);
        $settled = OrderRefunder::settledOf($payments);
        $pending = OrderRefunder::pendingEligibleOf($payments);

        if (count($settled) === 1) {
            $payment = $settled[0];
            $rooms = [];

            foreach ($this->caps->lineRooms($saleLineIds, $currency) as $id => $room) {
                $rooms[$id] = $room->isNegative() ? $zero : $room;
            }

            $channels = array_values(array_filter(
                [RefundChannel::CASH, RefundChannel::BANK],
                fn (RefundChannel $channel): bool => $this->permissions->mayRecord($channel),
            ));

            $default = RefundChannel::defaultForMethod($payment->method());

            return new RefundFormContext(
                mode: RefundFormContext::SETTLED,
                currency: $currency,
                orderShipping: $order->shipping(),
                shippingRoom: $this->clamp($this->caps->shippingRoom($orderId, $currency), $zero),
                totalRoom: $this->clamp($this->caps->totalRoom($payment), $zero),
                lineRooms: $rooms,
                channels: $channels,
                defaultChannel: in_array($default, $channels, true) ? $default : ($channels[0] ?? null),
            );
        }

        if (count($settled) === 0 && count($pending) === 1) {
            try {
                $room = $withPendingShippingRoom ? $this->clamp($this->caps->pendingShippingRoom($orderId, $pending[0], $zero), $zero) : null;
            } catch (PendingShippingInvariantBrokenException) {
                // Not something to fix in a form; the service refuses it with its own notice if it is tried.
                $room = null;
            }

            return new RefundFormContext(RefundFormContext::PENDING, $currency, $order->shipping(), $room, null, [], [], null);
        }

        return new RefundFormContext(RefundFormContext::NONE, $currency, $order->shipping(), null, null, [], [], null);
    }

    /**
     * The computed share of returning $quantity more units of a line — the figure the service
     * prefills and records as `defaultRefundAmount`. Null when it cannot be computed (a legacy line
     * with no net paid amount, or a quantity that is not within what remains).
     */
    public function computedShare(OrderAdminSaleLineView $line, int $quantity): ?Money
    {
        if ($line->netPaidAmount === null || $quantity <= 0 || $quantity > $line->remainingReturnable) {
            return null;
        }

        return $this->shares->shareFor(
            $line->netPaidAmount,
            $line->quantity,
            $line->quantity - $line->remainingReturnable,
            $quantity,
        );
    }

    private function clamp(Money $amount, Money $zero): Money
    {
        return $amount->isNegative() ? $zero : $amount;
    }
}
