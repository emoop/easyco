<?php

namespace Tests\Feature;

use App\Services\Exceptions\CarrierCapabilityNotProvidedException;
use App\Services\Exceptions\UnknownShippingCarrierException;
use App\Services\ShippingProviderResolver;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use Tests\Support\Shipping\FakeLabelProvider;
use Tests\Support\Shipping\FakePickupProvider;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 3c (shipping-domain-design.md §6): the resolver refuses by name
 * and never falls back; carriers are listed from the registry, so an extension
 * package adds one by binding its keys and registering — core is not edited.
 */
class ShippingProviderResolverTest extends TestCase
{
    private function resolver(): ShippingProviderResolver
    {
        return $this->app->make(ShippingProviderResolver::class);
    }

    /** What an extension package's service provider does: bind its keys, register one entry. */
    private function installCarrier(string $code, array $capabilities, ?array $bindOnly = null): void
    {
        $bind = $bindOnly ?? $capabilities;

        foreach ($bind as $capability) {
            $this->app->bind($capability->containerKey($code), fn () => match ($capability) {
                CarrierCapability::RATE => new FakeRateProvider(fn () => []),
                CarrierCapability::PICKUP => new FakePickupProvider(fn () => []),
                CarrierCapability::LABEL => new FakeLabelProvider(),
            });
        }

        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration($code, ucfirst($code), $capabilities));
    }

    public function test_core_registers_no_carrier_and_an_unknown_carrier_is_refused_by_name(): void
    {
        $this->assertSame([], $this->resolver()->carriers(), 'V1 ships no carrier');

        foreach (['rateProvider', 'pickupPointProvider', 'labelProvider'] as $method) {
            try {
                $this->resolver()->{$method}('econt');
                $this->fail("{$method} must refuse an unknown carrier");
            } catch (UnknownShippingCarrierException $e) {
                $this->assertSame('econt', $e->carrierCode);
                $this->assertStringContainsString('"econt"', $e->getMessage());
            }
        }
    }

    public function test_a_malformed_carrier_code_is_refused_as_unknown_without_touching_the_container(): void
    {
        // A dotted code must never be able to reach another container key.
        foreach (['', 'Econt', 'econt.rate', '../x', str_repeat('a', 65)] as $bad) {
            try {
                $this->resolver()->rateProvider($bad);
                $this->fail('a malformed carrier code must be refused');
            } catch (UnknownShippingCarrierException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_carrier_that_binds_only_rate_is_refused_for_pickup_and_label_by_name(): void
    {
        $this->installCarrier('acme', [CarrierCapability::RATE]);

        $this->assertInstanceOf(FakeRateProvider::class, $this->resolver()->rateProvider('acme'));

        foreach ([['pickupPointProvider', 'pickup'], ['labelProvider', 'label']] as [$method, $capability]) {
            try {
                $this->resolver()->{$method}('acme');
                $this->fail("{$method} must be refused");
            } catch (CarrierCapabilityNotProvidedException $e) {
                $this->assertSame('acme', $e->carrierCode);
                $this->assertSame($capability, $e->capability->value);
                $this->assertStringContainsString('"acme"', $e->getMessage());
                $this->assertStringContainsString($capability, $e->getMessage());
            }
        }
    }

    public function test_there_is_no_fallback_to_another_carrier_or_another_capability(): void
    {
        $this->installCarrier('acme', [CarrierCapability::RATE]);
        $this->installCarrier('beta', [CarrierCapability::PICKUP]);

        // acme has no pickup although beta does; beta has no rate although acme does.
        $this->expectException(CarrierCapabilityNotProvidedException::class);
        try {
            $this->resolver()->rateProvider('beta');
        } finally {
            $this->assertInstanceOf(FakePickupProvider::class, $this->resolver()->pickupPointProvider('beta'));
            try {
                $this->resolver()->pickupPointProvider('acme');
                $this->fail('acme must not borrow beta\'s pickup provider');
            } catch (CarrierCapabilityNotProvidedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_declared_capability_with_nothing_bound_is_refused_by_name(): void
    {
        $this->installCarrier('acme', [CarrierCapability::RATE, CarrierCapability::PICKUP], bindOnly: [CarrierCapability::RATE]);

        try {
            $this->resolver()->pickupPointProvider('acme');
            $this->fail('a declared but unbound capability must be refused');
        } catch (CarrierCapabilityNotProvidedException $e) {
            $this->assertStringContainsString('shipping.carrier.acme.pickup', $e->getMessage());
        }
    }

    public function test_keys_bound_without_a_registry_entry_are_not_a_carrier(): void
    {
        $this->app->bind('shipping.carrier.ghost.rate', fn () => new FakeRateProvider(fn () => []));

        $this->expectException(UnknownShippingCarrierException::class);

        $this->resolver()->rateProvider('ghost');
    }

    public function test_a_key_bound_to_something_that_is_not_the_contract_is_refused(): void
    {
        $this->app->bind('shipping.carrier.acme.rate', fn () => new \stdClass());
        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration('acme', 'Acme', [CarrierCapability::RATE]));

        $this->expectException(CarrierCapabilityNotProvidedException::class);

        $this->resolver()->rateProvider('acme');
    }

    public function test_an_extension_that_registers_a_new_carrier_is_listed_and_resolved_without_editing_core(): void
    {
        $this->installCarrier('acme', [CarrierCapability::RATE, CarrierCapability::LABEL]);
        $this->installCarrier('beta', [CarrierCapability::PICKUP]);

        $this->assertSame(['acme', 'beta'], array_map(static fn (CarrierRegistration $c): string => $c->code, $this->resolver()->carriers()));
        $this->assertSame(['acme'], array_map(static fn (CarrierRegistration $c): string => $c->code, $this->resolver()->carriersProviding(CarrierCapability::LABEL)));
        $this->assertInstanceOf(FakeRateProvider::class, $this->resolver()->rateProvider('acme'));
        $this->assertInstanceOf(FakeLabelProvider::class, $this->resolver()->labelProvider('acme'));
        $this->assertInstanceOf(FakePickupProvider::class, $this->resolver()->pickupPointProvider('beta'));
    }

    public function test_the_registry_is_one_shared_instance(): void
    {
        $this->assertSame($this->app->make(CarrierRegistry::class), $this->app->make(CarrierRegistry::class));
    }
}
