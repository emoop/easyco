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
 * $status, $providerReference AND $failureReason ARE THE ONLY MUTABLE
 * FIELDS — deliberately, via recordAttemptResult() below, and for
 * exactly one reason: CheckoutOrchestrator now writes this row INSIDE
 * Phase 1 (checkout-domain-design.md §8.3) as PENDING, before the actual
 * charge is even attempted, so that a crash between commit and the
 * external call leaves a real, findable row rather than nothing at all.
 * recordAttemptResult() is how Phase 2 fills in what the adapter actually
 * said, once it's known. Every other field stays readonly and set once
 * at construction — this is NOT a general mutation door.
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
     * attemptedAt always starts null — a freshly created Payment has not
     * yet had an adapter answer for it (see class docblock).
     */
    public static function create(
        string $orderId,
        string $method,
        Money $amount,
        PaymentStatus $status,
        ?string $providerReference = null,
        ?string $failureReason = null,
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
}
