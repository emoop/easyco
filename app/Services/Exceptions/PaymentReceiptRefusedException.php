<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Recording, accepting or correcting a bank-transfer receipt (shipping-domain-design.md §7.2.20 §3, §4) was refused, by name.
 * Nothing has been written when this is thrown. The message is a translated sentence
 * (orders.payment_receipt.refused.*, en + bg); field() names the form field a dialog should attach
 * it to ('amount', 'received_on', 'bank_reference', 'reason', 'accepted_amount'), or null for a refusal about the payment itself.
 */
final class PaymentReceiptRefusedException extends RuntimeException
{
    // About the payment.
    public const NOT_BANK_TRANSFER = 'not_bank_transfer';

    public const PAYMENT_SETTLED = 'payment_settled';

    public const PAYMENT_VOIDED = 'payment_voided';

    public const PAYMENT_UNANSWERED = 'payment_unanswered';

    public const PAYMENT_NOT_CONFIRMABLE = 'payment_not_confirmable';

    public const TOO_MANY_EFFECTIVE_RECEIPTS = 'too_many_effective_receipts';

    public const TOO_MANY_RECEIPT_ROWS = 'too_many_receipt_rows';

    // Accepting a mismatch (R4a-3).
    public const NO_EFFECTIVE_RECEIPT = 'no_effective_receipt';

    public const NOTHING_TO_ACCEPT = 'nothing_to_accept';

    public const ACCEPTED_AMOUNT_CHANGED = 'accepted_amount_changed';

    // Correcting a receipt (R4a-3).
    public const RECEIPT_UNKNOWN = 'receipt_unknown';

    public const RECEIPT_NOT_EFFECTIVE = 'receipt_not_effective';

    public const NOTHING_TO_CORRECT = 'nothing_to_correct';

    public const RECEIPT_AMOUNT_CHANGE_AFTER_SETTLEMENT = 'receipt_amount_change_after_settlement';

    // About the reason of an acceptance or a correction.
    public const REASON_BLANK = 'reason_blank';

    public const REASON_TOO_LONG = 'reason_too_long';

    public const REASON_INVALID = 'reason_invalid';

    // About the amount.
    public const AMOUNT_NOT_POSITIVE = 'amount_not_positive';

    public const AMOUNT_TOO_LARGE = 'amount_too_large';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    // About the day.
    public const DAY_MALFORMED = 'day_malformed';

    public const DAY_IN_FUTURE = 'day_in_future';

    public const DAY_BEFORE_PLACEMENT = 'day_before_placement';

    // About the reference.
    public const REFERENCE_BLANK = 'reference_blank';

    public const REFERENCE_TOO_LONG = 'reference_too_long';

    public const REFERENCE_INVALID = 'reference_invalid';

    private const FIELDS = [
        self::ACCEPTED_AMOUNT_CHANGED => 'accepted_amount',
        self::RECEIPT_AMOUNT_CHANGE_AFTER_SETTLEMENT => 'amount',
        self::REASON_BLANK => 'reason',
        self::REASON_TOO_LONG => 'reason',
        self::REASON_INVALID => 'reason',
        self::AMOUNT_NOT_POSITIVE => 'amount',
        self::AMOUNT_TOO_LARGE => 'amount',
        self::CURRENCY_MISMATCH => 'amount',
        self::DAY_MALFORMED => 'received_on',
        self::DAY_IN_FUTURE => 'received_on',
        self::DAY_BEFORE_PLACEMENT => 'received_on',
        self::REFERENCE_BLANK => 'bank_reference',
        self::REFERENCE_TOO_LONG => 'bank_reference',
        self::REFERENCE_INVALID => 'bank_reference',
    ];

    public function __construct(public readonly string $reason, array $replace = [])
    {
        parent::__construct(__('orders.payment_receipt.refused.'.$reason, $replace));
    }

    /** The form field this refusal belongs to, or null when it is about the payment as a whole. */
    public function field(): ?string
    {
        return self::FIELDS[$this->reason] ?? null;
    }
}
