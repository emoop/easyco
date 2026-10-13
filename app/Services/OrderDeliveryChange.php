<?php

namespace App\Services;

use EasyCo\Order\Enums\OrderDeliveryType;

/**
 * The delivery half of one order edit (order-editing-design.md §1/§3): the
 * WHOLE replacement delivery snapshot, in the exact eleven fields
 * Order::reviseDelivery() takes — never a partial patch, because
 * reviseDelivery() replaces the snapshot atomically and re-runs the same
 * delivery-type exclusivity validation create() does.
 *
 * A plain carrier in the same shape as CheckoutInput (public readonly
 * fields, no behaviour). It exists so that OrderEditor::apply()'s nullable
 * `?OrderDeliveryChange $delivery` argument can mean, unambiguously,
 * "delivery unchanged by this edit" without eleven nullable parameters at
 * the top level — where a null could not say "leave this field alone" or
 * "clear this field" (a street order's postalCode is legitimately null).
 * Validation is Order's own job, not this class's.
 */
final class OrderDeliveryChange
{
    public function __construct(
        public readonly OrderDeliveryType $deliveryType,
        public readonly string $recipientFirstName,
        public readonly string $recipientLastName,
        public readonly string $phone,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $addressLine1 = null,
        public readonly ?string $addressLine2 = null,
        public readonly ?string $carrierCode = null,
        public readonly ?string $pickupPointReference = null,
        public readonly ?string $settlement = null,
        // The region of a street address (checkout RN-1), replaced with the rest of the delivery; null for a pickup point. Staff are NOT bound
        // by the merchant's per-country region rule: it governs what a CUSTOMER types (the edit form checks only the length and plain text).
        public readonly ?string $region = null,
        // The DESCRIBED pick-up point's text (checkout form v2, section 3): what the customer typed; null = keep the order's own text.
        public readonly ?string $pickupPointAddress = null,
    ) {}
}
