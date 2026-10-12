<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * The guest cart cookie (App\Http\Middleware\PersistGuestCartToken; cart-domain-design.md §10, "Guest cart persistence").
 *
 * A "browser" here is just the cookies we hand back: the test client keeps none of its own, and the in-process session store
 * is flushed to stand for the two hours running out.
 */
final class CartGuestCookiePersistenceTest extends TestCase
{
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
        // JSON requests send no cookies unless this is on (MakesHttpRequests::prepareCookiesForJsonRequest).
        $this->withCredentials();
    }

    /**
     * Cookie::queue() is a process-wide jar that only a new application (a real request) empties; AddQueuedCookiesToResponse
     * never does. Without this every response in a test would also carry the cookies queued by the requests before it.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['cookie']->flushQueuedCookies();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------

    private function pricedVariation(int $stock = 10): string
    {
        self::$counter++;
        $n = (string) self::$counter;

        $product = Product::createSimple("Cookie product {$n}", "COOKIE-{$n}", "cookie-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    /** A browser that holds a cart cookie holds the marker too (the server sets them together). */
    private function newBrowser(array $cookies = [], bool $withMarker = true): static
    {
        if ($withMarker && isset($cookies['easyco_cart']) && ! isset($cookies['easyco_has_cart'])) {
            $cookies['easyco_has_cart'] = '1';
        }

        $this->unencryptedCookies = [];
        $this->app['session.store']->flush();
        $this->app['session.store']->setId(Str::random(40));

        foreach ($cookies as $name => $value) {
            $this->withUnencryptedCookie($name, $value);
        }

        return $this;
    }

    private function cartCookie(TestResponse $response): ?Cookie
    {
        return $response->getCookie('easyco_cart', false);
    }

    private function markerCookie(TestResponse $response): ?Cookie
    {
        return $response->getCookie('easyco_has_cart', false);
    }

    private function addLine(string $variationId, int $quantity = 1): TestResponse
    {
        return $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity]);
    }

    private function token(string $cartId): string
    {
        return (string) CartModel::findOrFail($cartId)->session_token;
    }

    // ---- the cookie ------------------------------------------------------------------------------------------

    public function test_the_first_write_sets_an_encrypted_httponly_lax_cookie_that_never_outlives_the_cart_row(): void
    {
        $response = $this->addLine($this->pricedVariation())->assertStatus(201);
        $token = $this->token($response->json('cart_id'));

        $cookie = $this->cartCookie($response);

        $this->assertNotNull($cookie, 'the cart cookie is set on the first write');
        $this->assertNotSame($token, $cookie->getValue(), 'encrypted, not the plain token');
        $this->assertStringNotContainsString($token, $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame(config('session.secure'), $cookie->isSecure() ?: config('session.secure'));

        $expires = Carbon::createFromTimestamp($cookie->getExpiresTime());
        $rowExpires = Carbon::parse(CartModel::findOrFail($response->json('cart_id'))->expires_at);

        $this->assertTrue($expires->lessThanOrEqualTo($rowExpires), 'the cookie never outlives the cart row');
        $this->assertTrue($expires->lessThanOrEqualTo(Carbon::now()->addDays(7)->addMinute()), 'and not longer than cart.guest_cookie_days (7)');
        $this->assertTrue($expires->greaterThan(Carbon::now()->addDays(6)), 'about seven days');
    }

    public function test_the_lifetime_is_capped_by_the_cart_row_when_the_setting_is_longer(): void
    {
        config(['cart.guest_cookie_days' => 30]);

        $response = $this->addLine($this->pricedVariation())->assertStatus(201);
        $cookie = $this->cartCookie($response);

        $rowExpires = Carbon::parse(CartModel::findOrFail($response->json('cart_id'))->expires_at);

        $this->assertLessThanOrEqual($rowExpires->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_a_plain_read_with_the_same_cookie_does_not_refresh_it_and_a_write_does(): void
    {
        $first = $this->addLine($this->pricedVariation());
        $raw = $this->cartCookie($first)->getValue();

        $read = $this->newBrowser(['easyco_cart' => $raw])->getJson('/api/cart')->assertOk();
        $this->assertNull($this->cartCookie($read), 'a read never extends the cart, so it does not extend the cookie');

        $write = $this->newBrowser(['easyco_cart' => $raw]);
        $write = $this->addLine($this->pricedVariation())->assertStatus(201);
        $this->assertNotNull($this->cartCookie($write), 'a write refreshes it');
    }

    // ---- the session ran out -------------------------------------------------------------------------------

    public function test_after_the_session_expires_the_cookie_brings_the_same_cart_back(): void
    {
        $variationId = $this->pricedVariation();
        $first = $this->addLine($variationId, 2)->assertStatus(201);
        $cartId = $first->json('cart_id');
        $raw = $this->cartCookie($first)->getValue();

        // A different session (the old one is gone), the same cookie.
        $cart = $this->newBrowser(['easyco_cart' => $raw])->getJson('/api/cart')->assertOk();

        $cart->assertJsonPath('cart_id', $cartId);
        $cart->assertJsonPath('lines.0.variation_id', $variationId);
        $cart->assertJsonPath('lines.0.quantity', 2);

        // ... and a write continues the SAME cart.
        $more = $this->newBrowser(['easyco_cart' => $raw]);
        $more = $this->addLine($variationId, 1)->assertStatus(201);
        $more->assertJsonPath('cart_id', $cartId);
        $more->assertJsonPath('lines.0.quantity', 3);
        $this->assertSame(1, CartModel::count());
    }

    public function test_without_the_cookie_the_expired_session_has_no_cart(): void
    {
        $this->addLine($this->pricedVariation())->assertStatus(201);

        $this->newBrowser()->getJson('/api/cart')->assertOk()->assertJsonPath('cart_id', null)->assertJsonCount(0, 'lines');
    }

    public function test_a_tampered_or_plain_text_cookie_is_ignored(): void
    {
        $first = $this->addLine($this->pricedVariation())->assertStatus(201);
        $token = $this->token($first->json('cart_id'));
        $raw = $this->cartCookie($first)->getValue();

        $tampered = substr($raw, 0, -4).(substr($raw, -4) === 'AAAA' ? 'BBBB' : 'AAAA');

        foreach (['tampered' => $tampered, 'the plain token' => $token, 'garbage' => 'x', 'another uuid, plain' => (string) Str::uuid()] as $label => $value) {
            $response = $this->newBrowser(['easyco_cart' => $value])->getJson('/api/cart')->assertOk();

            $response->assertJsonPath('cart_id', null);
            $this->assertSame([], $response->json('lines'), $label);
        }

        // ... and a write under a tampered cookie starts a NEW server-generated cart, never the cookie's token.
        $new = $this->newBrowser(['easyco_cart' => $tampered]);
        $new = $this->addLine($this->pricedVariation())->assertStatus(201);
        $this->assertNotSame($token, $this->token($new->json('cart_id')));
    }

    public function test_an_encrypted_cookie_that_is_not_a_uuid_is_ignored(): void
    {
        $encrypted = app('encrypter')->encrypt(\Illuminate\Cookie\CookieValuePrefix::create('easyco_cart', app('encrypter')->getKey()).'not-a-uuid', false);

        $this->newBrowser(['easyco_cart' => $encrypted])->getJson('/api/cart')->assertOk()->assertJsonPath('cart_id', null);
        $this->assertNull(session('cart_token'));
    }

    public function test_a_token_sent_in_the_body_a_header_or_the_query_is_never_read(): void
    {
        $first = $this->addLine($this->pricedVariation())->assertStatus(201);
        $token = $this->token($first->json('cart_id'));

        $this->newBrowser();
        $response = $this->withHeaders(['X-Cart-Token' => $token, 'Cart-Token' => $token])
            ->getJson('/api/cart?cart_token='.$token.'&session_token='.$token)
            ->assertOk();
        $response->assertJsonPath('cart_id', null);

        $this->newBrowser();
        $write = $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1, 'cart_token' => $token, 'session_token' => $token])->assertStatus(201);
        $this->assertNotSame($token, $this->token($write->json('cart_id')), 'the server generated its own token');
    }

    // ---- login: the merge consumes the token, the cookie goes ----------------------------------------------

    private function registeredAccount(string $email = 'cookie@example.com', string $password = 'password123'): void
    {
        $account = Account::register($email, app(PasswordHasher::class)->hash($password));
        app(AccountRepository::class)->save($account);
    }

    public function test_after_login_the_guest_cart_merges_into_the_account_and_the_cookie_is_deleted(): void
    {
        $this->registeredAccount();
        $variationId = $this->pricedVariation();
        $first = $this->addLine($variationId, 2)->assertStatus(201);
        $raw = $this->cartCookie($first)->getValue();

        // The session has expired by the time they log in: the cookie alone carries the guest cart to the login.
        $login = $this->newBrowser(['easyco_cart' => $raw])->postJson('/api/account/login', ['email' => 'cookie@example.com', 'password' => 'password123'])->assertOk();

        $deleted = $this->cartCookie($login);
        $this->assertNotNull($deleted, 'the login response deletes the cookie');
        $this->assertTrue($deleted->getExpiresTime() < time(), 'an expired cookie is a deletion');

        $cart = $this->getJson('/api/cart')->assertOk();
        $cart->assertJsonPath('lines.0.variation_id', $variationId);
        $cart->assertJsonPath('lines.0.quantity', 2);
        $this->assertNull(CartModel::whereNotNull('session_token')->first(), 'the guest row is gone');
    }

    public function test_a_logged_in_customer_never_receives_the_cookie(): void
    {
        $this->registeredAccount();
        $this->postJson('/api/account/login', ['email' => 'cookie@example.com', 'password' => 'password123'])->assertOk();

        $write = $this->addLine($this->pricedVariation())->assertStatus(201);
        $this->assertNull($this->cartCookie($write));
        $this->assertNull($this->cartCookie($this->getJson('/api/cart')->assertOk()));
        $this->assertNull($this->cartCookie($this->patchJson('/api/cart/lines/'.$write->json('lines.0.variation_id'), ['quantity' => 2])->assertOk()));
    }

    public function test_a_logged_in_request_never_queues_the_cookie_even_if_a_live_guest_token_is_somehow_in_its_session(): void
    {
        $guest = $this->addLine($this->pricedVariation())->assertStatus(201);
        $liveToken = $this->token($guest->json('cart_id'));

        $this->registeredAccount();
        $account = \EasyCo\Account\Persistence\Eloquent\AccountModel::where('email', 'cookie@example.com')->firstOrFail();
        $this->newBrowser();
        $this->actingAs($account, 'customer');
        $this->app['session.store']->put('cart_token', $liveToken);

        $this->assertNull($this->cartCookie($this->addLine($this->pricedVariation())->assertStatus(201)));
        $this->assertNull($this->cartCookie($this->getJson('/api/cart')->assertOk()));
    }

    public function test_a_logged_in_request_ignores_a_cookie_it_carries_and_never_rewrites_it(): void
    {
        $guest = $this->addLine($this->pricedVariation())->assertStatus(201);
        $raw = $this->cartCookie($guest)->getValue();
        $guestCartId = $guest->json('cart_id');

        // A different browser: log in WITHOUT the cookie (so nothing merges), build the account's own cart.
        $this->registeredAccount();
        $this->newBrowser();
        $this->postJson('/api/account/login', ['email' => 'cookie@example.com', 'password' => 'password123'])->assertOk();
        $accountCartId = $this->addLine($this->pricedVariation())->assertStatus(201)->json('cart_id');
        $this->assertNotSame($guestCartId, $accountCartId);

        // Logged in AND carrying the old guest cookie: the cart is the account's, and the cookie is neither used nor rewritten.
        $cart = $this->withUnencryptedCookie('easyco_cart', $raw)->getJson('/api/cart')->assertOk();
        $cart->assertJsonPath('cart_id', $accountCartId);
        $cookie = $this->cartCookie($cart);
        $this->assertTrue($cookie === null || $cookie->getExpiresTime() < time(), 'at most a deletion of the stale cookie, never a value');
        $this->assertNotNull(CartModel::find($guestCartId), 'the guest cart is untouched');
    }

    // ---- an order (the claim) ----------------------------------------------------------------------------

    private function checkoutPayload(string $cartId): array
    {
        return [
            'cart_id' => $cartId,
            'email' => 'guest@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ];
    }

    public function test_after_an_order_the_next_cart_reuses_the_same_token_and_a_late_replay_still_answers(): void
    {
        $first = $this->addLine($this->pricedVariation(), 1)->assertStatus(201);
        $cartId = $first->json('cart_id');
        $token = $this->token($cartId);
        $raw = $this->cartCookie($first)->getValue();

        // Order placed from a NEW session that only has the cookie.
        $order = $this->newBrowser(['easyco_cart' => $raw])->postJson('/api/checkout', $this->checkoutPayload($cartId))->assertStatus(201);
        $order->assertJsonPath('already_placed', false);
        $this->assertNull($this->cartCookie($order), 'a claimed cart has no live row: the cookie is left as it is');

        // The next cart follows §14: a NEW cart row under the SAME token.
        $next = $this->newBrowser(['easyco_cart' => $raw]);
        $next = $this->addLine($this->pricedVariation(), 1)->assertStatus(201);
        $this->assertNotSame($cartId, $next->json('cart_id'));
        $this->assertSame($token, $this->token($next->json('cart_id')));
        $this->assertNotNull($this->cartCookie($next));

        // A replay of the first checkout (from yet another session holding the cookie) is still answered.
        $replay = $this->newBrowser(['easyco_cart' => $raw])->postJson('/api/checkout', $this->checkoutPayload($cartId))->assertStatus(201);
        $replay->assertJsonPath('already_placed', true);
        $this->assertSame($order->json('order.id'), $replay->json('order.id'));
    }

    // ---- scope -----------------------------------------------------------------------------------------------

    public function test_the_middleware_is_on_exactly_the_routes_that_touch_the_guest_cart_token(): void
    {
        $expected = [
            'GET api/cart', 'POST api/cart/lines', 'PATCH api/cart/lines/{variationId}', 'DELETE api/cart/lines/{variationId}',
            'PUT api/cart/promotion', 'DELETE api/cart/promotion', 'POST api/checkout', 'POST api/shipping/quote',
            'POST api/account/login', 'POST api/account/register',
        ];
        $carrying = [];

        foreach (app('router')->getRoutes() as $route) {
            if (array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'cart.cookie'))) {
                foreach ($route->methods() as $method) {
                    if ($method !== 'HEAD') {
                        $carrying[] = $method.' '.$route->uri();
                    }
                }
            }
        }

        sort($expected);
        sort($carrying);
        $this->assertSame($expected, $carrying);
    }

    public function test_the_cookie_is_listed_before_the_throttle_whose_key_reads_the_token(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $at = array_key_first(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'cart.cookie')));

            if ($at !== null) {
                foreach ($middleware as $i => $name) {
                    if (is_string($name) && str_starts_with($name, 'throttle:')) {
                        $this->assertLessThan($i, $at, $route->uri());
                    }
                }
            }
        }
    }

    public function test_a_plain_read_with_a_matching_cookie_does_not_look_up_the_cart_row_for_the_cookie(): void
    {
        $first = $this->addLine($this->pricedVariation())->assertStatus(201);
        $raw = $this->cartCookie($first)->getValue();

        $this->newBrowser(['easyco_cart' => $raw]);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $this->getJson('/api/cart')->assertOk();

        $this->assertSame([], array_values(array_filter($sql, fn (string $q) => str_contains($q, 'select `expires_at` from `carts`'))), 'a read does not look up the row for the cookie');
    }

    public function test_the_modes_are_write_for_cart_changes_read_for_the_cart_and_passive_for_the_rest(): void
    {
        $modeOf = function (string $method, string $uri): ?string {
            foreach (app('router')->getRoutes() as $route) {
                if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                    foreach ($route->gatherMiddleware() as $m) {
                        if (is_string($m) && str_starts_with($m, 'cart.cookie:')) {
                            return substr($m, 12);
                        }
                    }
                }
            }

            return null;
        };

        $this->assertSame('read', $modeOf('GET', 'api/cart'));
        foreach ([['POST', 'api/cart/lines'], ['PATCH', 'api/cart/lines/{variationId}'], ['DELETE', 'api/cart/lines/{variationId}'], ['PUT', 'api/cart/promotion'], ['DELETE', 'api/cart/promotion']] as [$method, $uri]) {
            $this->assertSame('write', $modeOf($method, $uri), "{$method} {$uri}");
        }
        foreach ([['POST', 'api/checkout'], ['POST', 'api/shipping/quote'], ['POST', 'api/account/login'], ['POST', 'api/account/register']] as [$method, $uri]) {
            $this->assertSame('passive', $modeOf($method, $uri), "{$method} {$uri}");
        }
    }

    public function test_checkout_adds_no_query_for_the_cookie(): void
    {
        $first = $this->addLine($this->pricedVariation())->assertStatus(201);
        $raw = $this->cartCookie($first)->getValue();

        $this->newBrowser(['easyco_cart' => $raw]);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $this->postJson('/api/checkout', $this->checkoutPayload($first->json('cart_id')))->assertStatus(201);

        $this->assertSame([], array_values(array_filter($sql, fn (string $q) => str_contains($q, 'select `expires_at` from `carts`'))));
    }

    // ---- the marker cookie ------------------------------------------------------------------------------------

    public function test_a_write_sets_the_marker_readable_unencrypted_and_with_the_same_life_as_the_cart_cookie(): void
    {
        $response = $this->addLine($this->pricedVariation())->assertStatus(201);

        $cart = $this->cartCookie($response);
        $marker = $this->markerCookie($response);

        $this->assertNotNull($marker);
        $this->assertSame('1', $marker->getValue(), 'plain "1": no token, not encrypted');
        $this->assertFalse($marker->isHttpOnly(), 'readable by the script, on purpose');
        $this->assertSame('lax', $marker->getSameSite());
        $this->assertSame('/', $marker->getPath());
        $this->assertSame($cart->getDomain(), $marker->getDomain());
        $this->assertSame($cart->isSecure(), $marker->isSecure());
        $this->assertSame($cart->getExpiresTime(), $marker->getExpiresTime(), 'one lifetime for both');
        $this->assertTrue($cart->isHttpOnly());
        $this->assertStringNotContainsString($this->token($response->json('cart_id')), (string) json_encode($marker->getValue()));
    }

    public function test_the_marker_is_listed_in_the_encrypt_cookies_exceptions(): void
    {
        $encrypt = new \Illuminate\Cookie\Middleware\EncryptCookies($this->app['encrypter']);

        $this->assertTrue($encrypt->isDisabled('easyco_has_cart'), 'the marker is plain text on purpose');
        $this->assertFalse($encrypt->isDisabled('easyco_cart'), 'the token cookie is encrypted');
    }

    public function test_the_marker_follows_the_cart_cookie_in_every_mode(): void
    {
        $variationId = $this->pricedVariation();
        $first = $this->addLine($variationId)->assertStatus(201);
        $raw = $this->cartCookie($first)->getValue();

        // write: both refreshed together
        $write = $this->newBrowser(['easyco_cart' => $raw]);
        $write = $this->addLine($variationId)->assertStatus(201);
        $this->assertNotNull($this->cartCookie($write));
        $this->assertNotNull($this->markerCookie($write));
        $this->assertSame($this->cartCookie($write)->getExpiresTime(), $this->markerCookie($write)->getExpiresTime());

        // read with both: neither is touched
        $read = $this->newBrowser(['easyco_cart' => $raw])->getJson('/api/cart')->assertOk();
        $this->assertNull($this->cartCookie($read));
        $this->assertNull($this->markerCookie($read));

        // read with the token cookie but WITHOUT the marker (a browser from before the marker existed): both are queued
        $legacy = $this->newBrowser(['easyco_cart' => $raw], withMarker: false)->getJson('/api/cart')->assertOk();
        $this->assertNotNull($this->cartCookie($legacy));
        $this->assertNotNull($this->markerCookie($legacy));

        // read with neither cookie but a live session: both are queued (the same rule as for the token cookie)
        $this->newBrowser();
        $this->app['session.store']->put('cart_token', $this->token($first->json('cart_id')));
        $bare = $this->getJson('/api/cart')->assertOk();
        $this->assertNotNull($this->cartCookie($bare));
        $this->assertNotNull($this->markerCookie($bare));

        // passive (shipping quote): neither is ever queued
        $passive = $this->newBrowser(['easyco_cart' => $raw])->postJson('/api/shipping/quote', []);
        $this->assertNull($this->cartCookie($passive));
        $this->assertNull($this->markerCookie($passive));
    }

    public function test_the_marker_is_deleted_with_the_cart_cookie_when_the_login_merge_consumes_the_token(): void
    {
        $this->registeredAccount();
        $first = $this->addLine($this->pricedVariation(), 2)->assertStatus(201);
        $raw = $this->cartCookie($first)->getValue();

        $login = $this->newBrowser(['easyco_cart' => $raw])->postJson('/api/account/login', ['email' => 'cookie@example.com', 'password' => 'password123'])->assertOk();

        foreach ([$this->cartCookie($login), $this->markerCookie($login)] as $cookie) {
            $this->assertNotNull($cookie, 'both cookies are deleted by the login');
            $this->assertTrue($cookie->getExpiresTime() < time());
        }
    }

    public function test_a_logged_in_customer_gets_neither_cookie_and_a_stale_pair_is_only_deleted(): void
    {
        $guest = $this->addLine($this->pricedVariation())->assertStatus(201);
        $raw = $this->cartCookie($guest)->getValue();

        $this->registeredAccount();
        $this->newBrowser();
        $this->postJson('/api/account/login', ['email' => 'cookie@example.com', 'password' => 'password123'])->assertOk();

        $write = $this->addLine($this->pricedVariation())->assertStatus(201);
        $this->assertNull($this->cartCookie($write));
        $this->assertNull($this->markerCookie($write));

        $read = $this->withUnencryptedCookie('easyco_cart', $raw)->withUnencryptedCookie('easyco_has_cart', '1')->getJson('/api/cart')->assertOk();
        foreach ([$this->cartCookie($read), $this->markerCookie($read)] as $cookie) {
            $this->assertTrue($cookie === null || $cookie->getExpiresTime() < time(), 'at most a deletion, never a value');
        }
    }

    public function test_a_request_with_only_the_marker_restores_nothing_and_the_stale_marker_is_deleted(): void
    {
        $this->addLine($this->pricedVariation())->assertStatus(201);

        $response = $this->newBrowser(['easyco_has_cart' => '1'], withMarker: false)->getJson('/api/cart')->assertOk();

        $response->assertJsonPath('cart_id', null);
        $this->assertSame([], $response->json('lines'));
        $marker = $this->markerCookie($response);
        $this->assertTrue($marker !== null && $marker->getExpiresTime() < time(), 'a marker without a cart cookie is stale: deleted');
    }

    public function test_the_marker_alone_never_makes_the_server_accept_a_token(): void
    {
        $first = $this->addLine($this->pricedVariation())->assertStatus(201);
        $token = $this->token($first->json('cart_id'));

        // The marker carrying a token-looking value changes nothing: the server never reads it.
        $response = $this->newBrowser(['easyco_has_cart' => $token], withMarker: false)->getJson('/api/cart')->assertOk();

        $response->assertJsonPath('cart_id', null);
    }

    public function test_the_storefront_pages_and_the_cart_page_still_set_neither_cookie(): void
    {
        foreach (['/', '/cart', '/product/missing', '/product-category/missing'] as $url) {
            $response = $this->newBrowser(['easyco_cart' => 'x', 'easyco_has_cart' => '1'])->get($url);

            $this->assertNull($this->cartCookie($response), $url);
            $this->assertNull($this->markerCookie($response), $url);
            $this->assertFalse($response->headers->has('Set-Cookie'), $url);
        }
    }
}
