<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Exceptions\CarrierRegistrationException;
use PHPUnit\Framework\TestCase;

final class CarrierRegistryTest extends TestCase
{
    public function test_a_fresh_registry_lists_no_carrier_core_ships_none(): void
    {
        $registry = new CarrierRegistry();

        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->has('econt'));
        $this->assertNull($registry->find('econt'));
    }

    public function test_registered_carriers_are_listed_in_registration_order_with_their_capabilities(): void
    {
        $registry = new CarrierRegistry();
        $registry->register(new CarrierRegistration('speedy', 'Speedy', [CarrierCapability::RATE, CarrierCapability::LABEL]));
        $registry->register(new CarrierRegistration('boxnow', 'BOX NOW', [CarrierCapability::PICKUP]));

        $this->assertSame(['speedy', 'boxnow'], array_map(static fn (CarrierRegistration $c): string => $c->code, $registry->all()));
        $this->assertSame(['speedy'], array_map(static fn (CarrierRegistration $c): string => $c->code, $registry->providing(CarrierCapability::RATE)));
        $this->assertSame(['boxnow'], array_map(static fn (CarrierRegistration $c): string => $c->code, $registry->providing(CarrierCapability::PICKUP)));
        $this->assertTrue($registry->find('speedy')->provides(CarrierCapability::LABEL));
        $this->assertFalse($registry->find('boxnow')->provides(CarrierCapability::RATE));
    }

    public function test_a_second_registration_of_the_same_code_is_refused_not_replaced(): void
    {
        $registry = new CarrierRegistry();
        $registry->register(new CarrierRegistration('econt', 'Econt', [CarrierCapability::RATE]));

        $this->expectException(CarrierRegistrationException::class);
        $this->expectExceptionMessage('"econt" is already registered');

        $registry->register(new CarrierRegistration('econt', 'Econt again', [CarrierCapability::PICKUP]));
    }

    public function test_a_registration_needs_a_valid_code_a_name_and_a_capability(): void
    {
        foreach ([['Econt', 'Econt', [CarrierCapability::RATE]], ['econt', ' ', [CarrierCapability::RATE]], ['econt', 'Econt', []]] as $args) {
            try {
                new CarrierRegistration(...$args);
                $this->fail('expected a refusal');
            } catch (CarrierRegistrationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_capabilities_are_deduplicated_and_the_container_keys_follow_the_convention(): void
    {
        $registration = new CarrierRegistration('econt', 'Econt', [CarrierCapability::RATE, CarrierCapability::RATE]);

        $this->assertCount(1, $registration->capabilities);
        $this->assertSame('shipping.carrier.econt.rate', CarrierCapability::RATE->containerKey('econt'));
        $this->assertSame('shipping.carrier.econt.pickup', CarrierCapability::PICKUP->containerKey('econt'));
        $this->assertSame('shipping.carrier.econt.label', CarrierCapability::LABEL->containerKey('econt'));
    }
}
