<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The quote service cannot quote this cart to this destination
 * (shipping-domain-design.md §6.7). A named refusal with a machine-readable
 * reason, so the HTTP layer (stage 3d part 2) can map each one: the message is
 * for logs, the reason code is the contract. It carries no personal data.
 *
 *  - empty_cart               the cart has no lines;
 *  - no_priced_lines          it has lines, but none has a price (nothing to ship a price for);
 *  - no_zone_for_destination  no shipping zone covers the destination;
 *  - address_incomplete       a saved address with no country (AddressResolver's own rule);
 *  - address_not_found        an unknown address, or one belonging to another account
 *                             (indistinguishable on purpose; part 2 maps it to 404).
 */
final class ShippingQuoteRefusedException extends RuntimeException
{
    public const EMPTY_CART = 'empty_cart';

    public const NO_PRICED_LINES = 'no_priced_lines';

    public const NO_ZONE_FOR_DESTINATION = 'no_zone_for_destination';

    public const ADDRESS_INCOMPLETE = 'address_incomplete';

    public const ADDRESS_NOT_FOUND = 'address_not_found';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("Shipping quote refused: {$reason}.", 0, $previous);
    }
}
