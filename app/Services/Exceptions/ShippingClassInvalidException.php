<?php

namespace App\Services\Exceptions;

use InvalidArgumentException;

/**
 * A shipping class (or an assignment of one) was refused by the shipping-class services: one or more FIELDS are
 * wrong. The messages are already translated sentences; the keys are the form's own field names (`name`, `code`,
 * `description`, `shipping_class`). Thrown before anything is written.
 */
final class ShippingClassInvalidException extends InvalidArgumentException
{
    /** @param  array<string, list<string>>  $errors  field => translated messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0][0] ?? __('shipping.classes.errors.invalid')));
    }

    /** The first message of a field, or null. */
    public function messageFor(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
