<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\CarrierRegistrationException;

/**
 * The list of registered carriers (shipping-domain-design.md §6).
 *
 * WHY A REGISTRY AT ALL: PaymentMethodAdapterResolver::availableMethods() is a
 * hand-written list in core, which cannot work for carriers that live in
 * extension packages. The container cannot answer "which carriers exist" either:
 * bindings are keyed by capability, and scanning them would depend on keys this
 * class does not own. So a carrier's package registers one entry here, from its
 * service provider, next to binding its keys — core lists nothing.
 *
 * Plain PHP, no container: the Shipping service provider holds ONE instance as a
 * singleton. Registration order is listing order. A code can be registered once;
 * a second registration is refused rather than silently replacing the first.
 */
final class CarrierRegistry
{
    /** @var array<string, CarrierRegistration> */
    private array $carriers = [];

    /** @throws CarrierRegistrationException */
    public function register(CarrierRegistration $registration): void
    {
        if (isset($this->carriers[$registration->code])) {
            throw CarrierRegistrationException::alreadyRegistered($registration->code);
        }

        $this->carriers[$registration->code] = $registration;
    }

    public function has(string $code): bool
    {
        return isset($this->carriers[$code]);
    }

    public function find(string $code): ?CarrierRegistration
    {
        return $this->carriers[$code] ?? null;
    }

    /** @return list<CarrierRegistration> in registration order */
    public function all(): array
    {
        return array_values($this->carriers);
    }

    /** @return list<CarrierRegistration> the carriers that declare $capability */
    public function providing(CarrierCapability $capability): array
    {
        return array_values(array_filter($this->carriers, static fn (CarrierRegistration $c): bool => $c->provides($capability)));
    }
}
