<?php

namespace Tests\Feature\Deploy;

use App\Services\FreeShippingThreshold;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Services\ShippingZoneWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * DOCKER-1.1: redis has persistence off, so a redis restart EMPTIES the cache. Every "forever" key must therefore be recomputed on a miss, never
 * read as "nothing". Cache::flush() below is that restart.
 */
class CacheFlushRecomputesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_free_shipping_threshold_comes_back_after_the_cache_is_emptied(): void
    {
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        $zone = (string) app(ShippingZoneWriter::class)->create('Bulgaria', ['BG'], [], [])->id();
        app(ShippingMethodWriter::class)->create($zone, new ShippingMethodInput(
            name: 'Courier',
            kind: 'flat',
            active: true,
            price: Money::fromMinorUnits(500, 'EUR'),
            freeAbove: Money::fromMinorUnits(5000, 'EUR'),
        ));

        $this->assertSame(5000, app(FreeShippingThreshold::class)->current()['minor'] ?? null);

        Cache::flush();
        $this->assertNull(Cache::get(FreeShippingThreshold::KEY));

        $this->assertSame(5000, app(FreeShippingThreshold::class)->current()['minor'] ?? null, 'recomputed, not "no threshold"');
    }

    public function test_the_storefront_appearance_comes_back_after_the_cache_is_emptied(): void
    {
        $before = (new StorefrontAppearanceProbe())->read();

        Cache::flush();
        $this->assertNull(Cache::get(StorefrontAppearance::CACHE_KEY));
        $this->app->forgetScopedInstances();

        $this->assertSame($before, (new StorefrontAppearanceProbe())->read(), 'rebuilt from the settings');
        $this->assertNotNull(Cache::get(StorefrontAppearance::CACHE_KEY), 'and stored again');
    }
}

/** A fresh StorefrontAppearance from the container (the scoped instance is dropped by forgetScopedInstances). */
final class StorefrontAppearanceProbe
{
    public function read(): array
    {
        return app(StorefrontAppearance::class)->data();
    }
}
