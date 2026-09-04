<?php

namespace EasyCo\Payment\Enums;

/**
 * A Payment's lifecycle status — see payment-domain-design.md §2. No
 * holding/reservation state beyond PENDING (that document's own note,
 * mirroring inventory-domain-design.md's own lack of one): an attempt
 * either captures or it doesn't.
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case CAPTURED = 'captured';
    case FAILED = 'failed';
}
