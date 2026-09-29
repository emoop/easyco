<?php

namespace EasyCo\Payment;

use DateTimeImmutable;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;

/**
 * A payment attempt against an order — see payment-domain-design.md §2
 * for the full field list and reasoning. Mirrors
 * EasyCo\Promotions\Promotion's shape: private constructor, named
 * assertion methods, a public create() factory, reconstituteFromStorage()
 * for the persistence layer, and a one-time assignId().
 *
 * orderId IS A GENUINE FORWARD REFERENCE — the Order domain does not
 * exist yet (design doc §6). It is a required, non-empty plain string
 * regardless, same cross-domain-by-id posture every other reference in
 * this project takes.
 *
 * Money DOES NOT GUARD AGAINST ZERO/NEGATIVE AMOUNTS AT CONSTRUCTION
 * (checked directly against EasyCo\Pricing\Money) — this class enforces
 * amount->isPositive() itself, the same way every other consumer of
 * Money that needs a strictly-positive value must (see
 * assertPositiveAmount()).
 *
 * $status, $providerReference, $failureReason, $confirmedAt AND $voidedAt
 * ARE THE MUTABLE FIELDS — five fields, three one-time mutators, and
 * nothing else in this class ever moves after construction:
 *  - recordAttemptResult() records what the ADAPTER answered, once.
 *    CheckoutOrchestrator writes this row INSIDE Phase 1
 *    (checkout-domain-design.md §8.3) as PENDING, before the actual charge
 *    is even attempted, so that a crash between commit and the external
 *    call leaves a real, findable row rather than nothing at all; Phase 2
 *    fills in the answer once it is known.
 *  - confirm() records that a MERCHANT saw the money arrive, once
 *    (order-lifecycle-design.md §4.1) — the half no adapter can answer for
 *    a bank transfer or a cash-on-delivery order.
 *  - void() records that the ORDER'S REQUIREMENT shrank afterwards, once
 *    (that document's §7.3) — the row that carried the old obligation stops
 *    being the current one (see $voidedAt below).
 * Each of the three is one-time and refuses a second call with a
 * LogicException. Every other field stays readonly and set once at
 * construction — this is NOT a general mutation door.
 *
 * status, attemptedAt, confirmedAt AND voidedAt ARE FOUR DIFFERENT FACTS,
 * NOT FOUR WAYS OF SAYING ONE (payment-domain-design.md §2, §7.3): what the
 * adapter answered, when it answered, when a human recorded the money, and
 * when the obligation was called off. Neither of the last two is a
 * PaymentStatus, deliberately — PaymentStatus's own docblock argues against
 * exactly that addition, and a confirmation that flipped status would
 * silently widen captured_order_id's DB guarantee instead of leaving it
 * meaning what it documents (order-lifecycle-design.md §4.2).
 *
 * THIS DOES NOT VIOLATE payment-domain-design.md §1'S APPEND-ONLY RULE —
 * a real distinction, not a loophole, worth stating explicitly: a RETRY
 * is still a brand-new Payment row, untouched by this change. What
 * recordAttemptResult() adds is recording the outcome of THE SAME
 * ATTEMPT that is already underway — one attempt, one row, whose result
 * is written exactly once, when it becomes known.
 *
 * $attemptedAt RECORDS WHEN THE ADAPTER ANSWERED — not when the payment
 * "resolved" or "finished": for cash-on-delivery/bank-transfer nothing
 * is finished the moment the adapter answers, the money still hasn't
 * arrived. This is deliberately the narrower, honest claim. It also
 * answers a real, standing support question this entity previously had
 * no way to answer at all — "when did we learn this payment failed?" —
 * not merely crash-detection scaffolding.
 *
 * THE TWO PREVIOUSLY-INDISTINGUISHABLE STATES THIS FIELD SEPARATES:
 * - status PENDING + attemptedAt NULL — the charge attempt never
 *   completed (a crash, a timeout, a Phase 2 that never ran). Needs a
 *   retry.
 * - status PENDING + attemptedAt SET — a normal offline order genuinely
 *   awaiting the customer's money. Needs a merchant confirmation later
 *   (payment-domain-design.md §7), not a retry.
 *
 * A KNOWN, STATED LIMIT — not hidden: if a crash happens AFTER the
 * adapter has answered but BEFORE recordAttemptResult()'s save()
 * completes, $attemptedAt stays null and the row still looks like "never
 * attempted." For V1's two adapters this risk is nil — they call no
 * external system, so nothing can have half-happened. For a future real
 * online provider this is the classic unsolvable case without a
 * provider-side idempotency key; whoever adds that adapter must handle
 * it there, not here.
 */
final class Payment
{
    private function __construct(
        private ?string $id,
        private readonly string $orderId,
        private readonly string $method,
        private readonly Money $amount,
        private PaymentStatus $status,
        private ?string $providerReference,
        private ?string $failureReason,
        private ?DateTimeImmutable $attemptedAt,
        private ?DateTimeImmutable $confirmedAt = null,
        private ?DateTimeImmutable $voidedAt = null,
    ) {
        self::assertNotEmpty('orderId', $orderId);
        self::assertNotEmpty('method', $method);
        self::assertPositiveAmount($amount);
        self::assertFailureReasonMatchesStatus($status, $failureReason);
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Payment {$fieldName} must not be empty.");
        }
    }

    private static function assertPositiveAmount(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Payment amount must be positive; zero or negative amounts are rejected.');
        }
    }

    /**
     * Only the one direction is enforced: a non-null failureReason
     * implies status = FAILED. The reverse is NOT required — a FAILED
     * Payment may have no known failureReason — per design doc §8's
     * "only meaningful when FAILED" phrasing (not "always set when
     * FAILED").
     */
    private static function assertFailureReasonMatchesStatus(PaymentStatus $status, ?string $failureReason): void
    {
        if ($failureReason !== null && $status !== PaymentStatus::FAILED) {
            throw new InvalidArgumentException('Payment failureReason may only be set when status is FAILED.');
        }
    }

    /**
     * attemptedAt/confirmedAt/voidedAt always start null — a freshly created
     * Payment has not yet had an adapter answer for it, no merchant has
     * recorded its money and no obligation has been called off (see class
     * docblock). The two last parameters exist so a caller can thread
     * already-known facts through the factory the same way it threads
     * providerReference/failureReason; nothing in this codebase creates a
     * Payment with a confirmation or a void, and every existing call site
     * keeps working unchanged because both are last and defaulted.
     */
    public static function create(
        string $orderId,
        string $method,
        Money $amount,
        PaymentStatus $status,
        ?string $providerReference = null,
        ?string $failureReason = null,
        ?DateTimeImmutable $confirmedAt = null,
        ?DateTimeImmutable $voidedAt = null,
    ): self {
        return new self(
            id: null,
            orderId: $orderId,
            method: $method,
            amount: $amount,
            status: $status,
            providerReference: $providerReference,
            failureReason: $failureReason,
            attemptedAt: null,
            confirmedAt: $confirmedAt,
            voidedAt: $voidedAt,
        );
    }

    /**
     * Reconstitutes a Payment exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $orderId,
        string $method,
        Money $amount,
        PaymentStatus $status,
        ?string $providerReference,
        ?string $failureReason,
        ?DateTimeImmutable $attemptedAt,
        ?DateTimeImmutable $confirmedAt = null,
        ?DateTimeImmutable $voidedAt = null,
    ): self {
        return new self(
            id: $id,
            orderId: $orderId,
            method: $method,
            amount: $amount,
            status: $status,
            providerReference: $providerReference,
            failureReason: $failureReason,
            attemptedAt: $attemptedAt,
            confirmedAt: $confirmedAt,
            voidedAt: $voidedAt,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Payment already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function attemptedAt(): ?DateTimeImmutable
    {
        return $this->attemptedAt;
    }

    /**
     * When a merchant recorded this attempt's money as received
     * (order-lifecycle-design.md §4.1) — null until confirm() runs, and
     * never cleared afterwards. Not "when the adapter answered" (that is
     * attemptedAt() above) and not "when the money arrived": it is when the
     * fact was recorded, the same honest, narrower claim attemptedAt makes
     * for the adapter's own answer.
     */
    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /**
     * When this row stopped being the order's current payment because the
     * obligation it carried was called off (order-lifecycle-design.md §7.3).
     * The row stays, in full, for the payment trail — a reissued row is a
     * NEW payment, never an edit of this one.
     */
    public function voidedAt(): ?DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /**
     * The ONE place "money is held on this payment" is decided
     * (order-lifecycle-design.md §4.1, §11 item 17): the adapter captured
     * it, or a merchant recorded the money as received. Every rule that
     * means "settled" — refusing a second settlement, a refund's target, a
     * shipment's precondition, the panel's payment block — calls this
     * instead of re-deriving the two-part predicate, or the guard and the
     * badge eventually disagree with each other.
     *
     * voidedAt IS DELIBERATELY NOT PART OF THIS PREDICATE: a void only ever
     * happens on a row that was never settled (see void()'s guards), so the
     * two can never describe the same row through any write path — and
     * adding the term here would let a "settled but voided" row, which no
     * caller can create, silently read as unsettled.
     */
    public function isSettled(): bool
    {
        return $this->status === PaymentStatus::CAPTURED || $this->confirmedAt !== null;
    }

    /**
     * EXACTLY THE STATE confirm() ITSELF ACCEPTS — §4.5's table
     * (order-lifecycle-design.md), extracted as a real accessor so a caller
     * can ask "would this succeed" without duplicating confirm()'s own four
     * guard conditions inline (§10 stage 7b). `status !== CAPTURED &&
     * status !== FAILED` there is `status === PENDING` here: PaymentStatus
     * has exactly those three cases, so the two are the same predicate,
     * stated the way confirm()'s own guards state it.
     *
     * App\Services\OrderStatusChanger::confirmDeliveryPaymentIfEligible()
     * (R10) is the first caller — previously an inline copy of this exact
     * formula, found byte-for-byte identical when extracted (not a
     * "subtly different" duplicate).
     */
    public function isConfirmable(): bool
    {
        return $this->status === PaymentStatus::PENDING
            && $this->attemptedAt !== null
            && $this->confirmedAt === null
            && ! $this->isVoided();
    }

    /**
     * Records the outcome of THIS attempt, once. Only ever called after
     * this Payment was created as the "we are about to charge, result
     * not yet known" placeholder (checkout-domain-design.md §8.3) —
     * calling it a second time throws: re-recording an already-recorded
     * attempt would silently rewrite history.
     *
     * THE GUARD IS ON attemptedAt, NOT ON status — deliberately.
     * PENDING is a legitimate, final answer for an offline method (both
     * V1 adapters always return it), so status alone can never tell a
     * resolved attempt from an unresolved one; that indistinguishability
     * is exactly the gap $attemptedAt exists to close. There is
     * therefore no restriction here on which PaymentStatus may be
     * recorded — PENDING, CAPTURED and FAILED are all valid outcomes of
     * an attempt that genuinely happened.
     *
     * NOT A RETRY MECHANISM — a retry is a NEW Payment row, per
     * payment-domain-design.md §1's own append-only rule (see class
     * docblock). This only fills in the result of an attempt that was
     * already recorded as in-flight.
     */
    public function recordAttemptResult(
        PaymentStatus $status,
        ?string $providerReference,
        ?string $failureReason,
        DateTimeImmutable $attemptedAt,
    ): void {
        if ($this->attemptedAt !== null) {
            throw new LogicException(
                "Payment attempt already recorded at {$this->attemptedAt->format(DATE_ATOM)}; recordAttemptResult() is a one-time operation."
            );
        }

        self::assertFailureReasonMatchesStatus($status, $failureReason);

        $this->status = $status;
        $this->providerReference = $providerReference;
        $this->failureReason = $failureReason;
        $this->attemptedAt = $attemptedAt;
    }

    /**
     * Records that the money this attempt was for actually arrived, once —
     * a MERCHANT's fact, on a row an ADAPTER already answered
     * (order-lifecycle-design.md §4.1). This is the offline half of "the
     * money is held": for cash on delivery and bank transfer the adapter's
     * own answer is PENDING and always will be, so without this call an
     * offline-paid order can never be told apart from one whose money never
     * came.
     *
     * IT MOVES $status NOWHERE (§4.2) — deliberately, and it writes exactly
     * one field, $confirmedAt. The row is the record of what the adapter
     * said; flipping it to CAPTURED would replace that answer with a
     * human's, lose the attemptedAt distinction the column exists for, and
     * silently widen captured_order_id's DB guarantee to cover offline
     * rows. Whether the money is held is isSettled()'s question, and it
     * answers it from both facts.
     *
     * ONE-TIME, like assignId() and recordAttemptResult(): a second call
     * throws, because recording the same money fact twice is a caller bug
     * (a race or a defect), not a no-op. There is no un-confirm anywhere
     * (§11 item 6) — a confirmation recorded against the wrong order is
     * corrected by the money trail, never by clearing this column.
     *
     * THE GUARDS, all LogicException, all visible on the aggregate itself
     * (so no caller needs a read of its own to know what is confirmable):
     *  - $attemptedAt must be set. PENDING + NULL attemptedAt is the state
     *    attemptedAt was added to expose — a crashed or never-answered
     *    attempt — and confirming it would record money against an attempt
     *    whose outcome nobody knows.
     *  - the status may not be CAPTURED: the adapter already settled it.
     *  - the status may not be FAILED: the adapter answered no, there is no
     *    money, and "confirming" it would be a refund waiting to happen.
     *  - the row may not be voided: a called-off obligation is not a
     *    payment, so confirming one would record money against a row the
     *    order no longer owes on.
     * The only state confirm() accepts is therefore PENDING with an
     * answered attempt.
     */
    public function confirm(DateTimeImmutable $confirmedAt): void
    {
        if ($this->confirmedAt !== null) {
            throw new LogicException(
                "Payment was already confirmed at {$this->confirmedAt->format(DATE_ATOM)}; confirm() is a one-time operation."
            );
        }

        if ($this->attemptedAt === null) {
            throw new LogicException(
                'Payment attempt was never answered (attemptedAt is null) — money cannot be recorded as received against an attempt nobody knows the outcome of.'
            );
        }

        if ($this->status === PaymentStatus::CAPTURED) {
            throw new LogicException(
                'Payment was already settled by the adapter (status captured); confirm() is the offline half and has nothing to add.'
            );
        }

        if ($this->status === PaymentStatus::FAILED) {
            throw new LogicException(
                'Payment attempt failed; there is no money to record as received.'
            );
        }

        if ($this->voidedAt !== null) {
            throw new LogicException(
                "Payment was voided at {$this->voidedAt->format(DATE_ATOM)}; a called-off obligation cannot be confirmed — the order owes on a newer payment row."
            );
        }

        $this->confirmedAt = $confirmedAt;
    }

    /**
     * Records that the ORDER'S REQUIREMENT shrank, once — the row stops
     * being the order's current payment (order-lifecycle-design.md §7.3,
     * §11 item 19). It is NOT a fourth status and NOT the attempt's
     * outcome: the attempt was answered PENDING and always will have been;
     * what changed is that the customer was never told the amount this row
     * carries, so the money owed is corrected by appending a NEW payment
     * row, never by editing this one (payment-domain-design.md §1's
     * append-only rule). The annotation on the OLD row is what says it is
     * no longer current.
     *
     * ONE-TIME, like every other mutator here: a second call throws.
     *
     * THE GUARDS, all LogicException: a void applies to a normal offline
     * order awaiting its money, so the status must be PENDING, $attemptedAt
     * must be set (the same crashed/never-answered distinction confirm()
     * makes, read from the other side), the row must not already be settled
     * — isSettled(): a captured row, or one whose money a merchant already
     * recorded, is REFUNDED, never voided, because money that really moved
     * has to move back — and it must not already be voided.
     *
     * IT MOVES $status NOWHERE and touches neither generated column: a
     * voided row is always a pending, unconfirmed one, so it keeps
     * contributing NULL to captured_order_id and settled_order_id and can
     * never interact with either unique index.
     */
    public function void(DateTimeImmutable $voidedAt): void
    {
        if ($this->voidedAt !== null) {
            throw new LogicException(
                "Payment was already voided at {$this->voidedAt->format(DATE_ATOM)}; void() is a one-time operation."
            );
        }

        if ($this->isSettled()) {
            throw new LogicException(
                'Payment is settled (status captured, or a confirmation is on record) — money that really moved is refunded, never voided.'
            );
        }

        if ($this->status === PaymentStatus::FAILED) {
            throw new LogicException(
                'Payment attempt failed; there is no outstanding obligation to call off.'
            );
        }

        if ($this->attemptedAt === null) {
            throw new LogicException(
                'Payment attempt was never answered (attemptedAt is null) — void() applies to a normal offline order awaiting its money, not to a crashed attempt.'
            );
        }

        $this->voidedAt = $voidedAt;
    }
}
