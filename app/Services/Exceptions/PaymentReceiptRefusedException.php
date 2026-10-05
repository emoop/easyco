<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Recording a bank-transfer receipt (shipping-domain-design.md §7.2.20 §3) was refused, by name.
 * Nothing has been written when this is thrown. The message is a translated sentence
 * (orders.payment_receipt.refused.*, en + bg); field() names the form field a dialog should attach
 * it to ('amount', 'received_on', 'bank_reference'), or null for a refusal about the payment itself.
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
