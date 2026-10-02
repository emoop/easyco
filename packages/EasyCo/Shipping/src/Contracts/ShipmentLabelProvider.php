<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\ShipmentLabel;
use EasyCo\Shipping\Carrier\ShipmentRequest;

/**
 * Creates a shipment with the carrier and returns its waybill
 * (shipping-domain-design.md §6). Bound in the container as
 * `shipping.carrier.<code>.label`. Nothing in V1 calls it.
 *
 * IDEMPOTENCY IS AN OBLIGATION OF EVERY IMPLEMENTATION. Creating a shipment
 * costs money and the call can fail in the worst way — the carrier created it
 * and the answer was lost. A retry must therefore never create a second paid
 * shipment:
 *  - Send ShipmentRequest::$idempotencyReference to the carrier as its client
 *    reference / idempotency key wherever the carrier's API accepts one.
 *  - Where it has no native idempotency, LOOK UP by that reference before creating
 *    and return the existing shipment's label.
 *  - A second call with the same reference returns the same label (same tracking
 *    number, echoing the reference); it never creates, and never charges, again.
 *  - If neither is possible the implementation must refuse to run (throw), not
 *    guess: an unsafe retry is worse than no label.
 *
 * UNLIKE rate and pickup calls, a failure here is NOT swallowed: it propagates to
 * the merchant who asked, who sees it and retries with the same reference. There
 * is no time budget in the signature for the same reason — a merchant waits for a
 * label; the implementation still sets a finite timeout of its own.
 */
interface ShipmentLabelProvider
{
    public function createLabel(ShipmentRequest $request): ShipmentLabel;
}
