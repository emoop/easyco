<?php

namespace Tests\Feature\Storefront\Cache;

use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/** a cache store that is down: every read and write throws */
class ThrowingStore extends ArrayStore
{
    public function get($key)
    {
        throw new \RuntimeException('cache down');
    }

    public function put($key, $value, $seconds)
    {
        throw new \RuntimeException('cache down');
    }

    public function forever($key, $value)
    {
        throw new \RuntimeException('cache down');
    }
}

/**
 * P8b review: (a) a page VIEW never collects a purge, even on a cold cache or with a cache store that is down; (b) the writes that run per order or on a schedule
 * (an order, the scheduled commands) never collect `all`.
 *
 * The test is not a request that terminates by itself: the purger records what the TERMINATING flush really sent (an empty collector sends nothing).
 */
class ReadsAndWritesTest extends TestCase
{
    use BuildsCacheShop;
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    private RecordingPurger $purger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        $this->purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $this->purger);
        app(CachePurgeCollector::class)->reset();
    }

    private const PAGES = ['/', '/product/alpha', '/product-category/clothing', '/brand/acme', '/info/about', '/robots.txt', '/sitemap.xml'];

    private function freeShippingBadgeOn(): void
    {
        $working = BadgeSet::defaults();

        foreach ($working['badges'] as &$row) {
            if ($row['kind'] === BadgeSet::FREE_SHIPPING) {
                $row['enabled'] = true;
                $row['text'] = 'Free shipping';
            }
        }

        [$clean, $errors] = BadgeSet::validate($working);
        $this->assertSame([], $errors, json_encode($errors));
        BadgeStore::publish($clean);
        $this->shippingPayload(); // a free method exists, so the threshold is a real number
        app(CachePurgeCollector::class)->reset();
    }

    /** ★ Cache::flush() before EACH view: a cold cache rebuilds the appearance, the menus, the free-shipping threshold, the filters... and collects nothing */
    public function test_a_cold_cache_page_view_collects_nothing(): void
    {
        $this->freeShippingBadgeOn(); // the badge makes the page ask for the cached threshold, which a cold cache recomputes (refresh())

        foreach (self::PAGES as $url) {
            Cache::flush();
            $this->fresh();
            $this->purger->batches = [];

            $this->get($url)->assertOk();

            $this->assertSame([], app(CachePurgeCollector::class)->pendingKeys(), "{$url}: pending");
            $this->assertSame([], $this->purger->batches, "{$url}: the request's own flush sent a purge");
        }
    }

    /** ★ a cache store that throws on get / put / forever: the views that survive it still collect nothing */
    public function test_a_page_view_with_a_broken_cache_store_collects_nothing(): void
    {
        Cache::extend('throwing', fn () => new Repository(new ThrowingStore));
        config(['cache.stores.throwing' => ['driver' => 'throwing'], 'cache.default' => 'throwing']);
        Cache::forgetDriver('throwing');
        Cache::setDefaultDriver('throwing');

        foreach (self::PAGES as $url) {
            $this->fresh();
            $this->purger->batches = [];

            $this->get($url); // the status is not the point (a store that is down may well break a page): the point is that no page view asks for a purge

            $this->assertSame([], app(CachePurgeCollector::class)->pendingKeys(), "{$url}: pending");
            $this->assertSame([], $this->purger->batches, "{$url}: the request's own flush sent a purge");
        }
    }

    /** ★ placing an order and running every scheduled command collect NO `all` (a checkout may collect `p{id}` from the stock) */
    public function test_an_order_and_the_scheduled_commands_collect_no_all(): void
    {
        $this->withHeader('Referer', 'http://localhost/');
        $shipping = $this->shippingPayload(); // creates the zone and the methods (their hooks purge: the setup is not what is measured)

        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(201)->json('cart_id');

        app(CachePurgeCollector::class)->reset();
        $this->purger->batches = [];

        $this->postJson('/api/checkout', array_merge([
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_first_name' => 'Guest', 'recipient_last_name' => 'Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1',
        ], $shipping))->assertStatus(201);

        $this->assertSame(1, OrderModel::query()->count());
        $pending = app(CachePurgeCollector::class)->pendingKeys();
        $this->assertNotContains('all', $pending, 'an order: ['.implode(' ', $pending).']');
        app(CachePurgeCollector::class)->flush();
        $this->assertNotContains('all', $this->purger->keys(), 'an order (flushed)');

        foreach (['prices:refresh-boundaries', 'prices:refresh-from', 'mail:reconcile-order-confirmations', 'order-blocks:prune', 'activity-log:prune', 'privacy:anonymise-order-ips'] as $command) {
            app(CachePurgeCollector::class)->reset();
            $this->purger->batches = [];

            Artisan::call($command);
            app(CachePurgeCollector::class)->flush();

            $this->assertNotContains('all', $this->purger->keys(), "{$command} must not purge all: [".implode(' ', $this->purger->keys()).']');
        }
    }
}
