<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A saved address chosen at checkout has no country: a historical PICKUP_POINT
 * row saved before the delivery country became mandatory on every address
 * (owner decision D1, shipping stage 3.0b). An Order cannot be created from it,
 * and nothing may guess a country, so checkout refuses BEFORE any write; the
 * customer updates the address, the merchant can run
 * `addresses:backfill-pickup-country`. The message is translated
 * (delivery.address_incomplete) and carries no personal data.
 */
final class AddressIncompleteForCheckoutException extends RuntimeException
{
    public function __construct(public readonly string $addressId)
    {
        parent::__construct(__('delivery.address_incomplete'));
    }
}
