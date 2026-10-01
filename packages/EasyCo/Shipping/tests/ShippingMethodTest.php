<?php

namespace EasyCo\Shipping\Tests;

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
}
