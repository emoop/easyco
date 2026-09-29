<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\OrderTransitionRefusedException;
use Closure;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE ONE PLACE AN ORDER'S OWN LIFECYCLE TRANSITIONS ARE PERFORMED —
 * order-lifecycle-design.md §5.2 (its §10 stage 6a). Three of the eventual
 * five public methods this stage: confirm(), ship(), deliver().
 * cancel()/recordReturn() are stage 6b's, on this SAME class, and are
 * deliberately not even stubbed here.
 *
 * EVERY METHOD PERFORMS THE SAME SIX-STEP SHAPE (§5.2), via
 * performTransition() below: validate the id; lock the order; return early,
 * silently, on an idempotent same-status call; run any transition-specific
 * guard; call the domain mutator and save; write one order_events row. Two
 * of the three methods deviate in exactly one place each — ship()'s R9
 * guard runs BEFORE the domain call, deliver()'s R10 attempt runs AFTER it
 * — and performTransition() takes each as an optional closure so the shared
 * shape lives in one method rather than being copied three times.
 *
 * PERMISSION: NONE CHECKED HERE, deliberately — the same posture
 * ProductStatusChanger already takes. ORDER_MANAGE is the Filament action's
 * job, arriving in a later stage; a console caller is accountable the way a
 * console caller of any other service here already is.
 *
 * R9's PAYMENT METHOD IS READ OFF Payment::method(), NEVER OFF Order —
 * VERIFIED, AND REPORTED AS A DIFFERENCE FROM THIS STAGE'S OWN BRIEF: Order
 * has no paymentMethod field and no accessor that could answer "is this
 * order's payment bank transfer" (its only delivery-shaped field,
 * deliveryType, is STREET_ADDRESS|PICKUP_POINT — the parcel's destination,
 * unrelated to how the customer pays). The method lives on Payment
 * ('cash_on_delivery'|'bank_transfer', checkout-domain-design.md §8.2/8.3),
 * so R9 reads it off the order's own payments — the same
 * PaymentRepository::findByOrderId() call the guard already needs for the
 * settled check.
 *
 * THE HOOK FIRES AFTER THE COMMIT, ALWAYS, AND ONLY WHEN A REAL TRANSITION
 * RAN (§12): never on the idempotent early return, which performs no domain
 * call, no write and no event either.
 */
final class OrderStatusChanger
{
    private const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly OrderEventRecorder $events,
        private readonly OrderPaymentConfirmer $paymentConfirmer,
    ) {}

    /**
     * placed -> confirmed (§2.1). Carries no service-owned guard of its own.
     */
    public function confirm(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
    {
        $this->performTransition($orderId, OrderStatus::CONFIRMED, $occurredAt, $note);
    }

    /**
     * confirmed -> shipped (§2.1), guarded by R9: refused while the order's
     * payment method is bank transfer and none of its payments has settled.
     * cash_on_delivery is unguarded (§2.2 R9).
     *
     * @throws OrderTransitionRefusedException If R9 refuses the move.
     */
    public function ship(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
    {
        $this->performTransition(
            $orderId,
            OrderStatus::SHIPPED,
            $occurredAt,
            $note,
            guard: function (Order $order): void {
                $this->guardBankTransferSettled($order);
            },
        );
    }

    /**
     * shipped -> delivered (§2.1), then R10: if the order has exactly one
     * payment that is PENDING, has an answered attempt and is not already
     * confirmed (and not voided — see this class's own guardBankTransfer...
     * sibling method confirmDeliveryPaymentIfEligible()'s own docblock for
     * why that exclusion exists although §4.5's table does not enumerate a
     * voided payment as one of its four other states), it is confirmed in
     * this SAME transaction, via
     * OrderPaymentConfirmer::confirmWithinOpenTransaction() — never the
     * public confirm(), which would compose as a savepoint and fire its hook
     * before this method's own outer commit (§4.3, this stage's whole reason
     * for existing). Any other state: the delivery still completes, nothing
     * is written to payments, and order.payment_confirmed does not fire.
     */
    public function deliver(string $orderId, DateTimeImmutable $occurredAt, ?string $note = null): void
    {
        $this->performTransition(
            $orderId,
            OrderStatus::DELIVERED,
            $occurredAt,
            $note,
            afterTransition: fn (Order $order): ?Payment => $this->confirmDeliveryPaymentIfEligible($order->id(), $occurredAt),
        );
    }

    /**
     * The shared six-step shape (§5.2), parameterised by the one guard and
     * the one post-transition step that differ between the three callers.
     *
     * Step 1 (validate $orderId) runs before any transaction opens, matching
     * ProductStatusChanger::requireProduct()'s posture. Steps 2-6 (lock,
     * idempotence, guard, domain call + save, event) run inside one
     * DB::transaction(); its return value is null for the idempotent
     * no-hook case, or the transition's own facts otherwise. The hook(s)
     * fire only after that transaction call returns, from the facts it
     * handed back — never from a value re-read afterward.
     */
    private function performTransition(
        string $orderId,
        OrderStatus $target,
        DateTimeImmutable $occurredAt,
        ?string $note,
        ?Closure $guard = null,
        ?Closure $afterTransition = null,
    ): void {
        if (trim($orderId) === '') {
            throw new InvalidArgumentException('OrderStatusChanger: orderId must not be empty.');
        }

        /** @var array{order: Order, from: OrderStatus, to: OrderStatus, confirmedPayment: ?Payment}|null $result */
        $result = DB::transaction(function () use ($orderId, $target, $occurredAt, $note, $guard, $afterTransition): ?array {
            $order = $this->orders->findByIdForUpdate($orderId);

            if ($order === null) {
                throw new InvalidArgumentException(
                    "OrderStatusChanger: no order exists with id \"{$orderId}\"."
                );
            }

            // Idempotence (§5.2 step 3): a double-click on an already-target
            // order performs no domain call, no save, no event — and, below,
            // no hook. The aggregate itself would also refuse a same-status
            // call; this early return is what makes a retry harmless instead
            // of a 500.
            if ($order->status() === $target) {
                return null;
            }

            if ($guard !== null) {
                $guard($order);
            }

            $from = $order->status();

            match ($target) {
                OrderStatus::CONFIRMED => $order->confirm(),
                OrderStatus::SHIPPED => $order->ship(),
                OrderStatus::DELIVERED => $order->deliver(),
                default => throw new InvalidArgumentException(
                    "OrderStatusChanger: performTransition() does not support target status \"{$target->value}\"."
                ),
            };

            $this->orders->save($order);

            $this->events->record(
                orderId: $orderId,
                type: OrderEventType::STATUS_CHANGED,
                fromStatus: $from,
                toStatus: $target,
                reason: $note,
                transactionId: null,
                occurredAt: $occurredAt,
            );

            $confirmedPayment = $afterTransition !== null ? $afterTransition($order) : null;

            return ['order' => $order, 'from' => $from, 'to' => $target, 'confirmedPayment' => $confirmedPayment];
        });

        if ($result === null) {
            return;
        }

        // AFTER the commit, and only after it (§12): always for the
        // transition itself; additionally for the payment, only when R10
        // actually confirmed one.
        Hook::fire('order.status_changed', $result['order'], $result['from'], $result['to']);

        if ($result['confirmedPayment'] !== null) {
            Hook::fire('order.payment_confirmed', $result['confirmedPayment']);
        }
    }

    /**
     * R9 (§2.2): reads the order's payment method off its own Payment rows,
     * never off Order (see this class's own docblock) — any payment whose
     * method() is 'bank_transfer' makes this a bank-transfer order, and the
     * transition is refused unless at least one payment isSettled().
     * cash_on_delivery orders carry no bank_transfer payment, so the guard
     * is a silent no-op for them.
     */
    private function guardBankTransferSettled(Order $order): void
    {
        $isBankTransfer = false;
        $hasSettledPayment = false;

        foreach ($this->payments->findByOrderId($order->id()) as $payment) {
            if ($payment->method() === self::PAYMENT_METHOD_BANK_TRANSFER) {
                $isBankTransfer = true;
            }

            if ($payment->isSettled()) {
                $hasSettledPayment = true;
            }
        }

        if ($isBankTransfer && ! $hasSettledPayment) {
            throw OrderTransitionRefusedException::becauseBankTransferNotSettled($order->id());
        }
    }

    /**
     * R10's pre-check (§4.3, §4.5's table): confirms the order's payment
     * only when EXACTLY ONE of its payments is PENDING, has an answered
     * attempt, and is not already confirmed — never by calling
     * confirmWithinOpenTransaction() and catching a refusal, per this
     * stage's own instruction that a wrong confirmation must never be
     * attempted against a delivery that physically happened.
     *
     * ALSO EXCLUDES A VOIDED PAYMENT — a gap in §4.5's own table, found and
     * reported rather than silently worked around: void() (§7.3, stage 6b,
     * not built yet) leaves status PENDING and confirmedAt NULL, so a voided
     * row would otherwise match this filter and reach
     * Payment::confirm()'s own voidedAt guard, which throws — aborting
     * deliver()'s WHOLE transaction instead of completing the delivery with
     * no confirmation, exactly the outcome R10's pre-check exists to avoid.
     * No caller in this codebase can produce a voided payment yet, so this
     * is defensive rather than reachable today; excluded here so it is
     * correct once stage 6b ships void(), not discovered as a live bug then.
     *
     * Zero or more-than-one candidate: no confirmation, no hook, the
     * delivery still completes — §4.5's other four states, and the one
     * genuine data anomaly of a settled row coexisting with an otherwise-
     * eligible one (which normal checkout never produces — flagged, not
     * defended against further: see this stage's own final report).
     */
    private function confirmDeliveryPaymentIfEligible(string $orderId, DateTimeImmutable $occurredAt): ?Payment
    {
        $confirmable = array_values(array_filter(
            $this->payments->findByOrderId($orderId),
            static fn (Payment $payment): bool => $payment->status() === PaymentStatus::PENDING
                && $payment->attemptedAt() !== null
                && $payment->confirmedAt() === null
                && ! $payment->isVoided(),
        ));

        if (count($confirmable) !== 1) {
            return null;
        }

        return $this->paymentConfirmer->confirmWithinOpenTransaction($confirmable[0]->id(), $occurredAt);
    }
}
