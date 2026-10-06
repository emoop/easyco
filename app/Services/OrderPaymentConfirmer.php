<?php

namespace App\Services;

use App\Enums\OrderEventType;
use DateTimeImmutable;
use DateTimeInterface;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE place a payment's money is recorded as received —
 * order-lifecycle-design.md §4.3 (its §10 stage 5). Payment::confirm() is the
 * domain's half (§4.1), which decides what is confirmable from the aggregate
 * itself; this class owns the transaction around it, so "the money arrived"
 * means one thing whichever surface records it — the panel's own payment
 * action (§8.1) and §5.2's `deliver()` for a cash-on-delivery order are its
 * two callers, and nothing else in the codebase writes confirmed_at.
 *
 * IT PERFORMS NO ORDER TRANSITION, DELIBERATELY. §3 item 1: money arriving
 * never moves an order's status — there is no "paid" value to move it to, and
 * a `placed` order whose bank transfer has arrived stays `placed` until the
 * merchant accepts it. That is also why this is a separate class from §5.2's
 * OrderStatusChanger: confirming a payment has to stay callable on a
 * delivered order, where there is nothing at all to transition.
 *
 * WHAT IT WRITES, AND NOTHING ELSE: the payment row's confirmed_at, and one
 * order_events row of type payment_confirmed with BOTH statuses NULL (§6.1 —
 * nothing transitioned, which is the shape every "real event, no transition"
 * row takes). No stock, no email, no notification, no refund, no order write.
 *
 * ONE TRANSACTION, ONE LOCK ORDER — the order row first, then its payments
 * (§11 item 13). Two panel actions that took those rows in opposite orders
 * could deadlock each other, and the money fact and the goods fact of an R10
 * delivery have to be one atomic unit, so every path that touches both locks
 * the order first.
 *
 * THE REFUSALS ARE NOT WRAPPED (§5.2). Payment::confirm()'s own guards throw
 * LogicException and are left exactly as the domain wrote them; this class's
 * own refusals — an unknown payment, an unknown order, and an order that
 * already has a settled attempt — are InvalidArgumentException, because they
 * describe a request that cannot be answered rather than a broken invariant.
 * §4.3 step 2's settled check is a COURTESY CHECK: it exists so an operator
 * reads a sentence naming the settled attempt instead of a raw
 * QueryException, and §4.4's pay_settled_order_unique is the actual guarantee
 * (the same relationship PaymentStatus's own docblock draws for the capture
 * half). ONE HONEST CONSEQUENCE OF THAT, STATED RATHER THAN DISCOVERED LATER:
 * this transaction's first statement is a plain read, so under MySQL's default
 * REPEATABLE READ it opens a consistent snapshot — a competing confirmation
 * committed between that read and the lock below is invisible to step 3's scan.
 * The unique index refuses that case; the sentence does not. That is exactly the
 * division of labour §4.3 draws.
 *
 * NO PERMISSION CHECK AND NO ACTOR PARAMETER — like every other app service
 * here. The action that calls this is what is authorized (§8.3:
 * ORDER_MANAGE), and the actor is resolved inside OrderEventRecorder from the
 * panel guard, so a console or queued caller records a null actor rather than
 * failing (§6.2, §11 item 16).
 *
 * NO CONTAINER BINDING OF ITS OWN, AND NO STATE: like OrderEventRecorder it
 * is autowired from its three collaborators — deliberately not scoped() or
 * singleton(), because there is nothing to memoize and a longer lifetime
 * would only risk carrying one request's resolved repositories into the next.
 *
 * AND THE HOOK FIRES AFTER THE COMMIT, NEVER INSIDE IT (§12): a listener is
 * told a fact that is already durable, so a listener that throws cannot
 * un-record it — the error surfaces to the operator while the confirmation
 * stays written (§8.3 item 4's policy, proven in this class's own tests).
 *
 * SPLIT IN TWO, AS OF order-lifecycle-design.md §10 stage 6a, FOR ONE REASON:
 * §5.2's deliver() needs steps 1-6 below run INSIDE ITS OWN already-open
 * transaction, with the hook fired only after THAT transaction's real commit
 * — not after this class's own DB::transaction() call returns, which, when
 * nested inside a caller's open transaction, is merely a savepoint release
 * (Laravel's own nested-transaction composition). Firing here in that case
 * would violate §12's "every action hook fires after the writing transaction
 * commits": the confirmation could still be rolled back by the OUTER
 * transaction after the hook already ran. confirmWithinOpenTransaction()
 * below is steps 1-6, with no transaction of its own and no hook — it
 * ASSUMES it is already inside an open transaction, exactly the assumption
 * App\Services\OrderEventRecorder::record() already makes of its own
 * callers. confirm() is now a thin wrapper: open one transaction, run the
 * steps, fire the hook after that transaction call returns — behaviourally
 * IDENTICAL to the pre-split body for confirm()'s own callers (this class's
 * public API and its transaction/hook timing are unchanged; only the
 * refactor of what runs inside it is new).
 *
 * confirmWithinOpenTransaction() IS PUBLIC, NOT protected/private, BECAUSE
 * PHP HAS NO PACKAGE-PRIVATE VISIBILITY: OrderStatusChanger::deliver() is a
 * different class calling this one as a constructor-injected collaborator,
 * not a subclass, so `protected` would refuse it exactly as `private`
 * would. There is no visibility modifier that says "internal to this
 * package, not to the whole app" — so this is public, its name says what it
 * assumes, and its docblock states the one rule that matters: never call it
 * except from inside a transaction you will fire the hook after committing.
 */
final class OrderPaymentConfirmer
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
        private readonly OrderEventRecorder $events,
        private readonly PaymentReceiptReader $receipts,
    ) {}

    /**
     * Records that the payment's money arrived at $confirmedAt, exactly once
     * (§4.3): one transaction, the domain's own guards, one payment write, one
     * event, then the hook — nothing else.
     *
     * $confirmedAt is the INSTANT THE FACT HAPPENED, supplied by the caller
     * (like placed_at and every other instant this codebase records), never
     * "now" and never this class's own DateTimeImmutable — the same posture
     * OrderEventRecorder::record() takes toward its own occurredAt.
     *
     * THE PLAIN DB::transaction() FORM, WITH NO RETRY-ON-DEADLOCK LOOP
     * (§4.3): exactly one payment row is written here, and a retry would
     * re-run a decision the caller already made from data this class read
     * inside the same transaction. The whole multi-table operation that
     * genuinely has to survive a deadlock — a transition with its stock, goods
     * and money — is §5.2's, and it passes attempts at its own call site.
     *
     * CALLED FROM INSIDE A CALLER'S OWN TRANSACTION, AND SAFELY (§4.3):
     * `deliver()` no longer calls this public method at all (see this class's
     * own docblock, stage 6a) — it calls confirmWithinOpenTransaction()
     * directly, inside its own transaction, and fires the hook itself after
     * that transaction's real commit. This method's own transaction/hook
     * timing is therefore exactly what it always was, for its own caller
     * (the panel's payment action).
     *
     * @throws InvalidArgumentException If no payment has that id, if its order does not exist, or if the order already has a settled payment.
     * @throws \LogicException If Payment::confirm()'s own guards refuse it — an unanswered, captured, failed or voided attempt, or a second confirmation.
     */
    public function confirm(string $paymentId, DateTimeImmutable $confirmedAt): void
    {
        $payment = DB::transaction(
            fn (): Payment => $this->confirmWithinOpenTransaction($paymentId, $confirmedAt)
        );

        // AFTER the commit, and only after it (§12): the confirmation is
        // durable before any listener hears about it.
        Hook::fire('order.payment_confirmed', $payment);
    }

    /**
     * Steps 1-6 of confirm(), with no transaction of its own and no hook —
     * see this class's own docblock for exactly why this split exists.
     *
     * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION. The caller is
     * responsible for: (1) actually holding one open, and (2) firing
     * `Hook::fire('order.payment_confirmed', $payment)` with this method's
     * return value AFTER that transaction genuinely commits — never before,
     * and never at all if the caller decides not to call this in the first
     * place (§4.5's table: a refusal to confirm is the caller's own decision,
     * made BEFORE calling this, never by catching an exception from it).
     *
     * @throws InvalidArgumentException If no payment has that id, if its order does not exist, or if the order already has a settled payment.
     * @throws \LogicException If Payment::confirm()'s own guards refuse it — an unanswered, captured, failed or voided attempt, or a second confirmation.
     * @throws \App\Services\Exceptions\PaymentReceiptUnreconciledException If it is a bank transfer with an effective receipt (refunds R4a-2): see confirmReconciledWithinOpenTransaction().
     */
    public function confirmWithinOpenTransaction(string $paymentId, DateTimeImmutable $confirmedAt): Payment
    {
        return $this->settle($paymentId, $confirmedAt, true);
    }

    /**
     * The SAME settlement, for ONE caller: PaymentReceiptRecorder's exact-match path (refunds R4a-2,
     * shipping-domain-design.md §7.2.20 §3), which has just written the receipt that completes the
     * expected amount and so is the reconciliation itself. confirmWithinOpenTransaction() REFUSES a
     * bank-transfer payment that already has an effective receipt (PaymentReceiptUnreconciledException):
     * until the dialog of R4a-4 replaces the old one-click "mark as received", that one-click path
     * would settle for the full expected amount over a receipt that does not match it. Settling is
     * still ONE implementation — this is the same private steps with the receipt check switched off —
     * and the bypass has its own name rather than a boolean a caller could pass by mistake.
     *
     * Same contract as confirmWithinOpenTransaction(): inside an open transaction, no hook.
     */
    public function confirmReconciledWithinOpenTransaction(string $paymentId, DateTimeImmutable $confirmedAt): Payment
    {
        return $this->settle($paymentId, $confirmedAt, false);
    }

    /**
     * The SAME settlement once more, for the ONE other caller that may settle around receipts:
     * PaymentReceiptRecorder::acceptMismatch() (refunds R4a-3, shipping-domain-design.md §7.2.20 §4a) —
     * the merchant has accepted what was received, so the payment is settled for exactly $acceptedAmount
     * and the reason is stored on it (Payment::confirm()'s own validation of the pair applies). The
     * one-click path stays refused for a mismatching receipt; this one has its own name for the same
     * reason confirmReconciledWithinOpenTransaction() does. Same contract: inside an open transaction,
     * no hook, and the caller has already decided and checked the permission.
     */
    public function confirmAcceptedWithinOpenTransaction(string $paymentId, DateTimeImmutable $confirmedAt, Money $acceptedAmount, string $reason): Payment
    {
        return $this->settle($paymentId, $confirmedAt, false, $acceptedAmount, $reason);
    }

    private function settle(string $paymentId, DateTimeImmutable $confirmedAt, bool $refuseUnreconciledReceipts, ?Money $acceptedAmount = null, ?string $acceptedReason = null): Payment
    {
        // 1. The payment itself — a plain read, and deliberately the first
        //    statement, because the order to lock is only known from this
        //    row. The lock below, not this read, is what makes the
        //    decision that follows hold.
        $payment = $this->payments->findById($paymentId);

        if ($payment === null) {
            throw new InvalidArgumentException(
                "OrderPaymentConfirmer: no payment exists with id \"{$paymentId}\"."
            );
        }

        // 2. THE ORDER ROW, UNDER ITS LOCK, BEFORE ANY PAYMENT IS READ OR
        //    WRITTEN (§11 item 13's one lock order: the order, then its
        //    payments). The value is not needed — the lock is the point:
        //    it is what stops another panel action from moving this
        //    order's money underneath the decision made below. Re-locking a
        //    row this same transaction already holds (deliver()'s own
        //    findByIdForUpdate() call, before this method runs) is a safe,
        //    reentrant no-op under InnoDB — not a second, competing lock.
        if ($this->orders->findByIdForUpdate($payment->orderId()) === null) {
            throw new InvalidArgumentException(
                "OrderPaymentConfirmer: payment \"{$paymentId}\" belongs to order \"{$payment->orderId()}\", which does not exist."
            );
        }

        // 3. The courtesy check (§4.3 step 2): an attempt of this order
        //    already holds the money — including this very row, which is why
        //    a captured or already-confirmed payment is refused here, with a
        //    readable sentence, rather than by the domain guard behind it.
        //    §4.4's unique index is the guarantee; this is the readable
        //    refusal in front of it.
        foreach ($this->payments->findByOrderId($payment->orderId()) as $attempt) {
            if ($attempt->isSettled()) {
                throw new InvalidArgumentException(sprintf(
                    'OrderPaymentConfirmer: order "%s" already has a settled payment ("%s", %s) — at most one payment per order may hold money.',
                    $payment->orderId(),
                    (string) $attempt->id(),
                    self::describeSettled($attempt),
                ));
            }
        }

        // 3b. Money seen arriving that does not add up to this payment (R4a-2): the settlement is the
        //     receipt service's to make, not the one-click's. No read at all for a payment that is not a
        //     pending bank transfer (cash on delivery, R10).
        if ($refuseUnreconciledReceipts) {
            $this->receipts->assertReconciled([$payment]);
        }

        // 4. The domain's own guards (§4.1) — they throw before anything
        //    is written, and their LogicExceptions are not caught here.
        $payment->confirm($confirmedAt, $acceptedAmount, $acceptedReason);

        // 5. The row.
        $this->payments->save($payment);

        // 6. ...and the order's own history, inside the same transaction
        //    (§6.2): a confirmation that rolls back leaves no event behind.
        //    No reason and no transaction id — nothing was said and no
        //    return happened; both statuses NULL, because nothing moved.
        $this->events->record(
            orderId: $payment->orderId(),
            type: OrderEventType::PAYMENT_CONFIRMED,
            fromStatus: null,
            toStatus: null,
            reason: null,
            transactionId: null,
            occurredAt: $confirmedAt,
        );

        return $payment;
    }

    /**
     * two facts isSettled() is built of, read off the row, never re-derived as
     * a predicate (§11 item 17: the decision belongs to isSettled() alone).
     */
    private static function describeSettled(Payment $payment): string
    {
        if ($payment->confirmedAt() !== null) {
            return 'confirmed at '.$payment->confirmedAt()->format(DateTimeInterface::ATOM);
        }

        return 'captured by the adapter (status '.$payment->status()->value.')';
    }
}
