<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\ShippingMethod;
use PHPUnit\Framework\TestCase;

class ShippingMethodTest extends TestCase
{
    private function flat(?int $amount = 500, array $overrides = []): ShippingMethod
    {
        return ShippingMethod::create(...array_merge([
            'zoneId' => '1',
            'name' => 'Delivery',
            'kind' => ShippingMethodKind::FLAT,
            'amountMinor' => $amount,
        ], $overrides));
    }

    // --- FLAT ---------------------------------------------------------------

    public function test_flat_method_requires_an_amount(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(null);
    }

    public function test_flat_method_rejects_negative_amount(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(-1);
    }

    public function test_flat_method_accepts_a_zero_amount(): void
    {
        $this->assertSame(0, $this->flat(0)->amountMinor());
    }

    public function test_flat_method_rejects_class_rates(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(500, ['classRates' => ['bulky' => 900]]);
    }

    public function test_carrier_code_on_flat_method_is_rejected(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(500, ['carrierCode' => 'econt']);
    }

    public function test_flat_method_allows_a_free_above_threshold(): void
    {
        $this->assertSame(10000, $this->flat(500, ['freeAboveMinor' => 10000])->freeAboveMinor());
    }

    public function test_a_negative_free_above_threshold_is_rejected(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(500, ['freeAboveMinor' => -5]);
    }

    // --- FREE ---------------------------------------------------------------

    public function test_free_method_with_threshold_is_rejected_as_contradictory(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE, freeAboveMinor: 5000);
    }

    public function test_free_method_rejects_an_amount(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE, amountMinor: 0);
    }

    public function test_free_method_rejects_class_rates(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE, classRates: ['bulky' => 0]);
    }

    public function test_free_method_rejects_a_carrier_code(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE, carrierCode: 'econt');
    }

    public function test_a_plain_free_method_is_valid(): void
    {
        $method = ShippingMethod::create('1', 'Free', ShippingMethodKind::FREE);

        $this->assertNull($method->amountMinor());
        $this->assertSame([], $method->classRates());
    }

    // --- PER_CLASS ----------------------------------------------------------

    public function test_per_class_method_requires_fallback_amount(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, classRates: ['bulky' => 900]);
    }

    public function test_per_class_method_with_empty_rates_is_valid(): void
    {
        $method = ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500);

        $this->assertSame([], $method->classRates());
    }

    public function test_per_class_method_accepts_a_zero_rate(): void
    {
        $method = ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, classRates: ['light' => 0]);

        $this->assertSame(['light' => 0], $method->classRates());
    }

    public function test_per_class_method_rejects_a_negative_rate(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, classRates: ['bulky' => -1]);
    }

    public function test_per_class_method_rejects_a_non_integer_rate(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, classRates: ['bulky' => '9.50']);
    }

    public function test_class_rate_keys_must_be_valid_class_codes(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, classRates: ['Bulky Items' => 900]);
    }

    public function test_per_class_method_rejects_a_carrier_code(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, carrierCode: 'econt');
    }

    public function test_per_class_method_allows_a_free_above_threshold(): void
    {
        $method = ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, freeAboveMinor: 8000);

        $this->assertSame(8000, $method->freeAboveMinor());
    }

    public function test_class_rates_are_kept_in_a_deterministic_order(): void
    {
        $method = ShippingMethod::create('1', 'By class', ShippingMethodKind::PER_CLASS, amountMinor: 500, classRates: ['zeta' => 1, 'alpha' => 2]);

        $this->assertSame(['alpha', 'zeta'], array_keys($method->classRates()));
    }

    // --- CARRIER ------------------------------------------------------------

    public function test_carrier_method_requires_carrier_code(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Courier', ShippingMethodKind::CARRIER);
    }

    public function test_carrier_method_rejects_an_amount(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Courier', ShippingMethodKind::CARRIER, amountMinor: 500, carrierCode: 'econt');
    }

    public function test_carrier_method_rejects_class_rates(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Courier', ShippingMethodKind::CARRIER, classRates: ['bulky' => 1], carrierCode: 'econt');
    }

    public function test_carrier_code_must_use_the_class_code_format(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        ShippingMethod::create('1', 'Courier', ShippingMethodKind::CARRIER, carrierCode: 'Econt Express');
    }

    public function test_a_carrier_method_can_be_constructed_with_a_threshold_and_a_pickup_point(): void
    {
        $method = ShippingMethod::create('1', 'Courier to office', ShippingMethodKind::CARRIER, freeAboveMinor: 9000, carrierCode: 'econt', requiresPickupPoint: true);

        $this->assertSame('econt', $method->carrierCode());
        $this->assertSame(9000, $method->freeAboveMinor());
        $this->assertTrue($method->requiresPickupPoint());
    }

    // --- common -------------------------------------------------------------

    public function test_requires_pickup_point_is_allowed_on_every_kind(): void
    {
        foreach ([
            ShippingMethod::create('1', 'a', ShippingMethodKind::FLAT, amountMinor: 1, requiresPickupPoint: true),
            ShippingMethod::create('1', 'b', ShippingMethodKind::FREE, requiresPickupPoint: true),
            ShippingMethod::create('1', 'c', ShippingMethodKind::PER_CLASS, amountMinor: 1, requiresPickupPoint: true),
            ShippingMethod::create('1', 'd', ShippingMethodKind::CARRIER, carrierCode: 'x', requiresPickupPoint: true),
        ] as $method) {
            $this->assertTrue($method->requiresPickupPoint());
        }
    }

    public function test_name_must_not_be_empty_and_sort_order_not_negative(): void
    {
        $this->expectException(InvalidShippingMethodException::class);
        $this->flat(500, ['name' => '  ']);
    }

    public function test_a_negative_sort_order_is_rejected(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(500, ['sortOrder' => -1]);
    }

    public function test_an_empty_zone_id_is_rejected(): void
    {
        $this->expectException(InvalidShippingMethodException::class);

        $this->flat(500, ['zoneId' => ' ']);
    }

    public function test_a_rejected_update_leaves_the_method_untouched(): void
    {
        $method = $this->flat(500);

        try {
            $method->update('Renamed', ShippingMethodKind::FREE, 1, false, 500, [], null, null, false);
            $this->fail('a FREE method with an amount should have been rejected');
        } catch (InvalidShippingMethodException) {
        }

        $this->assertSame('Delivery', $method->name());
        $this->assertSame(ShippingMethodKind::FLAT, $method->kind());
        $this->assertSame(500, $method->amountMinor());
        $this->assertTrue($method->isActive());
    }
    // --- destination scope (shipping stage 6a, design 9.2) -------------------

    private function scoped(?ShippingDestinationScope $scope, bool $pickup = false, ?ShippingDeliveryType $type = null): ShippingMethod
    {
        return ShippingMethod::create('1', 'Delivery', ShippingMethodKind::FLAT, 0, true, 500, [], null, null, $pickup, ShippingClassMode::REPLACE, null, $type, $scope);
    }

    public function test_the_scope_enum_has_exactly_the_three_values(): void
    {
        $this->assertSame(['address', 'pickup', 'any'], array_map(fn (ShippingDestinationScope $s): string => $s->value, ShippingDestinationScope::cases()));
    }

    public function test_the_old_boolean_still_means_what_it_meant_when_no_scope_is_given(): void
    {
        $this->assertSame(ShippingDestinationScope::ADDRESS, $this->scoped(null, false)->destinationScope());
        $this->assertSame(ShippingDestinationScope::PICKUP, $this->scoped(null, true)->destinationScope());
        $this->assertSame(ShippingDestinationScope::ADDRESS, $this->flat()->destinationScope(), 'a default call is address-only, as before');
    }

    public function test_the_trailing_scope_wins_over_the_boolean(): void
    {
        $this->assertSame(ShippingDestinationScope::ANY, $this->scoped(ShippingDestinationScope::ANY, true)->destinationScope());
        $this->assertSame(ShippingDestinationScope::ADDRESS, $this->scoped(ShippingDestinationScope::ADDRESS, true)->destinationScope());
        $this->assertSame(ShippingDestinationScope::PICKUP, $this->scoped(ShippingDestinationScope::PICKUP, false)->destinationScope());
    }

    public function test_requires_pickup_point_is_derived_true_only_for_a_pickup_only_method(): void
    {
        $this->assertFalse($this->scoped(ShippingDestinationScope::ADDRESS)->requiresPickupPoint());
        $this->assertTrue($this->scoped(ShippingDestinationScope::PICKUP)->requiresPickupPoint());
        $this->assertFalse($this->scoped(ShippingDestinationScope::ANY)->requiresPickupPoint(), 'any is not pickup-only');
    }

    public function test_serves_pickup_point_truth_table(): void
    {
        $this->assertTrue($this->scoped(ShippingDestinationScope::ADDRESS)->servesPickupPoint(false));
        $this->assertFalse($this->scoped(ShippingDestinationScope::ADDRESS)->servesPickupPoint(true));
        $this->assertFalse($this->scoped(ShippingDestinationScope::PICKUP)->servesPickupPoint(false));
        $this->assertTrue($this->scoped(ShippingDestinationScope::PICKUP)->servesPickupPoint(true));
        $this->assertTrue($this->scoped(ShippingDestinationScope::ANY)->servesPickupPoint(false));
        $this->assertTrue($this->scoped(ShippingDestinationScope::ANY)->servesPickupPoint(true));
    }

    /** @return array<string, array{?ShippingDeliveryType, ShippingDestinationScope, bool}> label, scope, allowed */
    public static function labelScopeMatrix(): array
    {
        $cases = [];

        foreach ([null, ...ShippingDeliveryType::cases()] as $label) {
            foreach (ShippingDestinationScope::cases() as $scope) {
                $allowed = match ($label) {
                    ShippingDeliveryType::ADDRESS => $scope === ShippingDestinationScope::ADDRESS,
                    ShippingDeliveryType::OFFICE, ShippingDeliveryType::LOCKER => $scope === ShippingDestinationScope::PICKUP,
                    default => true,
                };
                $cases[($label?->value ?? 'none').' x '.$scope->value] = [$label, $scope, $allowed];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labelScopeMatrix')]
    public function test_the_label_scope_matrix_on_create(?ShippingDeliveryType $label, ShippingDestinationScope $scope, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(InvalidShippingMethodException::class);
            $this->expectExceptionMessage('destination scope');
        }

        $method = $this->scoped($scope, false, $label);

        $this->assertSame([$label, $scope], [$method->deliveryType(), $method->destinationScope()]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labelScopeMatrix')]
    public function test_the_label_scope_matrix_on_update_and_a_refused_update_changes_nothing(?ShippingDeliveryType $label, ShippingDestinationScope $scope, bool $allowed): void
    {
        $method = $this->scoped(ShippingDestinationScope::ANY);

        try {
            $method->update('Renamed', ShippingMethodKind::FLAT, 3, true, 700, [], null, null, false, ShippingClassMode::REPLACE, null, $label, $scope);
        } catch (InvalidShippingMethodException) {
            $this->assertFalse($allowed);
            $this->assertSame(['Delivery', ShippingDestinationScope::ANY, null, 500], [$method->name(), $method->destinationScope(), $method->deliveryType(), $method->amountMinor()]);

            return;
        }

        $this->assertTrue($allowed);
        $this->assertSame(['Renamed', $scope, $label], [$method->name(), $method->destinationScope(), $method->deliveryType()]);
    }

    public function test_reconstitute_carries_the_scope_and_keeps_the_boolean_form_working(): void
    {
        $any = ShippingMethod::reconstituteFromStorage('9', '1', 'M', ShippingMethodKind::FLAT, 0, true, 100, [], null, null, false, ShippingClassMode::REPLACE, null, null, ShippingDestinationScope::ANY);
        $this->assertSame(ShippingDestinationScope::ANY, $any->destinationScope());

        $old = ShippingMethod::reconstituteFromStorage('9', '1', 'M', ShippingMethodKind::FLAT, 0, true, 100, [], null, null, true);
        $this->assertSame(ShippingDestinationScope::PICKUP, $old->destinationScope());
    }
}
