<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** The zone is gone (another operator deleted it between the screen and the click). Translated; nothing was written. */
final class ShippingZoneNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('shipping.zones.not_found'));
    }
}
