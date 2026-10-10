<?php

namespace App\Storefront\Exceptions;

use InvalidArgumentException;

/**
 * A ListingQuery input that is refused (never coerced). `field` names the offending key; the message never echoes
 * the raw value, so a hostile string cannot be reflected into a log line or an error page.
 */
final class InvalidListingQuery extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $reason)
    {
        parent::__construct("Invalid listing query: {$field} {$reason}.");
    }
}
