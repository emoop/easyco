<?php

namespace EasyCo\Payment\Exceptions;

use EasyCo\Payment\Enums\PaymentRefundStatus;
use InvalidArgumentException;

/**
 * A PaymentRefund state transition (OWED -> PAID_OUT, OWED -> CANCELLED) was
 * refused (shipping-domain-design.md §7.2.5, §7.2.16). The reason is a closed
 * code so the application layer can name and translate it; the message never
 * repeats a free-text value.
 */
final class InvalidRefundTransitionException extends InvalidArgumentException
{
    public const NOT_OWED = 'not_owed';

    public const PAYOUT_IN_FUTURE = 'payout_in_future';

    public const BANK_REFERENCE_REQUIRED = 'bank_reference_required';

    public const REASON_REQUIRED = 'reason_required';

    public const ACTOR_REQUIRED = 'actor_required';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notOwed(PaymentRefundStatus $status): self
    {
        return new self(self::NOT_OWED, "PaymentRefund can only leave the OWED state; it is {$status->value}.");
    }

    public static function payoutInFuture(): self
    {
        return new self(self::PAYOUT_IN_FUTURE, 'PaymentRefund payout date must not be in the future.');
    }

    public static function bankReferenceRequired(): self
    {
        return new self(self::BANK_REFERENCE_REQUIRED, 'PaymentRefund paid out through the bank requires a payment reference.');
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 'PaymentRefund cancellation requires a reason.');
    }

    public static function actorRequired(): self
    {
        return new self(self::ACTOR_REQUIRED, 'PaymentRefund transition requires the staff member who did it.');
    }
}
