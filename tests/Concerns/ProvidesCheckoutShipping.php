<?php

namespace Tests\Concerns;

use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;

/**
 * Shipping stage 4e: checkout needs a shipping choice (no rollout switch), so a test that places an order for some other
 * reason gets the cheapest honest one — a FREE method in a zone that covers the countries the tests use (one for a home
 * address, one for a pickup point), so every existing total stays exactly as it was.
 *
 * The handle is a well-formed one the quote never issued, with expected_shipping_minor = 0: the resolver accepts a lost
 * handle for a LOCAL method when the customer provably saw the exact figure (O1, decision-table row 3). Tests that are
 * ABOUT the shipping (CheckoutShippingWiringTest) use real handles from the real quote.
 */
trait ProvidesCheckoutShipping
{
    /** @var array{home: string, pickup: string}|null */
    private ?array $freeShippingMethodIds = null;

    /** @return array{home: string, pickup: string} */
    private function freeShippingMethods(): array
    {
        if ($this->freeShippingMethodIds === null) {
            $zone = ShippingZone::create('Everywhere (test)', 0, ['BG', 'GR', 'XK', 'DE', 'FR', 'RO', 'RS', 'MK', 'TR', 'GB', 'US', 'AT', 'IT', 'ES', 'PL']);
            app(ShippingZoneRepository::class)->save($zone);

            $ids = [];
            foreach (['home' => false, 'pickup' => true] as $key => $pickup) {
                $method = ShippingMethod::create((string) $zone->id(), $pickup ? 'Free pickup' : 'Free delivery', ShippingMethodKind::FREE, $pickup ? 1 : 0, true, null, [], null, null, $pickup);
                app(ShippingMethodRepository::class)->save($method);
                $ids[$key] = (string) $method->id();
            }

            $this->freeShippingMethodIds = $ids;
        }

        return $this->freeShippingMethodIds;
    }

    private function shippingMethodId(bool $pickup = false): string
    {
        return $this->freeShippingMethods()[$pickup ? 'pickup' : 'home'];
    }

    /** A well-formed handle the quote never issued (see the trait docblock). */
    private function lostShippingHandle(): string
    {
        return 'qh_'.str_repeat('a', 40);
    }

    /** All three fields as a named-argument array. */
    private function shippingFields(bool $pickup = false): array
    {
        return [
            'shippingMethodId' => $this->shippingMethodId($pickup),
            'quoteHandle' => $this->lostShippingHandle(),
            'expectedShippingMinor' => 0,
        ];
    }

    /** Request fields for POST /api/checkout. */
    private function shippingPayload(bool $pickup = false): array
    {
        $fields = $this->shippingFields($pickup);

        return [
            'shipping_method_id' => $fields['shippingMethodId'],
            'quote_handle' => $fields['quoteHandle'],
            'expected_shipping_minor' => $fields['expectedShippingMinor'],
        ];
    }
}
