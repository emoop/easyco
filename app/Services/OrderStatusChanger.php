<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Services\Exceptions\OperationKeyReusedException;
use App\Services\Exceptions\OrderTransitionRefusedException;
use App\Services\Exceptions\RefundCapExceededException;
use App\Services\Exceptions\RefundPermissionDeniedException;
use App\Services\Exceptions\ReturnAnnouncedDateException;
use App\Settings\StoreTimezone;
use Closure;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentRefundStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\RefundBreakdown;
use EasyCo\Payment\RefundLine;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * THE ONE PLACE AN ORDER'S OWN LIFECYCLE TRANSITIONS ARE PERFORMED —
 * order-lifecycle-design.md §5.2. All five public methods now live here:
 * confirm(), ship(), deliver() (§10 stage 6a) and cancel()/recordReturn()
 * (§10 stage 6b-ii part 2, this pass).
 *
 * confirm()/ship()/deliver() SHARE performTransition()'s SIX-STEP SHAPE
 * (§5.2), UNCHANGED BY THIS PASS: validate the id; lock the order; return
 * early, silently, on an idempotent same-status call; run any
 * transition-specific guard; call the domain mutator and save; write one
 * order_events row.
 *
 * cancel()/recordReturn() SHARE A SEPARATE WORKER, performReturn() —
 * DELIBERATELY NOT performTransition(), and this is the first thing worth
 * reporting: performTransition()'s shape assumes exactly one domain mutator
 * call and exactly one order_events row per call, and returns early on an
 * idempotent same-status call. Neither holds for a return: a PARTIAL return
 * (or a cancel()/recordReturn() call that resolves to an empty line list)
 * writes REFUND lines/stock/an order_events(RETURNED) row while moving NO
 * status at all, and may then ALSO write a second order_events(STATUS_
 * CHANGED) row and a third (REFUNDED/PAYMENT_VOIDED) row in the SAME call —
 * up to three rows, zero or one domain mutator call, from one method call.
 * Forcing that shape through performTransition()'s "one guard, one domain
 * call, one event" parameterisation would have meant the guard closure
 * secretly doing the domain call and the event write too, which is a worse
 * kind of duplication than two similar-looking transaction bodies. R2's own
 * "one implementation serves cancellation and every return" is honoured by
 * performReturn() itself, shared by both new public methods — see that
 * method's own docblock.
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
 * NO AUTHENTICATED ACTOR IS WIRED INTO cancel()/recordReturn() YET — a
 * stage-7 (admin panel) gap, reported rather than silently patched:
 * App\Services\PanelStaffActor exists and IS already used by
 * OrderEventRecorder and OrderRefunder to resolve an actor for THEIR OWN
 * fields, but no panel action calls either new method yet, so there is
 * nothing behind a resolved actor here. ReturnGoodsRecorder::record()'s
 * returnedBy/returnedByName are passed null from both new methods, per this
 * stage's own explicit instruction — see performReturn()'s own docblock.
 *
 * ALL HOOKS FIRE AFTER THE COMMIT, ALWAYS, AND ONLY WHEN THE FACT THEY NAME
 * ACTUALLY HAPPENED (§12, §11 item 9): never on a refused call, which
 * performs no domain call, no write and no event either.
 */
final class OrderStatusChanger
{
    private const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly OrderEventRecorder $events,
        private readonly OrderPaymentConfirmer $paymentConfirmer,
        private readonly OrderCurrentLinesResolver $currentLines,
        private readonly SaleLineRepository $saleLineRepository,
        private readonly ReturnGoodsRecorder $returnGoodsRecorder,
        private readonly OrderRefunder $orderRefunder,
        private readonly PromotionRedemptionRepository $promotionRedemptions,
        private readonly RefundCapGuard $capGuard,
        private readonly PaymentRefundRepository $paymentRefunds,
        private readonly StoreTimezone $storeTimezone,
        private readonly PaymentReceiptReader $paymentReceipts,
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
     * placed|confirmed|shipped -> cancelled (§2.1), by returning every
     * remaining unit of every SALE line on the order's placement
     * transaction (R1/R2). Before `shipped` the goods never left, so
     * $restockOverrides is ignored ENTIRELY and every line restocks
     * unconditionally (R3); from `shipped` a line absent from the map
     * restocks by default (true), and a line present with `false` does not.
     *
     * @param array<string, bool> $restockOverrides Keyed by originatingSaleLineId — read only when the order's locked status is `shipped`.
     * @param RefundRequest|null $refund What the merchant decided about the money (shipping-domain-design.md §7.2.1); null is today's behaviour exactly — computed shares, no shipping, no deduction, derived channel. Its operationKey makes the call idempotent (§7.2.3): the same key with the same contents returns the first result and writes nothing; the same key with other contents throws OperationKeyReusedException.
     * @return OrderReturnResult wasReplay() true when this was a repeat of an operation already performed
     * @throws RefundCapExceededException A refund cap would be broken — nothing was written.
     * @throws RefundPermissionDeniedException Refunding a settled payment without the permission of its payout channel.
     *
     * @throws InvalidArgumentException If $orderId is empty or unknown.
     * @throws OrderTransitionRefusedException If the order's locked status is not placed/confirmed/shipped.
     */
    public function cancel(string $orderId, DateTimeImmutable $occurredAt, ?string $reason = null, array $restockOverrides = [], ?RefundRequest $refund = null): OrderReturnResult
    {
        if (trim($orderId) === '') {
            throw new InvalidArgumentException('OrderStatusChanger: orderId must not be empty.');
        }

        // The announced-return date is what a CUSTOMER told staff about a return (recordReturn()); a
        // cancellation has none. Refused rather than silently dropped.
        if ($refund?->announcedReturnOn !== null) {
            throw new InvalidArgumentException('OrderStatusChanger: cancel() takes no announced-return day; only recordReturn() does.');
        }

        return $this->performReturn(
            $orderId,
            $occurredAt,
            $reason,
            $refund,
            $refund?->operationKey !== null ? RefundOperationFingerprint::of('cancel', [], $restockOverrides, $refund, $reason) : null,
            legalStartingStatuses: [OrderStatus::PLACED, OrderStatus::CONFIRMED, OrderStatus::SHIPPED],
            refusalException: static fn (string $orderId, OrderStatus $status): Throwable => OrderTransitionRefusedException::becauseOrderNotCancellable($orderId, $status),
            resolveLines: function (Order $order, array $saleLines) use ($restockOverrides): array {
                $entries = [];

                foreach ($saleLines as $saleLine) {
                    $remaining = $saleLine->quantity() - $this->saleLineRepository->sumQuantityReturnedForOriginatingLine($saleLine->id());

                    if ($remaining <= 0) {
                        continue;
                    }

                    // R3: before `shipped` the goods never left, so the
                    // override map is ignored entirely and every line
                    // restocks; from `shipped` the merchant's own per-line
                    // answer applies, defaulting to true for an absent line.
                    $restock = $order->status() === OrderStatus::SHIPPED
                        ? ($restockOverrides[$saleLine->id()] ?? true)
                        : true;

                    $entries[] = [
                        'originatingLine' => $saleLine,
                        'quantityReturned' => $remaining,
                        'restock' => $restock,
                    ];
                }

                return $entries;
            },
        );
    }

    /**
     * A merchant-recorded, per-line return — legal only while the order's
     * locked status is shipped/delivered (R2, §2.3). Quantities/restock
     * flags travel through to ReturnGoodsRecorder unchanged; its own
     * validation refuses a request exceeding what R7's remaining-units read
     * allows.
     *
     * @param array<int, array{originatingSaleLineId: string, quantityReturned: int, restock: bool}> $lines
     * @param RefundRequest|null $refund Its announcedReturnOn (refunds R3) is the calendar day the customer announced this return: stored on the `returned` history row; between the order's placement day and the recording day ($occurredAt), both in the STORE timezone, inclusive.
     *
     * @throws ReturnAnnouncedDateException The announced date is in the future or before the order was placed — nothing was written.
     * @throws InvalidArgumentException If $orderId is empty or unknown, if
     *   $lines is empty, if any quantityReturned is not a positive integer,
     *   if $lines references the same originatingSaleLineId more than once
     *   (see this method's own "NOT DEFENDED AGAINST" note below), or if an
     *   originatingSaleLineId does not name a SALE line on this order's
     *   placement transaction.
     * @throws OrderTransitionRefusedException If the order's locked status is not shipped/delivered.
     */
    public function recordReturn(string $orderId, array $lines, DateTimeImmutable $occurredAt, ?string $reason = null, ?RefundRequest $refund = null): OrderReturnResult
    {
        if (trim($orderId) === '') {
            throw new InvalidArgumentException('OrderStatusChanger: orderId must not be empty.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('OrderStatusChanger: recordReturn() lines must not be empty.');
        }

        // NOT DEFENDED AGAINST BY ReturnGoodsRecorder ITSELF — that class's
        // own docblock assumes its real caller builds $lines from a map
        // keyed by originating line id, which makes a duplicate
        // structurally impossible there. THIS method's own $lines shape
        // (§5.2's own signature: a plain list, each entry carrying its own
        // originatingSaleLineId) reopens exactly that possibility — two
        // entries for the same SALE line would each be validated
        // independently against the SAME "already returned" read and could
        // together request more than actually remains. Guarded here,
        // before the lock, rather than silently inherited as a gap.
        $seenSaleLineIds = [];

        foreach ($lines as $line) {
            $id = $line['originatingSaleLineId'];

            if (isset($seenSaleLineIds[$id])) {
                throw new InvalidArgumentException(
                    "OrderStatusChanger: recordReturn() lines must not reference SaleLine \"{$id}\" more than once in a single call."
                );
            }
            $seenSaleLineIds[$id] = true;

            if (! is_int($line['quantityReturned']) || $line['quantityReturned'] <= 0) {
                throw new InvalidArgumentException(
                    "OrderStatusChanger: recordReturn() quantityReturned for SaleLine \"{$id}\" must be a positive integer."
                );
            }
        }

        return $this->performReturn(
            $orderId,
            $occurredAt,
            $reason,
            $refund,
            $refund?->operationKey !== null ? RefundOperationFingerprint::of('return', $lines, [], $refund, $reason) : null,
            legalStartingStatuses: [OrderStatus::SHIPPED, OrderStatus::DELIVERED],
            refusalException: static fn (string $orderId, OrderStatus $status): Throwable => OrderTransitionRefusedException::becauseOrderNotReturnable($orderId, $status),
            resolveLines: function (Order $order, array $saleLines) use ($lines): array {
                $byId = [];
                foreach ($saleLines as $saleLine) {
                    $byId[$saleLine->id()] = $saleLine;
                }

                $entries = [];

                foreach ($lines as $line) {
                    $id = $line['originatingSaleLineId'];

                    if (! isset($byId[$id])) {
                        throw new InvalidArgumentException(
                            "OrderStatusChanger: SaleLine \"{$id}\" is not a current SALE line of this order."
                        );
                    }

                    $entries[] = [
                        'originatingLine' => $byId[$id],
                        'quantityReturned' => $line['quantityReturned'],
                        'restock' => $line['restock'],
                    ];
                }

                return $entries;
            },
        );
    }

    /**
     * R2's ONE implementation of "the goods come back", shared by cancel()
     * and recordReturn() — the only thing that differs between them is how
     * $resolveLines turns the order's current SALE lines into
     * {originatingLine, quantityReturned, restock} entries (cancel(): every
     * remaining unit of every line; recordReturn(): the operator's own map,
     * validated against those same lines). $legalStartingStatuses/
     * $refusalException let each caller state its own legal set and its own
     * translatable refusal (§8.3 item 4).
     *
     * Steps, all inside ONE DB::transaction(), order row locked first
     * (§11 item 13):
     *  1. Lock the order; refuse an unknown id or an illegal starting status.
     *  2. Load the placement transaction's SALE lines; resolve $entries.
     *  3. If $entries is non-empty: ReturnGoodsRecorder::record() (§7.2),
     *     sum the new REFUND lines' actualRefundAmount() into
     *     $totalRefundAmount, write one order_events(RETURNED) row.
     *  4. R6/§2.3's compare-and-set: re-read EVERY placement SALE line's
     *     remaining units UNDER THE SAME LOCK (never reused from step 2/3),
     *     so a repeat call or a second entry for the same line cannot fool
     *     it. If every line is now fully accounted for, reach the terminal
     *     status — CANCELLED if the locked status was placed/confirmed/
     *     shipped, REFUNDED if delivered — via Order::cancel()/refund() +
     *     save(), and write one order_events(STATUS_CHANGED) row.
     *  5. R11: only when step 4 reached CANCELLED, release the order's
     *     promotion redemption (if any, and not already released).
     *  6. R8/§7.3: only when $totalRefundAmount is positive, call
     *     OrderRefunder::refund() inside this SAME transaction and write
     *     the matching order_events row (REFUNDED/PAYMENT_VOIDED/none) from
     *     its returned OrderRefundOutcome.
     *
     * AFTER commit (§12, §11 item 9): order.returned when step 3 ran;
     * order.status_changed, then order.cancelled (only for CANCELLED; a REFUNDED
     * terminal is announced by order.status_changed alone), when step 4 reached a
     * terminal status; and, when step 6's OrderRefunder produced a refund,
     * order.refund_recorded (+ order.refund_paid_out if it is already COMPLETED).
     * `order.refunded` was REMOVED in refunds R2a (it fired for an OWED refund).
     *
     * REPORTED DIFFERENCE FROM order-lifecycle-design.md §12's own Hook
     * Reference table, per this stage's own explicit instruction: that
     * table types order.returned as `(Order $order, array $returnedLines):
     * void` and order.refunded as `(Order $order, PaymentRefund $refund):
     * void` (non-nullable). This pass fires order.returned(Order,
     * Transaction) — the return's own Transaction, from which a listener
     * reads saleLines() itself. (This pass also fired order.refunded with a
     * nullable PaymentRefund; refunds R2a REMOVED that hook and replaced it
     * with order.refund_recorded / order.refund_paid_out / order.refund_cancelled,
     * see the Hook Reference.)
     *
     * NO AUTHENTICATED ACTOR: ReturnGoodsRecorder::record()'s
     * returnedBy/returnedByName are passed null (see this class's own
     * docblock) — a stage-7 gap, not a silent omission.
     */
    private function performReturn(
        string $orderId,
        DateTimeImmutable $occurredAt,
        ?string $reason,
        ?RefundRequest $refundRequest,
        ?string $payloadHash,
        array $legalStartingStatuses,
        Closure $refusalException,
        Closure $resolveLines,
        bool $afterDuplicateKey = false,
    ): OrderReturnResult {
        $operationKey = $refundRequest?->operationKey;

        try {
            /** @var array{replayed?: bool, refundId?: ?string, order?: Order, returnTransaction?: ?Transaction, from?: ?OrderStatus, to?: ?OrderStatus, outcome?: ?OrderRefundOutcome} $result */
            $result = DB::transaction(fn (): array => $this->performReturnLocked($orderId, $occurredAt, $reason, $refundRequest, $operationKey, $payloadHash, $legalStartingStatuses, $refusalException, $resolveLines));
        } catch (QueryException $exception) {
            // The UNIQUE (order_id, operation_key) is the backstop behind the order
            // lock: if a concurrent submission got its row in first, this call is a
            // repeat — run it once more, and it will find that row and replay it.
            if ($operationKey !== null && ! $afterDuplicateKey && self::isDuplicateKey($exception)) {
                return $this->performReturn($orderId, $occurredAt, $reason, $refundRequest, $payloadHash, $legalStartingStatuses, $refusalException, $resolveLines, true);
            }

            throw $exception;
        }

        if ($result['replayed'] ?? false) {
            return OrderReturnResult::replayed(
                $result['refundId'] !== null ? $this->paymentRefunds->findById($result['refundId']) : null,
            );
        }

        if ($result['returnTransaction'] !== null) {
            Hook::fire('order.returned', $result['order'], $result['returnTransaction']);
        }

        if ($result['to'] !== null) {
            Hook::fire('order.status_changed', $result['order'], $result['from'], $result['to']);

            if ($result['to'] === OrderStatus::CANCELLED) {
                Hook::fire('order.cancelled', $result['order'], $reason);
            }
        }

        // The money facts (refunds R2a, shipping-domain-design.md §7.2.16). `order.refunded` is gone: it
        // fired for a refund that was only OWED, which would let an extension tell a customer money was
        // returned before it was. A refund that was RECORDED fires order.refund_recorded; money that has
        // actually left (an online refund that is already COMPLETED — none exists yet) also fires
        // order.refund_paid_out. An OWED refund is paid out later, by RefundStatusChanger.
        $recorded = $result['outcome']?->refund();

        if ($recorded !== null) {
            Hook::fire('order.refund_recorded', $result['order'], $recorded);

            if ($recorded->status() === PaymentRefundStatus::COMPLETED) {
                Hook::fire('order.refund_paid_out', $result['order'], $recorded);
            }
        }

        return OrderReturnResult::performed($result['outcome']?->refund());
    }

    /** SQLSTATE 23000 + the driver's duplicate-key code (MySQL 1062, SQLite 19) — never the message (CLAUDE.md rule 3). */
    private static function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true);
    }

    /**
     * performReturn()'s body, INSIDE the transaction, order row locked first.
     * Before anything else it REPLAYS an operation already performed under the
     * same key (shipping-domain-design.md §7.2.3) — before the status guard, since
     * a finished cancel has moved the order to a status that guard would refuse.
     *
     * @return array<string, mixed>
     */
    private function performReturnLocked(
        string $orderId,
        DateTimeImmutable $occurredAt,
        ?string $reason,
        ?RefundRequest $refundRequest,
        ?string $operationKey,
        ?string $payloadHash,
        array $legalStartingStatuses,
        Closure $refusalException,
        Closure $resolveLines,
    ): array {
        $order = $this->orders->findByIdForUpdate($orderId);

        if ($order === null) {
            throw new InvalidArgumentException(
                "OrderStatusChanger: no order exists with id \"{$orderId}\"."
            );
        }

        if ($operationKey !== null) {
            $prior = DB::table('order_events')->where('order_id', $orderId)->where('operation_key', $operationKey)->first();

            if ($prior !== null) {
                if (! hash_equals((string) $prior->operation_payload_hash, (string) $payloadHash)) {
                    throw new OperationKeyReusedException();
                }

                $refundId = $prior->transaction_id === null ? null : DB::table('order_events')
                    ->where('order_id', $orderId)
                    ->where('transaction_id', $prior->transaction_id)
                    ->whereNotNull('payment_refund_id')
                    ->value('payment_refund_id');

                return ['replayed' => true, 'refundId' => $refundId === null ? null : (string) $refundId];
            }
        }

        $lockedStatus = $order->status();

        if (! in_array($lockedStatus, $legalStartingStatuses, true)) {
            throw $refusalException($orderId, $lockedStatus);
        }

        // A bank transfer was received for this order and has not been reconciled (refunds R4a-2): the
        // money is in hand, and a cancel / return would void the pending payment and orphan it. Refused by
        // name until the receipt is settled or accepted (shipping-domain-design.md §7.2.20 §3).
        $this->paymentReceipts->assertReconciled($this->payments->findByOrderId($orderId));

        // A FACT checked for being possible, never for being on time (§7.2.6: no deadline is enforced):
        // the customer cannot have announced a return after it is recorded, nor before the order existed.
        // CALENDAR DAYS in the store timezone (a plain 'Y-m-d' compares as text), both bounds inclusive.
        $announcedReturnOn = $refundRequest?->announcedReturnOn;

        if ($announcedReturnOn !== null) {
            if ($announcedReturnOn > $this->storeTimezone->dayOf($occurredAt)) {
                throw ReturnAnnouncedDateException::inFuture();
            }

            if ($announcedReturnOn < $this->storeTimezone->dayOf($order->placedAt())) {
                throw ReturnAnnouncedDateException::beforePlacement();
            }
        }

        // The order's TRUE lines (order-editing-design.md §4.4), not just
        // its placement transaction's: an edited order's original lines
        // may have been reversed away and replaced by lines in an edit's
        // own transaction. A line whose units are wholly or partly
        // returned is still listed — the R7 arithmetic below and in
        // $resolveLines decides what remains.
        $saleLines = $this->currentLines->resolveWithReturns($order);

        $entries = $resolveLines($order, $saleLines);

        $returnTransaction = null;
        $keyWritten = false;
        $zero = Money::zero($order->currency());
        $totalRefundAmount = $zero;
        $breakdown = null;
        $goodsDifferFromComputed = false;

        if ($entries !== []) {
            $returnTransaction = $this->returnGoodsRecorder->record(
                $entries,
                $order->clientId(),
                $occurredAt,
                null,
                null,
                $reason,
                $refundRequest?->enteredGoodsByLine ?? [],
            );

            // The refund is made of what the merchant ENTERED: the goods amount
            // of each new REFUND line (its actualRefundAmount — the computed
            // share unless he entered another), plus a shipping refund, minus a
            // deduction. A deduction is a refund-level figure; it is on no line.
            $goods = $zero;
            $lineRows = [];

            foreach ($returnTransaction->saleLines() as $refundLine) {
                if (! $refundLine->actualRefundAmount()->equals($refundLine->defaultRefundAmount())) {
                    $goodsDifferFromComputed = true;
                }

                $goods = $goods->add($refundLine->actualRefundAmount());
                $lineRows[] = new RefundLine((string) $refundLine->originatingSaleLineId(), $refundLine->actualRefundAmount());
            }

            // The deduction cap is checked here, BEFORE the breakdown exists (which
            // would refuse a negative total with a bare InvalidArgumentException):
            // a named, translated refusal naming the room left.
            $this->capGuard->assertDeductionWithinGoodsAndShipping($goods, $refundRequest?->shipping ?? $zero, $refundRequest?->deduction ?? $zero);

            $breakdown = new RefundBreakdown(
                goods: $goods,
                shipping: $refundRequest?->shipping ?? $zero,
                adjustment: $zero,
                deduction: $refundRequest?->deduction ?? $zero,
                deductionReason: $refundRequest?->deductionReason,
                lines: $lineRows,
            );
            $totalRefundAmount = $breakdown->total();

            $this->events->record(
                orderId: $orderId,
                type: OrderEventType::RETURNED,
                fromStatus: null,
                toStatus: null,
                reason: $reason,
                transactionId: $returnTransaction->id(),
                occurredAt: $occurredAt,
                operationKey: $operationKey,
                operationPayloadHash: $operationKey !== null ? $payloadHash : null,
                announcedReturnOn: $announcedReturnOn,
            );
            $keyWritten = $operationKey !== null;
        }

        // R6/§2.3's compare-and-set — a FRESH read, under the same
        // lock, never the figures $resolveLines/ReturnGoodsRecorder
        // already used above.
        $allEmptied = true;
        foreach ($saleLines as $saleLine) {
            $remaining = $saleLine->quantity() - $this->saleLineRepository->sumQuantityReturnedForOriginatingLine($saleLine->id());
            if ($remaining > 0) {
                $allEmptied = false;
                break;
            }
        }

        $from = null;
        $to = null;

        if ($allEmptied) {
            $from = $lockedStatus;
            $to = $lockedStatus === OrderStatus::DELIVERED ? OrderStatus::REFUNDED : OrderStatus::CANCELLED;

            if ($to === OrderStatus::CANCELLED) {
                $order->cancel();
            } else {
                $order->refund();
            }

            $this->orders->save($order);

            $this->events->record(
                orderId: $orderId,
                type: OrderEventType::STATUS_CHANGED,
                fromStatus: $from,
                toStatus: $to,
                reason: $reason,
                transactionId: null,
                occurredAt: $occurredAt,
                operationKey: $keyWritten ? null : $operationKey,
                operationPayloadHash: $keyWritten || $operationKey === null ? null : $payloadHash,
            );
        }

        // R11: only a cancellation releases the promotion code — never
        // a refund (§7.4).
        if ($to === OrderStatus::CANCELLED) {
            $redemption = $this->promotionRedemptions->findByOrderId($orderId);

            if ($redemption !== null && ! $redemption->isReleased()) {
                $redemption->release($occurredAt);
                $this->promotionRedemptions->save($redemption);
            }
        }

        // R8/§7.3: the money half, only when the goods step above
        // actually moved money.
        $outcome = null;

        // A refund whose total is 0 creates NO PaymentRefund — the goods have
        // already moved above, and there is no money to record. The one exception
        // is a FULL cancel/return, which must still reach the refunder so a pending
        // payment is voided even when every returned unit was free (§7.2.4).
        if ($totalRefundAmount->isPositive() || ($allEmptied && $returnTransaction !== null)) {
            $outcome = $this->orderRefunder->refund(
                $orderId,
                $totalRefundAmount,
                $occurredAt,
                $reason,
                $breakdown,
                $refundRequest?->channel,
                fullReturn: $allEmptied,
                goodsDifferFromComputed: $goodsDifferFromComputed,
            );

            if ($outcome->isRefunded()) {
                // An offline refund is OWED — decided, not yet paid back — and the
                // history says so; REFUNDED is for money that has actually left.
                $this->events->record(
                    orderId: $orderId,
                    type: $outcome->refund()->status() === PaymentRefundStatus::OWED ? OrderEventType::REFUND_OWED : OrderEventType::REFUNDED,
                    fromStatus: null,
                    toStatus: null,
                    reason: $reason,
                    transactionId: $returnTransaction->id(),
                    occurredAt: $occurredAt,
                    paymentRefundId: $outcome->refund()->id(),
                );
            } elseif ($outcome->isVoided()) {
                $this->events->record(
                    orderId: $orderId,
                    type: OrderEventType::PAYMENT_VOIDED,
                    fromStatus: null,
                    toStatus: null,
                    reason: $reason,
                    transactionId: $returnTransaction->id(),
                    occurredAt: $occurredAt,
                );
            }
        }

        return [
            'order' => $order,
            'returnTransaction' => $returnTransaction,
            'from' => $from,
            'to' => $to,
            'outcome' => $outcome,
        ];
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
        // §10 stage 7b: this used to be an inline copy of §4.5's own
        // predicate — now Payment::isConfirmable() itself, extracted
        // because a real second caller (the admin panel's "Mark as
        // received" action) needed to ask the same question without a
        // second inline copy. No behaviour change: the formula is
        // byte-for-byte what this closure computed before.
        $confirmable = array_values(array_filter(
            $this->payments->findByOrderId($orderId),
            static fn (Payment $payment): bool => $payment->isConfirmable(),
        ));

        if (count($confirmable) !== 1) {
            return null;
        }

        return $this->paymentConfirmer->confirmWithinOpenTransaction($confirmable[0]->id(), $occurredAt);
    }
}
