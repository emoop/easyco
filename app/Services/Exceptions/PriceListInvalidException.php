<?php

namespace App\Services\Exceptions;

use InvalidArgumentException;

/**
 * A price-list campaign typed in the admin form was refused by PriceListWriter: one or more FIELDS are wrong. The messages are already translated sentences; the keys are the form's own field names. Thrown before
 * anything is written (and a write that fails midway is rolled back as a whole).
 */
final class PriceListInvalidException extends InvalidArgumentException
{
    /** @param  array<string, list<string>>  $errors  field => translated messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0][0] ?? __('price_lists.errors.invalid')));
    }
}
