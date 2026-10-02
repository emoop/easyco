<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A carrier provider answered with something that is not a valid answer to the
 * question (an item of the wrong type, a quote in another currency, a pickup point
 * of another country). Internal to CarrierCallGuard, which turns it into
 * "unavailable". The message names no data from the answer.
 */
final class InvalidCarrierResponseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The carrier provider returned an invalid answer.');
    }
}
