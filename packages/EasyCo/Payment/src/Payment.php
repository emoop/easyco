<?php

namespace EasyCo\Payment;

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
 */
final class Payment
{
    private function __construct(
        private ?string $id,
        private readonly string $orderId,
        private readonly string $method,
        private readonly Money $amount,
        private readonly PaymentStatus $status,
        private readonly ?string $providerReference,
        private readonly ?string $failureReason,
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
    ): self {
        return new self(
            id: $id,
            orderId: $orderId,
            method: $method,
            amount: $amount,
            status: $status,
            providerReference: $providerReference,
            failureReason: $failureReason,
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
}
