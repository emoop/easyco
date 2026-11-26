<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\CarrierStatusEvent;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CreatedShipment;
use EasyCo\Shipping\Carrier\LabelDocument;
use EasyCo\Shipping\Carrier\LabelFormat;
use EasyCo\Shipping\Carrier\ShipmentRequest;

/**
 * The full waybill lifecycle of a courier (courier-integration-design.md §2.2). Bound as `shipping.carrier.<code>.label`.
 * It WIDENS (and replaces) the old ShipmentLabelProvider, which could only create a label.
 *
 * INTERFACE ONLY IN COUR-1a: no implementation exists, nothing calls it, and the shipments domain that will use it is
 * COUR-3a. Until then it is only a name the resolver can hold.
 *
 * IDEMPOTENCY (shipping-domain-design.md §6.4, to be AMENDED in COUR-3, courier-integration-design.md §1 row 11 / §6.3):
 * creating a shipment costs money and can fail in the worst way, the courier created it and the answer was lost. A
 * gateway therefore sends ShipmentRequest::$idempotencyReference wherever the courier takes a client reference, and the
 * CALLER (the shipments service) writes the attempt BEFORE the call and never retries an ambiguous outcome. A gateway
 * never retries a create or a cancel itself, and no failure message carries a courier's reply or customer data.
 */
interface ShipmentGateway
{
    public function create(ShipmentRequest $request, CourierCredentials $credentials): CreatedShipment;

    public function cancel(string $externalId, CourierCredentials $credentials): void;

    /**
     * One PDF for one or many shipments of this courier.
     *
     * @param  list<string>  $externalIds
     */
    public function labels(array $externalIds, LabelFormat $format, CourierCredentials $credentials): LabelDocument;

    /**
     * Polling couriers only; BOX NOW answers by webhook.
     *
     * @param  list<string>  $externalIds
     * @return list<CarrierStatusEvent>
     */
    public function track(array $externalIds, CourierCredentials $credentials): array;

    public function trackingUrl(string $trackingNumber): ?string;
}
