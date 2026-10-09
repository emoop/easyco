<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderPlacementSnapshotModel;

/**
 * The one place that builds the write-once placement snapshot row FROM an Order (order-editing-design.md §2.1),
 * now carrying every shipping fact the order does (shipping stage 4a, shipping-domain-design.md §9.1.4): the
 * amount, the method name and code, the courier, the delivery type and the carrier service.
 *
 * A pure, mechanical copy of the order as it was placed — so the snapshot AGREES with the order by construction
 * (the test pins that field by field). It writes one INSERT and nothing else; the row is never updated (an edit changes
 * the order's current-state columns and leaves this record alone — OrderEditor / OrderLineEditor never touch it).
 *
 * NOT WIRED INTO CHECKOUT YET: CheckoutOrchestrator still builds its own row inline without the shipping columns
 * (they default to 0 / NULL, which agrees with the order it places today, because checkout places no shipping). Stage 4e
 * replaces that inline array with this writer in the same change that makes checkout choose a shipping method.
 */
final class OrderPlacementSnapshotWriter
{
    /** @return array<string, mixed> the row, ready for OrderPlacementSnapshotModel::create() */
    public function rowFor(Order $order, DateTimeImmutable $placedAt): array
    {
        return [
            'order_id' => $order->id(),
            'subtotal_minor' => $order->subtotal()->minorValue(),
            'subtotal_currency' => $order->subtotal()->currency()->code(),
            'discount_minor' => $order->discount()->minorValue(),
            'discount_currency' => $order->discount()->currency()->code(),
            'shipping_minor' => $order->shipping()->minorValue(),
            'shipping_method_name' => $order->shippingMethodName(),
            'shipping_method_code' => $order->shippingMethodCode(),
            'shipping_courier' => $order->shippingCourier(),
            'shipping_delivery_type' => $order->shippingDeliveryType(),
            'shipping_service_code' => $order->shippingServiceCode(),
            'total_minor' => $order->total()->minorValue(),
            'total_currency' => $order->total()->currency()->code(),
            'applied_promotion_code' => $order->appliedPromotionCode(),
            'delivery_type' => $order->deliveryType()->value,
            'recipient_name' => $order->recipientName(),
            'phone' => $order->phone(),
            'country' => $order->country(),
            'city' => $order->city(),
            'postal_code' => $order->postalCode(),
            'address_line_1' => $order->addressLine1(),
            'address_line_2' => $order->addressLine2(),
            'carrier_code' => $order->carrierCode(),
            'pickup_point_reference' => $order->pickupPointReference(),
            'settlement' => $order->settlement(),
            'pickup_point_name' => $order->pickupPointName(),
            'pickup_point_address' => $order->pickupPointAddress(),
            'created_at' => $placedAt,
        ];
    }

    public function write(Order $order, DateTimeImmutable $placedAt): OrderPlacementSnapshotModel
    {
        return OrderPlacementSnapshotModel::create($this->rowFor($order, $placedAt));
    }
}
