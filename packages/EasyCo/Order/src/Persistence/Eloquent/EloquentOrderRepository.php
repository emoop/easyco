<?php

namespace EasyCo\Order\Persistence\Eloquent;

use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;

/**
 * Maps the Order entity onto `orders`. No unique-constraint collision
 * handling here — nothing about this entity is unique.
 */
final class EloquentOrderRepository implements OrderRepository
{
    public function save(Order $order): void
    {
        $model = $order->id() !== null
            ? OrderModel::findOrFail($order->id())
            : new OrderModel();

        $model->client_id = $order->clientId();
        $model->account_id = $order->accountId();
        $model->transaction_id = $order->transactionId();
        $model->email = $order->email();
        $model->currency = $order->currency()->code();
        $model->subtotal_minor = $order->subtotal()->minorValue();
        $model->discount_minor = $order->discount()->minorValue();
        $model->total_minor = $order->total()->minorValue();
        $model->applied_promotion_code = $order->appliedPromotionCode();
        $model->status = $order->status()->value;
        $model->placed_at = $order->placedAt();
        $model->address_id = $order->addressId();
        $model->delivery_type = $order->deliveryType()->value;
        $model->recipient_name = $order->recipientName();
        $model->phone = $order->phone();
        $model->country = $order->country();
        $model->city = $order->city();
        $model->postal_code = $order->postalCode();
        $model->address_line_1 = $order->addressLine1();
        $model->address_line_2 = $order->addressLine2();
        $model->carrier_code = $order->carrierCode();
        $model->pickup_point_reference = $order->pickupPointReference();
        $model->settlement = $order->settlement();

        $model->save();

        if ($order->id() === null) {
            $order->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Order
    {
        $model = OrderModel::find($id);

        return $model !== null ? $this->toDomainOrder($model) : null;
    }

    private function toDomainOrder(OrderModel $model): Order
    {
        return Order::reconstituteFromStorage(
            id: (string) $model->id,
            clientId: (string) $model->client_id,
            accountId: $model->account_id !== null ? (string) $model->account_id : null,
            transactionId: (string) $model->transaction_id,
            email: $model->email,
            currency: $model->currency,
            subtotal: Money::fromMinorUnits($model->subtotal_minor, $model->currency),
            discount: Money::fromMinorUnits($model->discount_minor, $model->currency),
            total: Money::fromMinorUnits($model->total_minor, $model->currency),
            appliedPromotionCode: $model->applied_promotion_code,
            status: OrderStatus::from($model->status),
            placedAt: $model->placed_at,
            addressId: $model->address_id !== null ? (string) $model->address_id : null,
            deliveryType: OrderDeliveryType::from($model->delivery_type),
            recipientName: $model->recipient_name,
            phone: $model->phone,
            country: $model->country,
            city: $model->city,
            postalCode: $model->postal_code,
            addressLine1: $model->address_line_1,
            addressLine2: $model->address_line_2,
            carrierCode: $model->carrier_code,
            pickupPointReference: $model->pickup_point_reference,
            settlement: $model->settlement,
        );
    }
}
