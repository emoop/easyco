<?php

namespace App\Services\Exceptions;

use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Staff\Enums\Permission;
use RuntimeException;

/**
 * Recording a refund on a SETTLED payment needs the permission of its payout
 * channel — REFUND_CASH for cash, REFUND_BANK for bank (shipping-domain-design.md
 * §7.2.8) — and the acting staff member does not hold it, or there is no acting
 * staff member at all (fail closed). The services enforce this themselves, so a
 * caller that bypasses the panel's visibility rules is stopped here.
 */
final class RefundPermissionDeniedException extends RuntimeException
{
    public function __construct(public readonly RefundChannel $channel, public readonly Permission $permission)
    {
        parent::__construct(__('orders.refund_permission_denied.'.$channel->value));
    }
}
