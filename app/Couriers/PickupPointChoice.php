<?php

namespace App\Couriers;

use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Support\Facades\DB;

/**
 * The server's judgement of a point the customer chose from the picker (COUR-2a, courier-integration-design.md §4.5). The browser is
 * never trusted: the request carries only OUR point id, and everything the order stores about the point is read from our own table
 * HERE, at this moment (a snapshot), never taken from the request.
 *
 * Whether the picker applies to a method is one rule, `pickerCarrier()`: the method names a courier (its `carrier_code`), the courier
 * is installed and enabled, and it has at least one offerable point. The storefront decides to show the picker by the same rule (the
 * quote's `integration`), so the page and the server cannot disagree for long.
 */
final class PickupPointChoice
{
    public function __construct(private readonly OfferablePickupPoints $offerable) {}

    /** The courier code whose picker this method shows, or null: the method has no courier, it is off, or it has nothing to offer. */
    public function pickerCarrier(?string $methodId): ?string
    {
        $row = $this->method($methodId);
        $carrier = $row === null ? null : $row->carrier_code;

        return is_string($carrier) && $carrier !== '' && $this->offerable->hasAny($carrier) ? $carrier : null;
    }

    /** @var array<string, bool> */
    private array $lists = [];

    /** Whether this courier has a list to offer right now (enabled, at least one offerable point): one check per courier per request. */
    public function hasList(string $carrier): bool
    {
        return $this->lists[$carrier] ??= $this->offerable->hasAny($carrier);
    }

    /**
     * The snapshot of the chosen point, or null when it cannot be chosen for this method: unknown id, another courier's point, inactive,
     * not offerable, a kind the method does not serve, or a kind other than the one the customer asked for.
     *
     * @return array{point_id: int, carrier_code: string, external_ref: string, kind: string, name: string, address_line: string, settlement_name: string, postcode: ?string}|null
     */
    public function resolve(string $carrier, ?string $methodId, int $pointId, ?string $claimedKind): ?array
    {
        $method = $this->method($methodId);

        if ($method === null || $method->carrier_code !== $carrier) {
            return null;
        }

        $point = $this->offerable->find($pointId, $carrier, 'pickup');

        if ($point === null) {
            return null;
        }

        $served = array_map(
            static fn (ShippingDeliveryType $kind): string => $kind->value,
            ShippingMethod::servedKindsFor(
                $method->delivery_type === null ? null : ShippingDeliveryType::tryFrom((string) $method->delivery_type),
                ShippingDestinationScope::tryFrom((string) $method->destination_scope) ?? ShippingDestinationScope::ADDRESS,
            ),
        );

        if (! in_array($point->kind, $served, true) || ($claimedKind !== null && $claimedKind !== $point->kind)) {
            return null;
        }

        return [
            'point_id' => (int) $point->id,
            'carrier_code' => $carrier,
            'external_ref' => (string) $point->external_ref,
            'kind' => (string) $point->kind,
            'name' => (string) $point->name,
            'address_line' => (string) $point->address_line,
            'settlement_name' => (string) $point->settlement_name,
            'postcode' => $point->postcode === null ? null : (string) $point->postcode,
        ];
    }

    /** One row of the method (an unknown id, or one that is not a plain integer, is null). */
    private function method(?string $methodId): ?object
    {
        if ($methodId === null || preg_match('/^\d{1,18}$/D', $methodId) !== 1) {
            return null;
        }

        return DB::table('shipping_methods')->where('id', (int) $methodId)->where('is_active', 1)->first(['carrier_code', 'delivery_type', 'destination_scope']);
    }

    /** Writes the snapshot of an order's point (called INSIDE the placement transaction). */
    public static function store(int|string $orderId, array $snapshot): void
    {
        DB::table('order_pickup_points')->insert([
            'order_id' => $orderId,
            'point_id' => $snapshot['point_id'],
            'carrier_code' => $snapshot['carrier_code'],
            'external_ref' => $snapshot['external_ref'],
            'kind' => $snapshot['kind'],
            'name' => $snapshot['name'],
            'address_line' => $snapshot['address_line'],
            'settlement_name' => $snapshot['settlement_name'],
            'postcode' => $snapshot['postcode'],
            'created_at' => now(),
        ]);
    }
}
