<?php

namespace App\Services;

use App\Services\Exceptions\ShippingMethodNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Support\Facades\DB;

/**
 * Move a shipping method up or down inside its zone (shipping-domain-design.md §12.3.3, §12.6) — the one and only
 * way a method's order changes (the method form has no order field). The order is the display order of the zone's
 * methods and the tie order when two methods are offered (ShippingRateCalculator::ratesFor() sorts by sortOrder
 * ascending, then id); nothing else depends on it.
 *
 * The twin of ShippingZoneReorderer: a swap with the neighbour in the CURRENT order (`forZone()`: sortOrder ASC, id
 * ASC; the stored sortOrder is not unique), every method whose position changed rewritten to a DENSE 0..n-1
 * sequence, in ONE transaction under a lock on that zone's methods; EXACTLY ONE audit entry (`shipping_method`, field
 * `order`, compact JSON of the two methods' ids and names with their positions before and after) and ONE hook
 * `shipping.method.reordered (array $change)` after the commit. The first method up / the last down is a NO-OP:
 * nothing is written, no audit, no hook. No cache is flushed (§12.6).
 */
final class ShippingMethodReorderer
{
    public function __construct(
        private readonly ShippingMethodRepository $methods,
        private readonly ActivityLogger $audit,
    ) {
    }

    /** @return bool whether anything moved */
    public function moveUp(string $id): bool
    {
        return $this->move($id, -1);
    }

    /** @return bool whether anything moved */
    public function moveDown(string $id): bool
    {
        return $this->move($id, 1);
    }

    /** @throws ShippingMethodNotFoundException */
    private function move(string $id, int $direction): bool
    {
        $subject = $this->methods->findById($id) ?? throw new ShippingMethodNotFoundException();
        $zoneId = $subject->zoneId();

        $change = DB::transaction(function () use ($id, $zoneId, $direction): ?array {
            // Serialise every reorder inside this zone: the order is read and rewritten as a whole.
            ShippingMethodModel::query()->where('zone_id', $zoneId)->lockForUpdate()->pluck('id');

            $ordered = $this->methods->forZone($zoneId);
            $index = null;

            foreach ($ordered as $i => $method) {
                if ($method->id() === $id) {
                    $index = $i;
                }
            }

            if ($index === null) {
                throw new ShippingMethodNotFoundException();
            }

            $target = $index + $direction;

            if (! isset($ordered[$target])) {
                return null; // first-up / last-down: nothing to do, nothing written
            }

            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

            foreach ($ordered as $position => $method) {
                if ($method->sortOrder() !== $position) {
                    $method->update(
                        $method->name(), $method->kind(), $position, $method->isActive(), $method->amountMinor(), $method->classRates(),
                        $method->freeAboveMinor(), $method->carrierCode(), $method->requiresPickupPoint(), $method->classMode(),
                    );
                    $this->methods->save($method);
                }
            }

            /** @var ShippingMethod $moved */
            $moved = $ordered[$target];
            $other = $ordered[$index];

            $change = [
                'direction' => $direction < 0 ? 'up' : 'down',
                'zone_id' => $zoneId,
                'methods' => [
                    ['id' => $moved->id(), 'name' => $moved->name(), 'position_before' => $index, 'position_after' => $target],
                    ['id' => $other->id(), 'name' => $other->name(), 'position_before' => $target, 'position_after' => $index],
                ],
            ];

            $picture = static fn (string $key): string => ShippingMethodWriter::json(['methods' => array_map(
                static fn (array $method): array => ['id' => $method['id'], 'name' => $method['name'], 'position' => $method[$key]],
                $change['methods'],
            )]);

            $this->audit->logFieldChanged(ShippingMethodWriter::ENTITY, (string) $moved->id(), 'order', $picture('position_before'), $picture('position_after'));

            return $change;
        });

        if ($change === null) {
            return false;
        }

        Hook::fire('shipping.method.reordered', $change);

        return true;
    }
}
