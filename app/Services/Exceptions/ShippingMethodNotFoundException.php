<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** The method (or the zone it was to be created in) is gone — another operator deleted it. Translated; nothing was written. */
final class ShippingMethodNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('shipping.methods.not_found'));
    }
}
