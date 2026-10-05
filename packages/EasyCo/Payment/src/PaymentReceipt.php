<?php

namespace EasyCo\Payment;

use DateTimeImmutable;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;

/**
 * One bank transfer a merchant saw arrive (shipping-domain-design.md §7.2.20 §2) — an immutable
 * fact, APPEND-ONLY: it is never edited or deleted; a correction is a NEW receipt that
 * `supersedes` this one, and a receipt is EFFECTIVE unless a later one names it. The only thing
 * that ever changes on the object is its id, assigned once when it is stored.
 *
 *  - amount: strictly positive, in the payment's currency (the service layer checks the currency
 *    against the payment; a value object has no payment to compare with);
 *  - receivedOn: a CALENDAR DAY, a plain 'Y-m-d' string — the store-timezone day the money
 *    arrived, never an instant (validating "not in the future" and "not before the order was
 *    placed" needs the store timezone and the order, so that is the service's job, R4a-2);
 *  - bankReference: what the merchant reconciles against the bank statement — trimmed, 1 to 64
 *    characters (the column's width);
 *  - recordedAt: the UTC instant the fact was recorded; recordedBy: the staff member, if any.
 *
 * Pure PHP (CLAUDE.md rule 1).
 */
final class PaymentReceipt
{
    public const MAX_REFERENCE_LENGTH = 64;

    public const MAX_RECORDED_BY_LENGTH = 255;

    private function __construct(
        private ?string $id,
        private readonly string $paymentId,
        private readonly Money $amount,
        private readonly string $receivedOn,
        private readonly string $bankReference,
        private readonly ?string $supersedesReceiptId,
        private readonly ?string $recordedBy,
        private readonly DateTimeImmutable $recordedAt,
    ) {
        if (trim($paymentId) === '') {
            throw new InvalidArgumentException('PaymentReceipt paymentId must not be empty.');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('PaymentReceipt amount must be positive.');
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $receivedOn);

        if ($day === false || $day->format('Y-m-d') !== $receivedOn) {
            throw new InvalidArgumentException("PaymentReceipt receivedOn must be a real date written Y-m-d, got \"{$receivedOn}\".");
        }

        if (trim($bankReference) === '' || $bankReference !== trim($bankReference) || mb_strlen($bankReference) > self::MAX_REFERENCE_LENGTH) {
            throw new InvalidArgumentException('PaymentReceipt bankReference must be trimmed, not blank, and at most '.self::MAX_REFERENCE_LENGTH.' characters.');
        }

        if ($supersedesReceiptId !== null && trim($supersedesReceiptId) === '') {
            throw new InvalidArgumentException('PaymentReceipt supersedesReceiptId must not be an empty string.');
        }

        if ($recordedBy !== null && mb_strlen($recordedBy) > self::MAX_RECORDED_BY_LENGTH) {
            throw new InvalidArgumentException('PaymentReceipt recordedBy must be at most '.self::MAX_RECORDED_BY_LENGTH.' characters.');
        }
    }

    /** A new receipt, not yet stored. The reference is trimmed here, so callers may pass what was typed. */
    public static function create(
        string $paymentId,
        Money $amount,
        string $receivedOn,
        string $bankReference,
        DateTimeImmutable $recordedAt,
        ?string $recordedBy = null,
        ?string $supersedesReceiptId = null,
    ): self {
        return new self(null, $paymentId, $amount, $receivedOn, trim($bankReference), $supersedesReceiptId, $recordedBy, $recordedAt);
    }

    /** PERSISTENCE-LAYER ONLY — trusts the repository that the row is already valid. */
    public static function reconstituteFromStorage(
        string $id,
        string $paymentId,
        Money $amount,
        string $receivedOn,
        string $bankReference,
        ?string $supersedesReceiptId,
        ?string $recordedBy,
        DateTimeImmutable $recordedAt,
    ): self {
        return new self($id, $paymentId, $amount, $receivedOn, $bankReference, $supersedesReceiptId, $recordedBy, $recordedAt);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('PaymentReceipt already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    /** The calendar day ('Y-m-d') the money arrived, in the store timezone. */
    public function receivedOn(): string
    {
        return $this->receivedOn;
    }

    public function bankReference(): string
    {
        return $this->bankReference;
    }

    /** The receipt this one replaces, if it is a correction. */
    public function supersedesReceiptId(): ?string
    {
        return $this->supersedesReceiptId;
    }

    public function recordedBy(): ?string
    {
        return $this->recordedBy;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
