<?php

namespace EasyCo\Payment\Enums;

/**
 * A Payment's lifecycle status — see payment-domain-design.md §2. No
 * holding/reservation state beyond PENDING (that document's own note,
 * mirroring inventory-domain-design.md's own lack of one): an attempt
 * either captures or it doesn't. This still holds even though Payment
 * gained an attemptedAt field (payment-domain-design.md §1's amendment
 * note) — attemptedAt records WHEN an attempt's outcome became known,
 * not a fourth outcome an attempt can reach; PENDING remains a fully
 * legitimate, final status for an offline method, distinguished from a
 * crashed/never-completed attempt by attemptedAt, not by adding a
 * status here.
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case CAPTURED = 'captured';
    case FAILED = 'failed';
}
