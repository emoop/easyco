<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Accepting a bank transfer that is short or over (refunds R4a-3, shipping-domain-design.md §7.2.20 §4a, §5)
 * needs the `payment_reconcile` permission — a money decision: it gives money away or takes it on — and the
 * acting staff member does not hold it, or there is no acting staff member at all (fail closed). The service
 * enforces this itself, so a caller that bypasses the panel's visibility rules is stopped here. The message
 * is a translated sentence (orders.payment_receipt.reconcile_denied); nothing has been written.
 */
final class PaymentReconcilePermissionDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('orders.payment_receipt.reconcile_denied'));
    }
}
