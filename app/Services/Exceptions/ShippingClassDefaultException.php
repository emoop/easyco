<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The store's default class cannot be deleted (shipping stage 5e): new products and the assign-missing command rely
 * on it. The message is a translated sentence — make another class the default first. Nothing was written.
 */
final class ShippingClassDefaultException extends RuntimeException
{
    public function __construct(public readonly string $className)
    {
        parent::__construct(__('shipping.classes.default_delete_refused', ['name' => $className]));
    }
}
