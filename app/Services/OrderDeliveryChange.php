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
        public readonly string $recipientName,
        public readonly string $phone,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $addressLine1 = null,
        public readonly ?string $addressLine2 = null,
        public readonly ?string $carrierCode = null,
        public readonly ?string $pickupPointReference = null,
        public readonly ?string $settlement = null,
    ) {}
}
