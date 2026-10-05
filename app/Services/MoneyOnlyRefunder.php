<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\MoneyOnlyRefundRefusedException;
use App\Services\Exceptions\OperationKeyReusedException;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The money-only refund ("refund without return", refunds R3 part 1,
 * shipping-domain-design.md §7.2.11): goodwill and corrections. A refund with goods 0 —
 * a shipping refund and/or an adjustment, a mandatory reason, a payout channel. It
 * writes ONE PaymentRefund (OWED for the offline methods) and ONE history event; no
 * SaleLine, no stock movement, no status change.
 *
 * The same machinery as a return's refund, nothing parallel: one DB::transaction with THE
 * ORDER ROW LOCKED FIRST (the single serialization point, §7.2.3); the replay of an
 * operation key BEFORE anything else is judged (the same key with the same contents
 * returns the first result and writes nothing, fires no hook; other contents:
 * OperationKeyReusedException; the UNIQUE (order_id, operation_key) is the backstop);
 * then OrderRefunder::refundMoneyOnly() — settled payment only, the permission of the
 * payout channel, the shipping and total caps, the offline adapter. The key and the
 * payload hash sit on the one `refund_owed` event, which carries the refund id.
 *
 * REFUSED by name before anything is written (MoneyOnlyRefundRefusedException): no reason,
 * a deduction, nothing to refund (shipping + adjustment = 0), a pending payment or none.
 * The shape checks run before the lock; they depend only on the request, so a repeat is
 * refused identically.
 *
 * HOOK, after the commit, never for a replay or a refused call: `order.refund_recorded`
 * (and `order.refund_paid_out` if an online method ever records it COMPLETED).
 * NO order status is required: goodwill on a delivered or even cancelled order is the
 * merchant's call; the settled payment is the only guard.
 */
final class MoneyOnlyRefunder
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderRefunder $refunder,
        private readonly OrderEventRecorder $events,
        private readonly PaymentRefundRepository $paymentRefunds,
    ) {
    }

    /**
     * @throws MoneyOnlyRefundRefusedException reason_required, deduction_not_allowed, nothing_to_refund, payment_not_settled
     * @throws \App\Services\Exceptions\RefundCapExceededException the shipping or the total cap
     * @throws \App\Services\Exceptions\RefundPermissionDeniedException without the permission of the payout channel
     * @throws OperationKeyReusedException the same key with other contents
     * @throws InvalidArgumentException an empty or unknown order id
     */
    public function record(string $orderId, MoneyOnlyRefundRequest $request, DateTimeImmutable $occurredAt): OrderReturnResult
    {
        if (trim($orderId) === '') {
            throw new InvalidArgumentException('MoneyOnlyRefunder: orderId must not be empty.');
        }

        if (trim($request->reason) === '') {
            throw new MoneyOnlyRefundRefusedException(MoneyOnlyRefundRefusedException::REASON_REQUIRED);
        }

        if ($request->deduction !== null && $request->deduction->isPositive()) {
            throw new MoneyOnlyRefundRefusedException(MoneyOnlyRefundRefusedException::DEDUCTION_NOT_ALLOWED);
        }

        if (! $request->shipping->add($request->adjustment)->isPositive()) {
            throw new MoneyOnlyRefundRefusedException(MoneyOnlyRefundRefusedException::NOTHING_TO_REFUND);
        }

        return $this->perform($orderId, $request, $occurredAt, false);
    }

    private function perform(string $orderId, MoneyOnlyRefundRequest $request, DateTimeImmutable $occurredAt, bool $afterDuplicateKey): OrderReturnResult
    {
        $hash = $request->operationKey !== null ? RefundOperationFingerprint::forMoneyOnly($request) : null;

        try {
            $result = DB::transaction(function () use ($orderId, $request, $occurredAt, $hash): array {
                $order = $this->orders->findByIdForUpdate($orderId)
                    ?? throw new InvalidArgumentException("MoneyOnlyRefunder: no order exists with id \"{$orderId}\".");

                if ($request->operationKey !== null) {
                    $prior = DB::table('order_events')->where('order_id', $orderId)->where('operation_key', $request->operationKey)->first();

                    if ($prior !== null) {
                        if (! hash_equals((string) $prior->operation_payload_hash, (string) $hash)) {
                            throw new OperationKeyReusedException();
                        }

                        return ['replayed' => true, 'refundId' => $prior->payment_refund_id === null ? null : (string) $prior->payment_refund_id];
                    }
                }

                $zero = Money::zero($order->currency());
                $breakdown = new RefundBreakdown(
                    goods: $zero,
                    shipping: $request->shipping,
                    adjustment: $request->adjustment,
                    deduction: $zero,
                );

                $outcome = $this->refunder->refundMoneyOnly($orderId, $breakdown, trim($request->reason), $request->channel);
                $refund = $outcome->refund();

                $this->events->record(
                    orderId: $orderId,
                    type: $refund->status() === PaymentRefundStatus::OWED ? OrderEventType::REFUND_OWED : OrderEventType::REFUNDED,
                    fromStatus: null,
                    toStatus: null,
                    reason: trim($request->reason),
                    transactionId: null,
                    occurredAt: $occurredAt,
                    operationKey: $request->operationKey,
                    operationPayloadHash: $hash,
                    paymentRefundId: $refund->id(),
                );

                return ['replayed' => false, 'order' => $order, 'refund' => $refund];
            });
        } catch (QueryException $exception) {
            // The UNIQUE (order_id, operation_key) is the backstop behind the lock: a concurrent
            // submission got its row in first, so this call is a repeat — run it once more and it replays.
            if ($request->operationKey !== null && ! $afterDuplicateKey && self::isDuplicateKey($exception)) {
                return $this->perform($orderId, $request, $occurredAt, true);
            }

            throw $exception;
        }

        if ($result['replayed']) {
            return OrderReturnResult::replayed($result['refundId'] !== null ? $this->paymentRefunds->findById($result['refundId']) : null);
        }

        Hook::fire('order.refund_recorded', $result['order'], $result['refund']);

        if ($result['refund']->status() === PaymentRefundStatus::COMPLETED) {
            Hook::fire('order.refund_paid_out', $result['order'], $result['refund']);
        }

        return OrderReturnResult::performed($result['refund']);
    }

    /** SQLSTATE 23000 + the driver's duplicate-key code (MySQL 1062, SQLite 19) — never the message (CLAUDE.md rule 3). */
    private static function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true);
    }
}
