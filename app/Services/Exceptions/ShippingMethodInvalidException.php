<?php

namespace App\Services\Exceptions;

use InvalidArgumentException;

/**
 * A shipping method (or a copy request) was refused by the shipping-method services (shipping-domain-design.md
 * §12.6): one or more FIELDS are wrong. The messages are already translated sentences; the keys are the form's own
 * field names (`name`, `kind`, `price`, `free_above`, `class_mode`, `class_rates`, `carrier_code`, `zone_id`,
 * `zones`), so a screen can put each one on its field and a test can read them. Thrown before anything is written.
 */
final class ShippingMethodInvalidException extends InvalidArgumentException
{
    /** @param  array<string, list<string>>  $errors  field => translated messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0][0] ?? __('shipping.methods.errors.invalid')));
    }

    /** The first message of a field, or null. */
    public function messageFor(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
