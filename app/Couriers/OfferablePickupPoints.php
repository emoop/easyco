<?php

namespace App\Couriers;

use App\Settings\StoreTimezone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE DEFINITION of "a point a customer may be offered" (COUR-2a, courier-integration-design.md §4.3 / §4.5). The two public read
 * endpoints and the order check call this and nothing else, so a point can never be listed that the order would then refuse (or the
 * other way round). A point is offerable when ALL of these hold:
 *   - its courier is installed AND enabled (a disabled courier offers nothing, whatever is in the table);
 *   - `is_active` (the nightly sync deactivates what the courier no longer lists);
 *   - its kind is the asked one (`pickup` = office or locker);
 *   - `features.pickUpAllowed` is not `false` (Speedy; absent = allowed);
 *   - `features.validTo`, when present, is not before TODAY in the store's timezone (Speedy; the last valid day is still valid);
 *   - its courier type is not an excluded one (`mps`: Econt's mobile points are never offered, owner's decision).
 * Only local reads: no courier is called.
 */
final class OfferablePickupPoints
{
    public const TABLE = 'shipping_pickup_points';

    /** The kinds a request may ask for. */
    public const KINDS = ['office', 'locker', 'pickup'];

    /** Courier types the owner keeps out of the checkout. */
    public const EXCLUDED_COURIER_TYPES = ['mps'];

    public function __construct(
        private readonly CourierDirectory $couriers,
        private readonly StoreTimezone $timezone,
    ) {}

    /** @return list<string>|null the stored kinds a requested kind stands for; null for a kind that is not asked for */
    public static function kindsOf(string $kind): ?array
    {
        return match ($kind) {
            'office' => ['office'],
            'locker' => ['locker'],
            'pickup' => ['office', 'locker'],
            default => null,
        };
    }

    public function courierOffers(string $carrier): bool
    {
        return $this->couriers->isEnabled($carrier);
    }

    /**
     * The offerable points of one courier and kind, as a query on the points table (null when the courier is not enabled or the
     * kind is not one that can be asked for). Callers add their own selects, joins and grouping.
     */
    public function query(string $carrier, string $kind): ?Builder
    {
        $kinds = self::kindsOf($kind);

        if ($kinds === null || ! $this->courierOffers($carrier)) {
            return null;
        }

        $today = now($this->timezone->zone())->format('Y-m-d');

        return DB::table(self::TABLE.' as p')
            ->where('p.carrier_code', $carrier)
            ->where('p.is_active', 1)
            ->whereIn('p.kind', $kinds)
            ->where(static fn (Builder $q) => $q->whereNull('p.courier_type')->orWhereNotIn('p.courier_type', self::EXCLUDED_COURIER_TYPES))
            // absent (or a NULL column) counts as allowed; only an explicit JSON false refuses
            ->whereRaw("(p.features IS NULL OR JSON_EXTRACT(p.features, '$.pickUpAllowed') IS NULL OR JSON_EXTRACT(p.features, '$.pickUpAllowed') <> CAST('false' AS JSON))")
            ->whereRaw("(p.features IS NULL OR JSON_EXTRACT(p.features, '$.validTo') IS NULL OR LEFT(JSON_UNQUOTE(JSON_EXTRACT(p.features, '$.validTo')), 10) >= ?)", [$today]);
    }

    /** True when the courier is on and has at least one offerable point of any kind (the picker's own switch). */
    public function hasAny(string $carrier): bool
    {
        return $this->query($carrier, 'pickup')?->exists() ?? false;
    }

    /**
     * ONE point by OUR id, iff it is offerable for this courier and kind (the order check). A point of another courier, an inactive
     * one, one of the wrong kind or an unknown id is null: the caller cannot tell them apart, and does not need to.
     */
    public function find(int $id, string $carrier, string $kind): ?object
    {
        return $this->query($carrier, $kind)?->where('p.id', $id)->select('p.*')->first();
    }
}
