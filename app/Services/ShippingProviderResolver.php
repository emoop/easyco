<?php

namespace App\Services;

use App\Couriers\CourierDirectory;
use App\Services\Exceptions\CarrierCapabilityNotProvidedException;
use App\Services\Exceptions\UnknownShippingCarrierException;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\PickupPointCatalogue;
use EasyCo\Shipping\Contracts\ShipmentGateway;
use EasyCo\Shipping\Contracts\ShippingRateProvider;
use EasyCo\Shipping\ShippingCode;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a carrier's provider by carrier code and capability
 * (shipping-domain-design.md §6) — PaymentMethodAdapterResolver's twin.
 *
 * A carrier's package binds each capability it offers as a NAMED container key,
 * `shipping.carrier.<code>.rate`, `.pickup`, `.label`, and registers ONE entry in
 * the CarrierRegistry (code, name, capabilities). Nothing in core lists carriers.
 *
 * THERE IS NEVER A DEFAULT OR FALLBACK. Refused by name:
 *  - a code that is not registered (or not even shaped like a carrier code) —
 *    UnknownShippingCarrierException. A carrier that bound its keys but never
 *    registered is unknown too: it would not be listed, and an unlisted carrier
 *    must not be reachable;
 *  - an integrated courier (one with a CourierDescriptor) that the merchant has not switched on
 *    in «Куриери»: unknown as well (COUR-1a);
 *  - a registered carrier that does not offer the capability, or declared it and
 *    bound nothing, or bound something that is not the contract —
 *    CarrierCapabilityNotProvidedException.
 */
final class ShippingProviderResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly CarrierRegistry $registry,
        private readonly CourierDirectory $couriers,
    ) {}

    /** @throws UnknownShippingCarrierException|CarrierCapabilityNotProvidedException */
    public function rateProvider(string $carrierCode): ShippingRateProvider
    {
        return $this->resolve($carrierCode, CarrierCapability::RATE, ShippingRateProvider::class);
    }

    /** @throws UnknownShippingCarrierException|CarrierCapabilityNotProvidedException */
    public function pickupPointCatalogue(string $carrierCode): PickupPointCatalogue
    {
        return $this->resolve($carrierCode, CarrierCapability::PICKUP, PickupPointCatalogue::class);
    }

    /** @throws UnknownShippingCarrierException|CarrierCapabilityNotProvidedException */
    public function shipmentGateway(string $carrierCode): ShipmentGateway
    {
        return $this->resolve($carrierCode, CarrierCapability::LABEL, ShipmentGateway::class);
    }

    /**
     * Every registered carrier that may be used, in registration order — what an admin screen or a
     * method form lists. Read from the registry, never from a list written here. An installed
     * integrated courier that is not enabled is NOT listed (the same rule resolve() applies):
     * it is unreachable from every path, the admin forms included.
     *
     * @return list<CarrierRegistration>
     */
    public function carriers(): array
    {
        return array_values(array_filter($this->registry->all(), fn (CarrierRegistration $c): bool => $this->couriers->isCallable($c->code)));
    }

    /** @return list<CarrierRegistration> the carriers that declare $capability */
    public function carriersProviding(CarrierCapability $capability): array
    {
        return array_values(array_filter($this->registry->providing($capability), fn (CarrierRegistration $c): bool => $this->couriers->isCallable($c->code)));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function resolve(string $carrierCode, CarrierCapability $capability, string $contract): object
    {
        $registration = ShippingCode::isValid($carrierCode) ? $this->registry->find($carrierCode) : null;

        if ($registration === null) {
            throw new UnknownShippingCarrierException($carrierCode);
        }

        // An INSTALLED but not ENABLED integrated courier is exactly as unknown (courier-integration-design.md §2.2): every
        // path (checkout quote, sync, a future shipment) goes through here, so a disabled courier is unreachable from all of them.
        if (! $this->couriers->isCallable($carrierCode)) {
            throw new UnknownShippingCarrierException($carrierCode);
        }

        if (! $registration->provides($capability)) {
            throw new CarrierCapabilityNotProvidedException($carrierCode, $capability, 'the carrier does not offer it');
        }

        $key = $capability->containerKey($carrierCode);

        if (! $this->container->bound($key)) {
            throw new CarrierCapabilityNotProvidedException($carrierCode, $capability, "it is declared but nothing is bound under \"{$key}\"");
        }

        $provider = $this->container->make($key);

        if (! $provider instanceof $contract) {
            throw new CarrierCapabilityNotProvidedException($carrierCode, $capability, "\"{$key}\" is not bound to a {$contract}");
        }

        return $provider;
    }
}
