<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** The class (or the product / variation an assignment was meant for) is gone — another operator deleted it. Translated; nothing was written. */
final class ShippingClassNotFoundException extends RuntimeException
{
    public function __construct(bool $target = false)
    {
        parent::__construct(__($target ? 'shipping.classes.target_not_found' : 'shipping.classes.not_found'));
    }
}
