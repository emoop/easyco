<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\CarrierRegistrationException;
use EasyCo\Shipping\ShippingCode;

/**
 * One registry entry: a carrier's code, the name a merchant sees in the admin,
 * and which of the three capabilities it declares. The entry says what the
 * carrier CLAIMS; the container bindings (CarrierCapability::containerKey())
 * are what actually serve it, and the resolver refuses a declared capability
 * whose binding is missing.
 */
final class CarrierRegistration
{
    /** @var list<CarrierCapability> */
    public readonly array $capabilities;

    /** @param list<CarrierCapability> $capabilities */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        array $capabilities,
    ) {
        if (! ShippingCode::isValid($code)) {
            throw CarrierRegistrationException::invalidCode($code);
        }

        if (trim($name) === '') {
            throw CarrierRegistrationException::emptyName($code);
        }

        $unique = [];

        foreach ($capabilities as $capability) {
            $unique[$capability->value] = $capability;
        }

        if ($unique === []) {
            throw CarrierRegistrationException::noCapabilities($code);
        }

        $this->capabilities = array_values($unique);
    }

    public function provides(CarrierCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
