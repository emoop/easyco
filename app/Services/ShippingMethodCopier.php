<?php

namespace App\Services;

use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\Exceptions\UnknownShippingClassException;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Support\Facades\DB;

/**
 * Copy a method into other zones (shipping-domain-design.md §12.5): the owner has ONE courier per zone but MANY
 * zones, so the same method must exist in each. For every selected zone a NEW, independent method is created with the
 * same kind, name, price, class mode, class amounts, free-shipping threshold, carrier code and pickup flag, and the
 * SAME ACTIVE STATE, appended at the end of that zone's order. Origin and copies share nothing afterwards.
 *
 * ONE transaction: if any target fails, none is created. One audit entry PER created method (`shipping_method`,
 * `created`, field `copied_from` naming the source), and — after the commit, never inside it — one hook
 * `shipping.method.created (ShippingMethod, string $copiedFrom)` per method.
 *
 * Refused (translated, ShippingMethodInvalidException on `zones`; nothing written): an empty selection, more than 50
 * zones, the source's own zone, an unknown zone. A zone that already has a method with the same name and kind is
 * NOT refused (a one-name-per-zone rule is not enforced, §12.5): it is copied to and reported in the result.
 */
final class ShippingMethodCopier
{
    public const MAX_TARGETS = 50;

    public function __construct(
        private readonly ShippingMethodRepository $methods,
        private readonly ShippingZoneRepository $zones,
        private readonly ActivityLogger $audit,
    ) {
    }

    /**
     * @param  list<string|int>  $zoneIds
     *
     * @throws ShippingMethodInvalidException
     * @throws ShippingMethodNotFoundException the source method is gone
     */
    public function copyToZones(string $methodId, array $zoneIds): ShippingMethodCopyResult
    {
        $targets = array_values(array_unique(array_map('strval', $zoneIds)));

        if ($targets === []) {
            throw new ShippingMethodInvalidException(['zones' => [__('shipping.methods.errors.copy_none')]]);
        }

        if (count($targets) > self::MAX_TARGETS) {
            throw new ShippingMethodInvalidException(['zones' => [__('shipping.methods.errors.copy_too_many', ['max' => self::MAX_TARGETS])]]);
        }

        $result = DB::transaction(function () use ($methodId, $targets): ShippingMethodCopyResult {
            $source = $this->methods->findById($methodId) ?? throw new ShippingMethodNotFoundException();

            if (in_array($source->zoneId(), $targets, true)) {
                throw new ShippingMethodInvalidException(['zones' => [__('shipping.methods.errors.copy_same_zone')]]);
            }

            $known = array_map(static fn (ShippingZone $zone): string => (string) $zone->id(), $this->zones->allOrdered());

            if (array_diff($targets, $known) !== []) {
                throw new ShippingMethodInvalidException(['zones' => [__('shipping.methods.errors.copy_unknown_zone')]]);
            }

            $created = [];
            $duplicates = [];

            foreach ($targets as $zoneId) {
                $next = 0;

                foreach ($this->methods->forZone($zoneId) as $existing) {
                    $next = max($next, $existing->sortOrder() + 1);

                    if ($existing->name() === $source->name() && $existing->kind() === $source->kind()) {
                        $duplicates[] = $zoneId;
                    }
                }

                try {
                    $copy = ShippingMethod::create(
                        $zoneId, $source->name(), $source->kind(), $next, $source->isActive(), $source->amountMinor(), $source->classRates(),
                        $source->freeAboveMinor(), $source->carrierCode(), $source->requiresPickupPoint(), $source->classMode(),
                        $source->courier(), $source->deliveryType(),
                    );
                    $this->methods->save($copy);
                } catch (InvalidShippingMethodException|UnknownShippingClassException) {
                    throw new ShippingMethodInvalidException(['zones' => [__('shipping.methods.errors.invalid')]]);
                }

                $this->audit->logCopied(ShippingMethodWriter::ENTITY, (string) $copy->id(), $methodId);
                $created[] = $copy;
            }

            return new ShippingMethodCopyResult($created, array_values(array_unique($duplicates)));
        });

        foreach ($result->created as $copy) {
            Hook::fire('shipping.method.created', $copy, $methodId);
        }

        return $result;
    }
}
