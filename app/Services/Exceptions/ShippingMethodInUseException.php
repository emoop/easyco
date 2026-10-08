<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A method that something still depends on cannot be deleted: the merchant deactivates it instead. Today nothing
 * references a method by foreign key (a placed order keeps its own snapshot of the name, code and amount), so a
 * delete is free; this exception exists for the day a row does — a database refusal of the delete is translated
 * to it, never shown raw. The message is a translated sentence suggesting "deactivate instead"; nothing was
 * written.
 */
final class ShippingMethodInUseException extends RuntimeException
{
    public function __construct(public readonly string $methodName)
    {
        parent::__construct(__('shipping.methods.in_use', ['name' => $methodName]));
    }
}
