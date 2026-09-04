<?php

namespace EasyCo\Payment\Enums;

/**
 * A PaymentRefund's lifecycle status — see payment-domain-design.md §3.
 * A refund against an online method may itself round-trip through a
 * provider and can fail; a refund against an offline method has no
 * external system to fail against and is COMPLETED immediately.
 */
enum PaymentRefundStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
