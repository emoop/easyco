<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A class that is still used cannot be deleted (shipping-domain-design.md §12.3.1): never a silent cascade. The
 * message is a translated sentence that says exactly where it is used — how many methods carry a rate for it and
 * how many variations are assigned to it — so the merchant knows what to clear. Nothing was written. The rate
 * table's restrict foreign key refuses it too; that is only the backstop.
 */
final class ShippingClassInUseException extends RuntimeException
{
    public function __construct(public readonly string $className, public readonly int $methodCount, public readonly int $variationCount)
    {
        parent::__construct(__('shipping.classes.in_use', [
            'name' => $className,
            'methods' => trans_choice('shipping.classes.methods_count', $methodCount, ['count' => $methodCount]),
            'variations' => trans_choice('shipping.classes.variations_count', $variationCount, ['count' => $variationCount]),
        ]));
    }
}
