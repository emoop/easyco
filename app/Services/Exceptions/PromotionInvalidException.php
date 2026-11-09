<?php

namespace App\Services\Exceptions;

use InvalidArgumentException;

/**
 * A promotion typed in the admin form was refused by PromotionWriter: one or more FIELDS are wrong. The messages are already translated sentences; the keys are the form's own field names. Thrown before anything is
 * written (the whole write is one transaction besides).
 */
final class PromotionInvalidException extends InvalidArgumentException
{
    /** @param  array<string, list<string>>  $errors  field => translated messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (array_values($errors)[0][0] ?? __('promotions.errors.invalid')));
    }
}
