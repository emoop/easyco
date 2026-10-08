<?php

namespace Tests\Feature;

use App\Http\ApiRateLimits;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Cache\RateLimiter as LimiterRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Input-hardening pass 3, item (c): the named API rate limiters.
 *
 * ShippingQuoteEndpointTest already tests shipping-quote where it lives — its budget
 * against two SUCCESSFUL requests, its Bulgarian sentence, the fact that its budget is
 * per cart. What this file tests is the REGISTRY the pass turned ApiRateLimits into:
 * that the five limiters are exactly the five the class names, that each has one
 * config row and only the routes that document it, that a budget is spent the same way
 * at every one of them (the last request inside it is answered, the next one is a 429
 * in the STORE locale with the standard headers), and that the two key shapes the
 * class documents — the IP alone (four of the five), and the IP plus the identity of
 * the cart (shipping-quote, whose work is per cart) — behave the way its docblock says
 * they do.
 *
 * WHY A BUDGET IS SPENT HERE WITH REQUESTS THE ENDPOINT REFUSES (a validation error,
 * no cart to promote): a limiter counts a request the moment it arrives, whatever the
 * endpoint then answers with, and a happy path in this file would mean building a
 * product, a price, a stock level and a cart before the third request could be shown
 * as refused — which would hide the limiter behind the setup. The one thing that
 * choice does NOT prove is that a successful request is counted;
 * ShippingQuoteEndpointTest's own 429 test (two 200s, then a 429) is that proof, on
 * the same middleware and the same hit() call.
 */
class ApiRateLimitersTest extends TestCase
{
    use RefreshDatabase;

    /** Two client IPs, so "another visitor" means something. */
    private const THIS_IP = '203.0.113.10';

    private const OTHER_IP = '203.0.113.11';

    /** The two key shapes ApiRateLimits's docblock documents, "TWO KEY SHAPES". */
    private const KEYED_BY_IP_ALONE = 'ip alone';

    private const KEYED_BY_IP_AND_CART = 'ip + cart identity';

    /**
     * The registry, spelled out: each limiter's config row, its key shape, its refusal
     * sentence, the routes that carry `throttle:<name>`, and the request this file uses
     * to spend one unit of its budget.
     *
     * This array IS the expectation the assertions below read, so a limiter added to
     * ApiRateLimits and forgotten here fails
     * test_the_registry_holds_exactly_the_five_limiters_this_file_tests — which is the
     * point of writing it down twice.
     *
     * @var array<string, array{config: string, key: string, refusal: string, routes: list<array{0: string, 1: string}>, inside: array{0: string, 1: string, 2: array<string, mixed>, 3: int}}>
     */
    private const LIMITERS = [
        ApiRateLimits::SHIPPING_QUOTE => [
            'config' => 'ratelimits.'.ApiRateLimits::SHIPPING_QUOTE,
            'key' => self::KEYED_BY_IP_AND_CART,
            'refusal' => 'shipping_quote.too_many_requests',
            'routes' => [['POST', 'api/shipping/quote']],
            'inside' => ['POST', 'api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'], 422],
        ],

        ApiRateLimits::REGISTRATION => [
            'config' => 'ratelimits.'.ApiRateLimits::REGISTRATION,
            'key' => self::KEYED_BY_IP_ALONE,
            'refusal' => 'rate_limit.too_many_requests',
            'routes' => [['POST', 'api/account/register']],
            // An address the domain itself would refuse: the request is counted, the
            // endpoint answers 422, and no account row is written on the way.
            'inside' => ['POST', 'api/account/register', [
                'email' => 'not-an-address',
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ], 422],
        ],

        ApiRateLimits::CHECKOUT => [
            'config' => 'ratelimits.'.ApiRateLimits::CHECKOUT,
            // IP alone: the cart half of an IP + cart key is the caller's to choose
            // with a fresh session cookie, and the owner's decision for checkout is
            // the per-IP limit (shipping-domain-design.md §9.1.8, "keyed by IP ONLY").
            'key' => self::KEYED_BY_IP_ALONE,
            'refusal' => 'rate_limit.too_many_requests',
            'routes' => [['POST', 'api/checkout']],
            // No cart_id: a 422 from the controller's own rules, before the
            // orchestrator is reached at all.
            'inside' => ['POST', 'api/checkout', [], 422],
        ],

        ApiRateLimits::ADDRESS => [
            'config' => 'ratelimits.'.ApiRateLimits::ADDRESS,
            // IP alone too, for the same reason: a public write of rows whose cart
            // half the caller chooses just as freely.
            'key' => self::KEYED_BY_IP_ALONE,
            'refusal' => 'rate_limit.too_many_requests',
            // Two routes, and this file spends the budget at the PUBLIC one:
            // PUT /api/addresses/{addressId} sits inside auth:customer, where
            // Authenticate outranks ThrottleRequests in the framework's middleware
            // priority — an unauthenticated request is a 401 that never reaches the
            // limiter and is therefore NOT counted. That is the route map's business,
            // not this test's: both routes carry one name, one budget and one key.
            'routes' => [['PUT', 'api/addresses/{addressId}'], ['POST', 'api/addresses']],
            'inside' => ['POST', 'api/addresses', [], 422],
        ],

        ApiRateLimits::PROMOTION => [
            'config' => 'ratelimits.'.ApiRateLimits::PROMOTION,
            'key' => self::KEYED_BY_IP_ALONE,
            'refusal' => 'rate_limit.too_many_requests',
            'routes' => [['PUT', 'api/cart/promotion']],
            // A well-formed code with no cart to apply it to: 404, and counted.
            'inside' => ['PUT', 'api/cart/promotion', ['code' => 'SAVE10'], 404],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Every request in this file comes from one known address unless a test says
        // otherwise — the limiter keys on the IP, so the IP has to be a fact rather
        // than whatever the test runner happens to have.
        $this->withServerVariables(['REMOTE_ADDR' => self::THIS_IP]);

        // See account-domain-design.md §10: a guest's session — and with it the cart
        // token half of a limiter key — needs a recognized Referer to engage Sanctum's
        // stateful pipeline. ShippingQuoteEndpointTest's setUp does the same thing for
        // the same reason.
        $this->withHeader('Referer', 'http://localhost/');
    }

    // --- the registry ------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function limiters(): array
    {
        $cases = [];

        foreach (self::LIMITERS as $name => $limiter) {
            $cases[$name] = [$name, $limiter];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function ipAloneLimiters(): array
    {
        return array_filter(
            self::limiters(),
            static fn (array $case): bool => $case[1]['key'] === self::KEYED_BY_IP_ALONE,
        );
    }

    public function test_the_registry_holds_exactly_the_five_limiters_this_file_tests(): void
    {
        $expected = array_keys(self::LIMITERS);
        sort($expected);

        $registered = $this->registeredLimiterNames();
        sort($registered);

        $this->assertSame(
            $expected,
            $registered,
            'ApiRateLimits::register() defines exactly these five named limiters, and nothing else in the application defines one',
        );
    }

    /**
     * One config row, one registered definition, only the routes that document the
     * limiter — plus the key shape its docblock promises.
     *
     * @param  array<string, mixed>  $limiter
     */
    #[DataProvider('limiters')]
    public function test_every_limiter_has_one_row_one_definition_and_only_the_routes_that_document_it(string $name, array $limiter): void
    {
        $row = $limiter['config'];

        // The row's own name is the limiter's name — ApiRateLimits reads its budget with
        // config('ratelimits.'.$name), so a row under any other key is a row nobody reads.
        $this->assertTrue(
            array_key_exists($name, config('ratelimits')),
            "config/ratelimits.php has a {$name} row ({$row})",
        );
        $this->assertNotNull(config($row), "the {$row} row resolves");
        $this->assertIsInt(config($row), "the {$row} row is a whole number of requests per minute");
        $this->assertGreaterThan(0, config($row), "the {$row} row is a budget, not zero or negative");

        $carrying = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('throttle:'.$name, $route->gatherMiddleware(), true)) {
                foreach ($route->methods() as $method) {
                    $carrying[] = [$method, $route->uri()];
                }
            }
        }

        $expected = $limiter['routes'];
        sort($carrying);
        sort($expected);

        $this->assertSame($expected, $carrying, "the {$name} limiter is on exactly the routes it documents, and no others");

        $key = $this->limitFor($name)->key;

        $this->assertIsString($key);
        $this->assertStringStartsWith($name.'|', $key, 'a limiter prefixes its key with its own name, so no two limiters share a bucket');
        $this->assertStringContainsString(self::THIS_IP, $key, 'the client IP is always half of the key');

        $segments = explode('|', $key);
        $this->assertCount(
            $limiter['key'] === self::KEYED_BY_IP_ALONE ? 2 : 3,
            $segments,
            "the {$name} limiter keys on ".$limiter['key'].' — see ApiRateLimits\'s own docblock, "TWO KEY SHAPES"',
        );

        if ($limiter['key'] === self::KEYED_BY_IP_AND_CART) {
            $this->assertSame('none', $segments[2], 'a visitor with no cart is the third identity');
        }
    }

    /**
     * @param  array<string, mixed>  $limiter
     */
    #[DataProvider('limiters')]
    public function test_a_damaged_budget_row_falls_back_to_the_default_in_the_class_rather_than_to_no_limit(string $name, array $limiter): void
    {
        foreach ([0, -5] as $damaged) {
            config([$limiter['config'] => $damaged]);

            $this->assertSame(
                ApiRateLimits::DEFAULT_PER_MINUTE,
                $this->limitFor($name)->maxAttempts,
                "a {$limiter['config']} of {$damaged} is not a budget — the class's own default applies, not no limit at all",
            );
        }

        config([$limiter['config'] => 7]);

        $this->assertSame(7, $this->limitFor($name)->maxAttempts, 'a healthy row is used exactly as written');
    }

    // --- what a budget does ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $limiter
     */
    #[DataProvider('limiters')]
    public function test_the_last_request_inside_a_budget_is_answered_and_the_next_one_is_refused_in_the_store_locale(string $name, array $limiter): void
    {
        config([$limiter['config'] => 2]);
        $this->storeLocale('bg');

        [$method, $uri, $body, $insideStatus] = $limiter['inside'];

        $first = $this->send($method, $uri, $body)->assertStatus($insideStatus);
        $this->assertSame('2', $first->headers->get('X-RateLimit-Limit'), 'the budget is reported on an answered request too, not only on the refusal');
        $this->assertSame('1', $first->headers->get('X-RateLimit-Remaining'));

        $this->send($method, $uri, $body)->assertStatus($insideStatus);

        $refused = $this->send($method, $uri, $body)->assertStatus(429);

        $this->assertSame('too_many_requests', $refused->json('reason'));
        $this->assertSame(__($limiter['refusal'], [], 'bg'), $refused->json('message'), "the {$name} refusal is translated in the store locale");
        $this->assertNotSame(__($limiter['refusal'], [], 'en'), $refused->json('message'), 'and it is not the English sentence');
        $this->assertNotNull($refused->headers->get('Retry-After'));
        $this->assertSame('2', $refused->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $refused->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * The four limiters keyed by the IP alone exist because in ALL of them the caller
     * gets to choose the other half of an IP + cart key — a fresh session cookie is a
     * fresh budget (ApiRateLimits's own docblock, "TWO KEY SHAPES"). This is that claim,
     * spent at every one of them (the provider takes the LIMITERS map's own four):
     * a new session does not reset the budget, and a second IP has its own.
     *
     * @param  array<string, mixed>  $limiter
     */
    #[DataProvider('ipAloneLimiters')]
    public function test_a_budget_keyed_by_the_ip_alone_is_not_reset_by_a_new_session_and_not_spent_by_another_ip(string $name, array $limiter): void
    {
        config([$limiter['config'] => 2]);

        [$method, $uri, $body, $insideStatus] = $limiter['inside'];

        $this->send($method, $uri, $body)->assertStatus($insideStatus);
        $this->send($method, $uri, $body)->assertStatus($insideStatus);

        // A brand-new session — a new cart token, a fresh cookie jar — is the cheapest
        // way a caller can try to choose their own budget, and it buys nothing here.
        $this->flushSession();
        $this->withSession(['cart_token' => 'a-brand-new-cart-'.Str::uuid()]);

        $this->send($method, $uri, $body)->assertStatus(429);

        // A different IP is a different visitor, and has its own budget.
        $this->withServerVariables(['REMOTE_ADDR' => self::OTHER_IP]);

        $this->send($method, $uri, $body)->assertStatus($insideStatus);
    }

    /**
     * The other half of the same argument: the ONE limiter keyed by the IP AND the cart
     * is shipping-quote, because its work is per cart — a quote prices one specific
     * cart's goods, so a rotated cart is a genuinely different quote, and the
     * convention's fairness argument (one shopper must not exhaust everyone behind the
     * same IP) applies to it unchanged. Request, budget and status come from the map
     * above, so this cannot drift from what the limiter is actually attached to.
     */
    public function test_a_budget_keyed_by_the_cart_gives_another_cart_and_a_cartless_visitor_their_own_budgets(): void
    {
        $limiter = self::LIMITERS[ApiRateLimits::SHIPPING_QUOTE];

        config([$limiter['config'] => 2]);

        // No cart of its own exists for any of these tokens, so the quote is refused
        // (422, an empty cart) whatever the limiter answers — the budget is what is
        // being spent here, not a price.
        [$method, $uri, $body, $insideStatus] = $limiter['inside'];

        $this->withSession(['cart_token' => 'cart-one']);
        $this->send($method, $uri, $body)->assertStatus($insideStatus);
        $this->send($method, $uri, $body)->assertStatus($insideStatus);
        $this->send($method, $uri, $body)->assertStatus(429);

        // The same visitor, a different cart: a genuinely different cart is a
        // genuinely different budget.
        $this->withSession(['cart_token' => 'cart-two']);
        $this->send($method, $uri, $body)->assertStatus($insideStatus);

        // A visitor with no cart at all is the third identity, and has one too.
        $this->flushSession();
        $this->send($method, $uri, $body)->assertStatus($insideStatus);

        // And the first cart, at its limit here, is not at its limit from another IP.
        $this->withServerVariables(['REMOTE_ADDR' => self::OTHER_IP]);
        $this->withSession(['cart_token' => 'cart-one']);
        $this->send($method, $uri, $body)->assertStatus($insideStatus);
    }

    // --- helpers -----------------------------------------------------------------------------------------------------------

    private function send(string $method, string $uri, array $body): TestResponse
    {
        return $method === 'PUT' ? $this->putJson($uri, $body) : $this->postJson($uri, $body);
    }

    /** The limit ApiRateLimits hands the middleware for a bare request. */
    private function limitFor(string $name): Limit
    {
        $limiter = RateLimiter::limiter($name);

        $this->assertNotNull($limiter, "the {$name} limiter is registered under its own name");

        // The same client address the test's own HTTP requests come from, so the key
        // read here is the key the requests below are counted under.
        return $limiter(Request::create('/api', 'GET', [], [], [], ['REMOTE_ADDR' => self::THIS_IP]));
    }

    /**
     * The names the framework's registry actually holds — read from the registry, so a
     * limiter defined anywhere else fails the test above rather than hiding inside it.
     *
     * @return list<string>
     */
    private function registeredLimiterNames(): array
    {
        $registry = app(LimiterRegistry::class);
        $limiters = (new ReflectionClass($registry))->getProperty('limiters');

        return array_keys($limiters->getValue($registry));
    }

    private function storeLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);
    }
}
