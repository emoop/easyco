<?php

namespace EasyCo\Shipping\Carrier;

/**
 * The three things a carrier may do, each a separate contract
 * (shipping-domain-design.md §6). A carrier implements only the ones it can: a
 * locker network may list pickup points and print labels but have no live rate.
 *
 * containerKey() is THE naming convention of the named container bindings
 * (`shipping.carrier.<code>.rate|pickup|label`), written once so the resolver,
 * the registry's tests and an extension package cannot spell it differently.
 */
enum CarrierCapability: string
{
    case RATE = 'rate';
    case PICKUP = 'pickup';
    case LABEL = 'label';

    public function containerKey(string $carrierCode): string
    {
        return 'shipping.carrier.'.$carrierCode.'.'.$this->value;
    }
}
