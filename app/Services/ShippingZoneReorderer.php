<?php

namespace App\Services;

use App\Services\Exceptions\ShippingZoneNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Persistence\Eloquent\ShippingZoneModel;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Support\Facades\DB;

/**
 * Move a shipping zone up or down the match order (shipping-domain-design.md §12.3.2, §12.6): the ORDER is the
 * rule — the first zone from the top that matches the address wins — so this is the one and only way the order
 * changes (the zone form has no order field, and the table's own drag-reorder is deliberately not used because it
 * would rewrite the column outside the service layer).
 *
 * A move swaps the zone with its neighbour in the CURRENT order (`allOrdered()`: sortOrder ASC, id ASC — the
 * stored sortOrder is not unique, so ties break by id) and rewrites every zone whose position changed to a DENSE
 * 0..n-1 sequence, in ONE transaction, under a lock on the zones table so two moves cannot interleave. It writes
 * EXACTLY ONE audit entry (`shipping_zone`, field `order`, compact JSON of the two zones' ids and names with their
 * positions before and after) and fires ONE hook `shipping.zone.reordered (array $change)` after the commit.
 * Moving the first zone up or the last zone down is a NO-OP: nothing is written, no audit, no hook.
 *
 * No cache is flushed (§12.6: the quote is recomputed on every call). No permission check here: the screen that
 * calls this is what is authorized (shipping_manage).
 */
final class ShippingZoneReorderer
{
    public function __construct(
        private readonly ShippingZoneRepository $zones,
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

    /** @throws ShippingZoneNotFoundException */
    private function move(string $id, int $direction): bool
    {
        $change = DB::transaction(function () use ($id, $direction): ?array {
            // Serialise every reorder: the order is read and rewritten as a whole.
            ShippingZoneModel::query()->lockForUpdate()->pluck('id');

            $ordered = $this->zones->allOrdered();
            $index = null;

            foreach ($ordered as $i => $zone) {
                if ($zone->id() === $id) {
                    $index = $i;
                }
            }

            if ($index === null) {
                throw new ShippingZoneNotFoundException();
            }

            $target = $index + $direction;

            if (! isset($ordered[$target])) {
                return null; // first-up / last-down: nothing to do, nothing written
            }

            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

            foreach ($ordered as $position => $zone) {
                if ($zone->sortOrder() !== $position) {
                    $zone->update($zone->name(), $position, $zone->countryCodes(), $zone->settlementNames(), $zone->postcodes());
                    $this->zones->save($zone);
                }
            }

            $moved = $ordered[$target];
            $other = $ordered[$index];

            $change = [
                'direction' => $direction < 0 ? 'up' : 'down',
                'zones' => [
                    ['id' => $moved->id(), 'name' => $moved->name(), 'position_before' => $index, 'position_after' => $target],
                    ['id' => $other->id(), 'name' => $other->name(), 'position_before' => $target, 'position_after' => $index],
                ],
            ];

            $this->audit->logFieldChanged(
                ShippingZoneWriter::ENTITY,
                (string) $moved->id(),
                'order',
                ShippingZoneWriter::json(['zones' => array_map(static fn (array $zone): array => ['id' => $zone['id'], 'name' => $zone['name'], 'position' => $zone['position_before']], $change['zones'])]),
                ShippingZoneWriter::json(['zones' => array_map(static fn (array $zone): array => ['id' => $zone['id'], 'name' => $zone['name'], 'position' => $zone['position_after']], $change['zones'])]),
            );

            return $change;
        });

        if ($change === null) {
            return false;
        }

        Hook::fire('shipping.zone.reordered', $change);

        return true;
    }
}
