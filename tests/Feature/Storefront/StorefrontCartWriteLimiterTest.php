<?php

namespace Tests\Feature\Storefront;

use App\Http\ApiRateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CART_WRITE (S3): POST /api/cart/lines, PATCH and DELETE /api/cart/lines/{variationId} share one budget; GET /api/cart and the
 * promotion routes are not under it. (The generic limiter conventions are pinned by ApiRateLimitersTest.)
 */
class StorefrontCartWriteLimiterTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const BUDGET = 3;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ratelimits.cart-write' => self::BUDGET]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
        $this->withHeader('Referer', 'http://localhost/');
        $this->seedPricing();
        $this->makeProduct(1, ['price' => '10.00', 'stock' => 9]);
    }

    public function test_the_name_the_config_row_and_the_three_routes(): void
    {
        $this->assertSame('cart-write', ApiRateLimits::CART_WRITE);
        $this->assertSame(30, (require base_path('config/ratelimits.php'))['cart-write']);

        $routes = ['POST api/cart/lines', 'PATCH api/cart/lines/{variationId}', 'DELETE api/cart/lines/{variationId}'];
        $throttled = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('throttle:'.ApiRateLimits::CART_WRITE, $route->gatherMiddleware(), true)) {
                foreach ($route->methods() as $method) {
                    if ($method !== 'HEAD') {
                        $throttled[] = $method.' '.$route->uri();
                    }
                }
            }
        }

        sort($routes);
        sort($throttled);
        $this->assertSame($routes, $throttled, 'cart-write is on the three line writes and on nothing else');
    }

    /**
     * Writes until the first refusal; returns how many were answered before it (any status but 429), at most $max.
     *
     * KEY SHAPE: IP + the session's cart token when it has one. The very first write of a session has no token yet, so it
     * is counted under the cart-less key ("none"); the later ones under the token's. A browser that keeps its session
     * therefore gets at most BUDGET + 1 answers; a client that throws sessions away is always in the cart-less bucket.
     *
     * @param callable(int): \Illuminate\Testing\TestResponse $write
     */
    private function answeredBeforeRefusal(callable $write, int $max = 80): int
    {
        for ($answered = 0; $answered < $max; $answered++) {
            if ($write($answered)->getStatusCode() === 429) {
                return $answered;
            }
        }

        return $max;
    }

    public function test_the_budget_is_spent_across_post_patch_and_delete_then_429_in_the_store_locale(): void
    {
        $answered = $this->answeredBeforeRefusal(fn (int $i) => match ($i % 3) {
            0 => $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]),
            1 => $this->patchJson('/api/cart/lines/101', ['quantity' => 2]),
            default => $this->deleteJson('/api/cart/lines/101'),
        });

        $this->assertGreaterThanOrEqual(self::BUDGET, $answered);
        $this->assertLessThanOrEqual(self::BUDGET + 1, $answered);

        $refused = $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]);
        $refused->assertStatus(429)->assertJsonPath('reason', 'too_many_requests');
        $this->assertNotEmpty($refused->json('message'));
        $this->assertNotNull($refused->headers->get('Retry-After'));
        $this->patchJson('/api/cart/lines/101', ['quantity' => 1])->assertStatus(429);
        $this->deleteJson('/api/cart/lines/101')->assertStatus(429);
    }

    public function test_reading_the_cart_and_the_promotion_routes_are_not_limited_by_it(): void
    {
        $this->answeredBeforeRefusal(fn () => $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]));
        $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/cart')->assertOk();
        }

        $this->putJson('/api/cart/promotion', ['code' => 'NOPE'])->assertStatus(422);
        $this->deleteJson('/api/cart/promotion')->assertStatus(200);
    }

    public function test_another_ip_has_its_own_budget(): void
    {
        $this->answeredBeforeRefusal(fn () => $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]));
        $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51']);
        $this->app['session.store']->flush();
        $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])->assertStatus(201);
    }

    public function test_a_session_thrown_away_each_time_stays_in_the_cartless_bucket_of_its_ip(): void
    {
        $answered = $this->answeredBeforeRefusal(function () {
            $this->app['session.store']->flush();

            return $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]);
        });

        $this->assertSame(self::BUDGET, $answered, 'rotating the session buys no fresh budget: every request is the same IP + "none"');
    }

    public function test_a_damaged_budget_row_falls_back_to_the_default_not_to_no_limit(): void
    {
        config(['ratelimits.cart-write' => 'banana']);

        $answered = $this->answeredBeforeRefusal(fn () => $this->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1]));

        $this->assertGreaterThanOrEqual(ApiRateLimits::DEFAULT_PER_MINUTE, $answered);
        $this->assertLessThanOrEqual(ApiRateLimits::DEFAULT_PER_MINUTE + 1, $answered);
    }
}
