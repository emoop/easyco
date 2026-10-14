<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The chosen payment method is BLOCKABLE and an active block matches the request (cod-protection-design.md section 4): thrown inside the
 * placement transaction, so nothing is written. It deliberately carries NOTHING: not the identifier, not even which of the three matched, so
 * that neither a log line nor a response can be used to probe the list of blocks.
 */
final class PaymentMethodBlockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The payment method is not available for this order.');
    }
}
