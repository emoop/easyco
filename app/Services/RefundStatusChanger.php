<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\RefundTransitionRefusedException;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Exceptions\InvalidRefundTransitionException;
use EasyCo\Payment\PaymentRefund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The two transitions out of an OWED refund (refunds R2a, shipping-domain-design.md
 * §7.2.5, §7.2.16): OWED -> PAID_OUT and OWED -> CANCELLED. No screen yet (R2b).
 *
 * BOTH run inside ONE transaction with THE ORDER ROW LOCKED FIRST — the single
 * serialization point of everything that moves an order's money (§7.2.3) — and
 * both check, in this order: the permission of the refund's own payout channel
 * (REFUND_CASH / REFUND_BANK through RefundPermissionPolicy, exactly as recording
 * it needed; no acting staff member is a refusal), the idempotent replay, then the
 * state. Nothing external is called inside the transaction.
 *
 * IDEMPOTENT through the existing `operation_key` mechanism (§7.2.3): the key and
 * a payload hash go on the ONE event the transition writes; the same key with the
 * same contents returns the first result and writes nothing (no second history
 * entry, no hook); the same key with other contents is OperationKeyReusedException.
 * The replay is looked up BEFORE the state check, because a finished transition has
 * moved the refund out of OWED. Without a key there is no idempotency: a repeat on a
 * refund that is no longer OWED is refused by name (not_owed).
 *
 * PAYING OUT confirms exactly the owed total — no amount is entered — with a payout
 * date that is not in the future (relative to $occurredAt, the caller's clock), a
 * bank reference (required for channel bank, optional for cash), an optional note,
 * and the staff member.
 *
 * CANCELLING needs a reason and the staff member, and appends the STORNO: one
 * REFUND_REVERSAL SaleLine per refund line, equal to that line's entered amount and
 * linked to the REFUND line it undoes, in a new Transaction the cancellation event
 * points at. The ledger is the source of truth for money refunded per product line,
 * so the REFUND lines themselves are never touched; and no stock changes — the goods
 * stay returned and restocked. The refund's room in every cap is freed by its state
 * alone (CANCELLED does not count). A PAID_OUT refund — a legacy one included — can
 * never be cancelled.
 *
 * HOOKS, after commit, only for a transition that really happened (never a replay):
 * `order.refund_paid_out` and `order.refund_cancelled`, each (Order, PaymentRefund).
 */
final class RefundStatusChanger
{
    public function __construct(
        private readonly PaymentRefundRepository $refunds,
        private readonly OrderRepository $orders,
        private readonly OrderEventRecorder $events,
        private readonly RefundPermissionPolicy $permissions,
        private readonly PanelStaffActor $staffActor,
        private readonly TransactionRepository $transactions,
    ) {
    }

    /**
     * @throws RefundTransitionRefusedException not_owed, payout_in_future, bank_reference_required, refund_not_found, actor_required
     * @throws \App\Services\Exceptions\RefundPermissionDeniedException without the permission of the refund's channel
     * @throws OperationKeyReusedException the same key with other contents
     */
    public function markPaidOut(string $refundId, DateTimeImmutable $paidOutAt, ?string $reference, ?string $note, DateTimeImmutable $occurredAt, ?string $operationKey = null): OrderReturnResult
    {
        $hash = $operationKey !== null ? RefundOperationFingerprint::forPayout($refundId, $paidOutAt, $reference, $note) : null;

        return $this->run($refundId, $operationKey, $hash, function (PaymentRefund $refund, Order $order) use ($paidOutAt, $reference, $note, $occurredAt, $operationKey, $hash): void {
            $staff = $this->staffActor->current();

            try {
                $refund->markPaidOut($paidOutAt, $reference, $note, (string) $staff?->id, $occurredAt);
            } catch (InvalidRefundTransitionException $e) {
                throw RefundTransitionRefusedException::fromDomain($e);
            }

            $this->refunds->save($refund);

            $this->events->record(
                orderId: $order->id(),
                type: OrderEventType::REFUND_PAID_OUT,
                fromStatus: null,
                toStatus: null,
                reason: $note,
                transactionId: null,
                occurredAt: $occurredAt,
                operationKey: $operationKey,
                operationPayloadHash: $hash,
                paymentRefundId: $refund->id(),
            );
        }, 'order.refund_paid_out');
    }

    /**
     * @throws RefundTransitionRefusedException not_owed, reason_required, refund_lines_unlinked, storno_mismatch, refund_not_found, actor_required
     * @throws \App\Services\Exceptions\RefundPermissionDeniedException without the permission of the refund's channel
     * @throws OperationKeyReusedException the same key with other contents
     */
    public function cancelOwed(string $refundId, string $reason, DateTimeImmutable $occurredAt, ?string $operationKey = null): OrderReturnResult
    {
        $hash = $operationKey !== null ? RefundOperationFingerprint::forCancellation($refundId, $reason) : null;

        return $this->run($refundId, $operationKey, $hash, function (PaymentRefund $refund, Order $order) use ($reason, $occurredAt, $operationKey, $hash): void {
            $staff = $this->staffActor->current();

            // The state and the reason are checked by the domain BEFORE anything is written.
            try {
                $refund->cancelOwed($occurredAt, $reason, (string) $staff?->id);
            } catch (InvalidRefundTransitionException $e) {
                throw RefundTransitionRefusedException::fromDomain($e);
            }

            $stornoTransactionId = $this->appendStorno($refund, $staff?->id !== null ? (string) $staff->id : null, $staff?->name, $reason, $occurredAt);

            $this->refunds->save($refund);

            $this->events->record(
                orderId: $order->id(),
                type: OrderEventType::REFUND_CANCELLED,
                fromStatus: null,
                toStatus: null,
                reason: $reason,
                transactionId: $stornoTransactionId,
                occurredAt: $occurredAt,
                operationKey: $operationKey,
                operationPayloadHash: $hash,
                paymentRefundId: $refund->id(),
            );
        }, 'order.refund_cancelled');
    }

    /**
     * The shared shell: lock the order, re-read the refund under the lock, check the
     * permission, replay, then run $transition (which changes the refund and writes
     * its one event) — all in one transaction; the hook fires after the commit.
     *
     * @param  callable(PaymentRefund, Order): void  $transition
     */
    private function run(string $refundId, ?string $operationKey, ?string $hash, callable $transition, string $hook, bool $afterDuplicateKey = false): OrderReturnResult
    {
        try {
            $result = DB::transaction(function () use ($refundId, $operationKey, $hash, $transition): array {
                $unlocked = $this->refunds->findById($refundId) ?? throw new RefundTransitionRefusedException(RefundTransitionRefusedException::REFUND_NOT_FOUND);

                // THE ORDER LOCK FIRST (the one serialization point), then the refund is read AGAIN
                // under it: the first read only told us which order to lock.
                $order = $this->orders->findByIdForUpdate($unlocked->orderId())
                    ?? throw new InvalidArgumentException("RefundStatusChanger: refund \"{$refundId}\" belongs to an order that does not exist.");
                $refund = $this->refunds->findById($refundId) ?? throw new RefundTransitionRefusedException(RefundTransitionRefusedException::REFUND_NOT_FOUND);

                $this->permissions->assertMayRecord($refund->channel());

                if ($operationKey !== null) {
                    $prior = DB::table('order_events')->where('order_id', $order->id())->where('operation_key', $operationKey)->first();

                    if ($prior !== null) {
                        if (! hash_equals((string) $prior->operation_payload_hash, (string) $hash)) {
                            throw new OperationKeyReusedException();
                        }

                        return ['replayed' => true, 'refund' => $refund];
                    }
                }

                $transition($refund, $order);

                return ['replayed' => false, 'refund' => $refund, 'order' => $order];
            });
        } catch (QueryException $exception) {
            // The UNIQUE (order_id, operation_key) is the backstop behind the lock: a concurrent
            // submission got its row in first, so this call is a repeat — run it once more and it replays.
            if ($operationKey !== null && ! $afterDuplicateKey && self::isDuplicateKey($exception)) {
                return $this->run($refundId, $operationKey, $hash, $transition, $hook, true);
            }

            throw $exception;
        }

        if ($result['replayed']) {
            return OrderReturnResult::replayed($result['refund']);
        }

        Hook::fire($hook, $result['order'], $result['refund']);

        return OrderReturnResult::performed($result['refund']);
    }

    /** SQLSTATE 23000 + the driver's duplicate-key code (MySQL 1062, SQLite 19) — never the message (CLAUDE.md rule 3). */
    private static function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true);
    }

    /**
     * One REFUND_REVERSAL per refund line, in a Transaction of their own. The REFUND lines are found through
     * the history, never guessed: the refund's own event (`refund_owed`/`refunded`, which carries
     * payment_refund_id since R1b) names the return Transaction that wrote them. A refund with no per-line
     * rows (a shipping-only refund, a bare amount) has no goods to reverse and writes no storno.
     *
     * @return string|null the id of the storno Transaction, or null when there was nothing to reverse
     *
     * @throws RefundTransitionRefusedException refund_lines_unlinked / storno_mismatch
     */
    private function appendStorno(PaymentRefund $refund, ?string $staffId, ?string $staffName, string $reason, DateTimeImmutable $occurredAt): ?string
    {
        $lines = $refund->breakdown()->lines;

        if ($lines === []) {
            return null;
        }

        $returnTransactionId = DB::table('order_events')
            ->where('order_id', $refund->orderId())
            ->where('payment_refund_id', $refund->id())
            ->whereIn('type', [OrderEventType::REFUND_OWED->value, OrderEventType::REFUNDED->value])
            ->orderBy('id')
            ->value('transaction_id');

        if ($returnTransactionId === null) {
            throw new RefundTransitionRefusedException(RefundTransitionRefusedException::LINES_UNLINKED);
        }

        $return = $this->transactions->findByIdWithSaleLines((string) $returnTransactionId)
            ?? throw new RefundTransitionRefusedException(RefundTransitionRefusedException::LINES_UNLINKED);

        $byOrigin = [];

        foreach ($return->saleLines() as $line) {
            if ($line->type() === SaleLineType::REFUND && $line->originatingSaleLineId() !== null) {
                $byOrigin[$line->originatingSaleLineId()] = $line;
            }
        }

        $storno = new Transaction(null, Channel::WEB);

        foreach ($lines as $refundLine) {
            $reversed = $byOrigin[$refundLine->saleLineId] ?? null;

            // The refund's recorded amount for the line and the ledger's REFUND line must agree: nothing is guessed.
            if ($reversed === null || $reversed->actualRefundAmount() === null || ! $reversed->actualRefundAmount()->equals($refundLine->amount)) {
                throw new RefundTransitionRefusedException(RefundTransitionRefusedException::STORNO_MISMATCH);
            }

            $storno->addSaleLine(SaleLine::createRefundReversal($reversed, '', $staffId, $staffName, $reason, $occurredAt, $occurredAt));
        }

        $this->transactions->save($storno);

        return (string) $storno->id();
    }
}
