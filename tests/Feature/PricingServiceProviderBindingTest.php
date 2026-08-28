<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Persistence\Eloquent\EloquentPriceResolver;
use Tests\TestCase;

/**
 * Confirms the container binding itself, not just EloquentPriceResolver's
 * own behavior in isolation (already covered by EloquentPriceResolverTest)
 * — PricingServiceProvider must actually resolve PriceResolver::class to
 * EloquentPriceResolver, not merely have code that looks like it does.
 */
class PricingServiceProviderBindingTest extends TestCase
{
    public function test_price_resolver_binding_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(EloquentPriceResolver::class, app(PriceResolver::class));
    }
}
