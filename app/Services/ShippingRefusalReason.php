<?php

namespace App\Services;

/**
 * Why CheckoutShippingResolver refused a shipping choice (shipping stage 4d, shipping-domain-design.md §9.1.3).
 * EXACTLY these cases. The string values are the future checkout API `reason` codes; the HTTP status of each
 * (shipping_required / shipping_invalid / pickup_mismatch -> 422, the rest -> 409) and the translated sentences
 * belong to stage 4e, not here.
 */
enum ShippingRefusalReason: string
{
    /** The method id or the quote handle is missing. */
    case REQUIRED = 'shipping_required';

    /** The method id or the handle is malformed (checked before any lookup). */
    case INVALID = 'shipping_invalid';

    /** The method is not among the methods offered now for this cart and destination (missing, inactive, another zone, hidden by the merchant filter, a carrier that is not available). */
    case METHOD_UNAVAILABLE = 'shipping_method_unavailable';

    /** The handle is unknown, expired, tampered, or was issued for something else — deliberately not told apart. */
    case QUOTE_EXPIRED = 'shipping_quote_expired';

    /** The price is not the one the customer was shown; the exception carries the new figure. */
    case PRICE_CHANGED = 'shipping_price_changed';

    /** The method's pickup-point requirement disagrees with the destination. */
    case PICKUP_MISMATCH = 'shipping_pickup_mismatch';
}
