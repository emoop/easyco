<?php

namespace EasyCo\Order\Enums;

/**
 * DELIBERATELY DUPLICATED FROM EasyCo\Address\Enums\AddressDeliveryType,
 * NOT IMPORTED — do not "helpfully" replace this with a shared import.
 * Order never depends on the Address package at the code level
 * (checkout-domain-design.md §4: "Order never depends on the Address
 * package — the embedded snapshot fields are Order's own columns, not
 * a foreign read"), the same reasoning EasyCo\Promotions\PromotionScope
 * already used to justify duplicating EasyCo\Pricing\PriceListScope's
 * shape rather than sharing code with it.
 */
enum OrderDeliveryType: string
{
    case STREET_ADDRESS = 'street_address';
    case PICKUP_POINT = 'pickup_point';
}
