<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\Rating\RateDestination;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingMethod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shipping stage 5g-1 (design 9.3.3): the destination-kind helpers, the served-kinds matrix, the free set and the threshold by kind. */
final class ShippingFreeSetTest extends TestCase
{
    private const OFFICE_LOCKER = [Type::OFFICE, Type::LOCKER];

    /** @param list<Type>|null $free */
    private function method(?Type $label = null, Scope $scope = Scope::ANY, ?array $free = null, ?int $freeAbove = 6000, ShippingMethodKind $kind = ShippingMethodKind::FLAT): ShippingMethod
    {
        return ShippingMethod::create('1', 'Speedy', $kind, 0, true, $kind === ShippingMethodKind::CARRIER ? null : 700, [], $freeAbove, $kind === ShippingMethodKind::CARRIER ? 'econt' : null, false, ShippingClassMode::REPLACE, null, $label, $scope, $free);
    }

    private function charge(ShippingMethod $method, ?RateDestination $destination, int $goods = 6000, array $lines = [['', 1]]): int
    {
        return (new ShippingRateCalculator())->rateFor($method, new RateRequest('EUR', $goods, array_map(fn (array $l): RateLine => new RateLine($l[0] === '' ? null : $l[0], $l[1]), $lines), $destination))->amountMinor();
    }

    // --- the vocabulary ----------------------------------------------------------------------------------

    public function test_the_destination_kinds_are_address_office_locker_and_other_is_never_one(): void
    {
        $this->assertSame([Type::ADDRESS, Type::OFFICE, Type::LOCKER], Type::destinationKinds());
        $this->assertSame([Type::OFFICE, Type::LOCKER], Type::pickupKinds());
        $this->assertTrue(Type::LOCKER->isDestinationKind());
        $this->assertFalse(Type::OTHER->isDestinationKind());
    }

    public function test_rate_destination_refuses_other_and_incoherent_pairs(): void
    {
        $this->assertSame(Type::ADDRESS, RateDestination::address()->kind);
        $this->assertTrue(RateDestination::pickup()->isPickupPoint);
        $this->assertNull(RateDestination::pickup()->kind);

        foreach ([[true, Type::OTHER], [false, Type::OTHER], [true, Type::ADDRESS], [false, Type::OFFICE], [false, Type::LOCKER], [false, null]] as [$pickup, $kind]) {
            try {
                new RateDestination($pickup, $kind);
                $this->fail('expected a refusal for '.var_export($pickup, true).'/'.($kind?->value ?? 'null'));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- the served-kinds matrix (the six rows) ------------------------------------------------------------

    /** @return array<string, array{0: ?Type, 1: Scope, 2: list<Type>}> */
    public static function servedMatrix(): array
    {
        return [
            'address + address' => [Type::ADDRESS, Scope::ADDRESS, [Type::ADDRESS]],
            'office + pickup' => [Type::OFFICE, Scope::PICKUP, [Type::OFFICE]],
            'locker + pickup' => [Type::LOCKER, Scope::PICKUP, [Type::LOCKER]],
            'none + address' => [null, Scope::ADDRESS, [Type::ADDRESS]],
            'none + pickup' => [null, Scope::PICKUP, [Type::OFFICE, Type::LOCKER]],
            'none + any' => [null, Scope::ANY, [Type::ADDRESS, Type::OFFICE, Type::LOCKER]],
            'other + address' => [Type::OTHER, Scope::ADDRESS, [Type::ADDRESS]],
            'other + pickup' => [Type::OTHER, Scope::PICKUP, [Type::OFFICE, Type::LOCKER]],
            'other + any' => [Type::OTHER, Scope::ANY, [Type::ADDRESS, Type::OFFICE, Type::LOCKER]],
        ];
    }

    /** @param list<Type> $served */
    #[DataProvider('servedMatrix')]
    public function test_served_kinds_matrix(?Type $label, Scope $scope, array $served): void
    {
        $this->assertSame($served, $this->method($label, $scope)->servedKinds());
        $this->assertSame($served, ShippingMethod::servedKindsFor($label, $scope));
    }

    // --- the stored set and the invariant ------------------------------------------------------------------

    public function test_a_method_with_no_stated_set_is_free_for_every_kind_it_serves(): void
    {
        $method = $this->method(null, Scope::ANY, null);

        $this->assertSame([Type::ADDRESS, Type::OFFICE, Type::LOCKER], $method->freeDestinations());
        $this->assertSame([Type::ADDRESS, Type::OFFICE, Type::LOCKER], $method->effectiveFreeDestinations());
    }

    public function test_the_set_is_normalised_to_canonical_order_without_duplicates(): void
    {
        $method = $this->method(null, Scope::ANY, [Type::LOCKER, Type::ADDRESS, Type::LOCKER]);

        $this->assertSame([Type::ADDRESS, Type::LOCKER], $method->freeDestinations());
    }

    public function test_the_effective_set_is_the_stored_set_within_the_served_kinds(): void
    {
        $method = $this->method(Type::OFFICE, Scope::PICKUP, [Type::ADDRESS, Type::OFFICE, Type::LOCKER]);

        $this->assertSame([Type::ADDRESS, Type::OFFICE, Type::LOCKER], $method->freeDestinations(), 'what was stored');
        $this->assertSame([Type::OFFICE], $method->effectiveFreeDestinations(), 'what applies');
    }

    public function test_other_and_non_kinds_are_refused_in_the_set(): void
    {
        foreach ([[Type::OTHER], ['office'], [null]] as $bad) {
            try {
                $this->method(null, Scope::ANY, $bad);
                $this->fail('expected a refusal');
            } catch (InvalidShippingMethodException $e) {
                $this->assertStringContainsString('free destination', $e->getMessage());
            }
        }
    }

    public function test_a_threshold_needs_a_free_kind_the_method_serves(): void
    {
        // an address-only method whose set names only office and locker: the threshold could never apply
        $this->expectException(InvalidShippingMethodException::class);
        $this->expectExceptionMessage('free destination kind');

        $this->method(Type::ADDRESS, Scope::ADDRESS, self::OFFICE_LOCKER);
    }

    public function test_an_empty_set_with_a_threshold_is_refused_and_without_a_threshold_it_is_allowed(): void
    {
        $this->assertSame([], $this->method(null, Scope::ANY, [], null)->freeDestinations(), 'no threshold: an empty set is just "never free by size"');

        $this->expectException(InvalidShippingMethodException::class);
        $this->method(null, Scope::ANY, [], 6000);
    }

    public function test_the_invariant_also_holds_on_update_and_a_refused_update_changes_nothing(): void
    {
        $method = $this->method(null, Scope::ANY, self::OFFICE_LOCKER);

        try {
            $method->update('Speedy', ShippingMethodKind::FLAT, 0, true, 700, [], 6000, null, false, ShippingClassMode::REPLACE, null, Type::ADDRESS, Scope::ADDRESS, self::OFFICE_LOCKER);
            $this->fail('expected a refusal');
        } catch (InvalidShippingMethodException) {
            $this->assertSame(Scope::ANY, $method->destinationScope());
            $this->assertSame(self::OFFICE_LOCKER, $method->freeDestinations());
        }
    }

    public function test_reconstitute_and_update_carry_the_set(): void
    {
        $method = ShippingMethod::reconstituteFromStorage('9', '1', 'M', ShippingMethodKind::FLAT, 0, true, 700, [], 6000, null, false, ShippingClassMode::REPLACE, null, null, Scope::ANY, [Type::LOCKER]);
        $this->assertSame([Type::LOCKER], $method->freeDestinations());

        $method->update('M', ShippingMethodKind::FLAT, 0, true, 700, [], 6000, null, false, ShippingClassMode::REPLACE, null, null, Scope::ANY, [Type::ADDRESS, Type::OFFICE]);
        $this->assertSame([Type::ADDRESS, Type::OFFICE], $method->freeDestinations());
    }

    // --- freeAppliesTo: never cheaper than stated ----------------------------------------------------------

    public function test_free_applies_by_kind(): void
    {
        $method = $this->method(null, Scope::ANY, self::OFFICE_LOCKER);

        $this->assertFalse($method->freeAppliesTo(RateDestination::address()));
        $this->assertTrue($method->freeAppliesTo(RateDestination::pickup(Type::OFFICE)));
        $this->assertTrue($method->freeAppliesTo(RateDestination::pickup(Type::LOCKER)));
    }

    public function test_an_unstated_pickup_kind_is_free_only_if_both_pickup_kinds_are(): void
    {
        $this->assertTrue($this->method(null, Scope::ANY, self::OFFICE_LOCKER)->freeAppliesTo(RateDestination::pickup()));
        $this->assertFalse($this->method(null, Scope::ANY, [Type::LOCKER])->freeAppliesTo(RateDestination::pickup()), 'locker only: an unstated pickup could be an office');
        $this->assertTrue($this->method(Type::LOCKER, Scope::PICKUP, [Type::LOCKER])->freeAppliesTo(RateDestination::pickup()), 'a locker-only method can only be a locker');
    }

    public function test_no_destination_is_free_only_if_every_served_kind_is(): void
    {
        $this->assertTrue($this->method(null, Scope::ANY, null)->freeAppliesTo(null), 'no stated set: as before stage 5g');
        $this->assertFalse($this->method(null, Scope::ANY, self::OFFICE_LOCKER)->freeAppliesTo(null));
        $this->assertTrue($this->method(null, Scope::PICKUP, self::OFFICE_LOCKER)->freeAppliesTo(null));
    }

    public function test_a_kind_the_method_does_not_serve_is_never_free(): void
    {
        $method = $this->method(Type::OFFICE, Scope::PICKUP, [Type::ADDRESS, Type::OFFICE]);

        $this->assertFalse($method->freeAppliesTo(RateDestination::pickup(Type::LOCKER)));
        $this->assertFalse($method->freeAppliesTo(RateDestination::address()));
    }

    // --- applyThreshold by kind --------------------------------------------------------------------------------

    public function test_the_owners_case_address_pays_office_and_locker_are_free(): void
    {
        $method = $this->method(null, Scope::ANY, self::OFFICE_LOCKER);

        $this->assertSame(700, $this->charge($method, RateDestination::address()), 'goods reach the threshold, the address is not in the set: the normal price');
        $this->assertSame(0, $this->charge($method, RateDestination::pickup(Type::OFFICE)));
        $this->assertSame(0, $this->charge($method, RateDestination::pickup(Type::LOCKER)));
    }

    public function test_locker_only_free_shipping(): void
    {
        $method = $this->method(null, Scope::ANY, [Type::LOCKER]);

        $this->assertSame(700, $this->charge($method, RateDestination::pickup(Type::OFFICE)));
        $this->assertSame(0, $this->charge($method, RateDestination::pickup(Type::LOCKER)));
        $this->assertSame(700, $this->charge($method, RateDestination::pickup()), 'unstated kind: never cheaper');
        $this->assertSame(700, $this->charge($method, null), 'no destination: never cheaper');
    }

    public function test_the_threshold_is_inclusive_for_every_kind(): void
    {
        $method = $this->method(null, Scope::ANY, [Type::ADDRESS, Type::OFFICE, Type::LOCKER]);

        foreach ([RateDestination::address(), RateDestination::pickup(Type::OFFICE), RateDestination::pickup(Type::LOCKER)] as $destination) {
            $this->assertSame(700, $this->charge($method, $destination, 5999), 'one below');
            $this->assertSame(0, $this->charge($method, $destination, 6000), 'exactly at');
            $this->assertSame(0, $this->charge($method, $destination, 6001), 'one above');
        }
    }

    public function test_a_method_with_no_stated_set_behaves_exactly_as_before_5g_for_every_destination(): void
    {
        $method = $this->method(null, Scope::ANY, null);

        foreach ([null, RateDestination::address(), RateDestination::pickup(), RateDestination::pickup(Type::OFFICE), RateDestination::pickup(Type::LOCKER)] as $destination) {
            $this->assertSame(0, $this->charge($method, $destination));
            $this->assertSame(700, $this->charge($method, $destination, 5999));
        }
    }

    public function test_replace_classes_are_unchanged_when_the_kind_is_not_free(): void
    {
        $method = ShippingMethod::create('1', 'M', ShippingMethodKind::PER_CLASS, 0, true, 400, ['large' => 900, 'small' => 300], 6000, null, false, ShippingClassMode::REPLACE, null, null, Scope::ANY, self::OFFICE_LOCKER);

        $this->assertSame(900, $this->charge($method, RateDestination::address(), 6000, [['large', 1], ['small', 2]]), 'the most expensive class, address pays');
        $this->assertSame(400, $this->charge($method, RateDestination::address(), 6000, [[null, 1]]), 'the fallback');
        $this->assertSame(0, $this->charge($method, RateDestination::pickup(Type::LOCKER), 6000, [['large', 1]]));
    }

    public function test_adjust_classes_are_unchanged_when_the_kind_is_not_free(): void
    {
        $method = ShippingMethod::create('1', 'M', ShippingMethodKind::PER_CLASS, 0, true, 500, ['bulky' => 300, 'promo' => -200], 6000, null, false, ShippingClassMode::ADJUST, null, null, Scope::ANY, [Type::LOCKER]);

        $this->assertSame(600, $this->charge($method, RateDestination::address(), 6000, [['bulky', 1], ['promo', 1]]), 'base 500 + 300 - 200');
        $this->assertSame(600, $this->charge($method, RateDestination::pickup(Type::OFFICE), 6000, [['bulky', 1], ['promo', 1]]));
        $this->assertSame(0, $this->charge($method, RateDestination::pickup(Type::LOCKER), 6000, [['bulky', 1]]));
    }

    public function test_the_threshold_facts_follow_the_kind(): void
    {
        $method = $this->method(null, Scope::ANY, self::OFFICE_LOCKER);
        $calculator = new ShippingRateCalculator();
        $request = fn (?RateDestination $d): RateRequest => new RateRequest('EUR', 4000, [new RateLine(null, 1)], $d);

        $locker = $calculator->rateFor($method, $request(RateDestination::pickup(Type::LOCKER)));
        $this->assertSame([6000, 2000], [$locker->freeAboveMinor, $locker->remainingToFreeMinor]);

        $address = $calculator->rateFor($method, $request(RateDestination::address()));
        $this->assertSame([null, null], [$address->freeAboveMinor, $address->remainingToFreeMinor], 'no "add X more" for a kind that is never free');

        $none = $calculator->rateFor($method, $request(null));
        $this->assertSame([6000, 2000], [$none->freeAboveMinor, $none->remainingToFreeMinor], 'no destination stated: the facts are still reported while some kind is free');
    }

    public function test_carrier_and_free_kinds_are_unchanged(): void
    {
        $carrier = $this->method(null, Scope::ANY, [Type::LOCKER], 6000, ShippingMethodKind::CARRIER);
        $rate = (new ShippingRateCalculator())->rateFor($carrier, new RateRequest('EUR', 9000, [new RateLine(null, 1)], RateDestination::pickup(Type::LOCKER)));
        $this->assertTrue($rate->needsQuote(), 'a carrier method is never priced locally, whatever the free set');
        $this->assertNull($rate->freeAboveMinor);

        $free = ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE, 0, true, null, [], null, null, false, ShippingClassMode::REPLACE, null, null, Scope::ANY, [Type::LOCKER]);
        $this->assertSame(0, $this->charge($free, RateDestination::address(), 0));
    }
}
