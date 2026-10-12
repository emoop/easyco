<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Sandbox\SandboxTestEnvironment;
use Tests\TestCase;

/**
 * The guest add-to-cart flow the storefront script follows, with CSRF REALLY active.
 *
 * Laravel's CSRF middleware disables itself under PHPUnit (`runningUnitTests()`), which would make a "419" assertion
 * prove nothing. So this class boots the application as `production` (the same device SandboxCartApiCsrfTest uses,
 * pinned to the private test database) and uses DatabaseTransactions rather than RefreshDatabase (no `migrate:fresh` in
 * production mode; the private database is already migrated).
 *
 * Verified here, not assumed: GET /sanctum/csrf-cookie from a storefront request (Referer on the stateful list) sets the
 * XSRF-TOKEN cookie; a write with it works; the same write without it is a 419; GET /api/cart shows the line.
 */
class StorefrontCartCsrfFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use DatabaseTransactions;

    public function createApplication()
    {
        SandboxTestEnvironment::forceProductionEnvironment();

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SandboxTestEnvironment::restoreProductionEnvironment();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricing();
        $this->makeProduct(1, ['slug' => 'flow', 'price' => '10.00', 'stock' => 5]);
    }

    /** @return array{xsrf: string, session: string, sessionName: string} the cookies the browser would now hold */
    private function csrfCookie(): array
    {
        // A brand-new browser: the test client would otherwise keep sending the cookies of the previous "browser".
        // (and the shared in-process session Store would otherwise carry the previous visitor's attributes over: a
        // test-process artefact, every real request builds its own.)
        $this->unencryptedCookies = [];
        $this->app['session.store']->flush();

        $response = $this->withHeader('Referer', 'http://localhost/')->get('/sanctum/csrf-cookie');

        $response->assertNoContent();

        $xsrf = $response->getCookie('XSRF-TOKEN', false);
        $sessionName = (string) config('session.cookie');
        $session = $response->getCookie($sessionName, false);

        $this->assertNotNull($xsrf, 'GET /sanctum/csrf-cookie must set XSRF-TOKEN for a request from the storefront');
        $this->assertNotNull($session, 'and the session cookie it belongs to');

        return ['xsrf' => urldecode($xsrf->getValue()), 'session' => $session->getValue(), 'sessionName' => $sessionName];
    }

    private function asBrowser(array $cookies): static
    {
        return $this->withHeader('Referer', 'http://localhost/')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withUnencryptedCookie($cookies['sessionName'], $cookies['session'])
            ->withUnencryptedCookie('XSRF-TOKEN', $cookies['xsrf']);
    }

    public function test_the_application_really_is_production_so_csrf_is_really_active(): void
    {
        $this->assertTrue($this->app->isProduction());
        $this->assertFalse($this->app->runningUnitTests());
    }

    public function test_the_storefront_pages_set_no_cookie_so_the_first_write_needs_the_csrf_cookie_request(): void
    {
        $this->get('/product/flow')->assertOk()->assertCookieMissing('XSRF-TOKEN')->assertCookieMissing((string) config('session.cookie'));
    }

    public function test_a_write_without_the_csrf_header_is_419(): void
    {
        $cookies = $this->csrfCookie();

        $this->asBrowser($cookies)
            ->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1])
            ->assertStatus(419);
    }

    public function test_the_full_guest_flow_csrf_cookie_then_add_then_read_the_cart(): void
    {
        $cookies = $this->csrfCookie();

        $this->asBrowser($cookies)
            ->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 2], ['X-XSRF-TOKEN' => $cookies['xsrf']])
            ->assertStatus(201)
            ->assertJsonPath('lines.0.variation_id', '101')
            ->assertJsonPath('lines.0.quantity', 2);

        $cart = $this->asBrowser($cookies)->getJson('/api/cart')->assertOk();

        $cart->assertJsonCount(1, 'lines');
        $cart->assertJsonPath('lines.0.variation_id', '101');
        $cart->assertJsonPath('lines.0.quantity', 2);
        $cart->assertJsonPath('lines.0.price_available', true);
        $cart->assertJsonPath('subtotal.minor', 2000);
    }

    public function test_quantity_change_and_removal_need_the_header_too(): void
    {
        $cookies = $this->csrfCookie();
        $this->asBrowser($cookies)->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertStatus(201);

        $this->asBrowser($cookies)->patchJson('/api/cart/lines/101', ['quantity' => 3])->assertStatus(419);
        $this->asBrowser($cookies)->deleteJson('/api/cart/lines/101')->assertStatus(419);

        $this->asBrowser($cookies)->patchJson('/api/cart/lines/101', ['quantity' => 3], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertOk()->assertJsonPath('lines.0.quantity', 3);
        $this->asBrowser($cookies)->deleteJson('/api/cart/lines/101', [], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertNoContent();
        $this->asBrowser($cookies)->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'lines');
    }

    public function test_a_client_supplied_cart_token_is_not_accepted(): void
    {
        $cookies = $this->csrfCookie();
        $this->asBrowser($cookies)->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertStatus(201);

        // Another browser (a fresh session) that SENDS a cart token of its own in every way a client could: it sees no cart.
        $other = $this->csrfCookie();
        $this->asBrowser($other)->getJson('/api/cart?cart_token=anything&cart_id=1', ['X-Cart-Token' => 'anything'])->assertOk()->assertJsonCount(0, 'lines');
    }

    public function test_the_promotion_routes_work_with_the_same_flow(): void
    {
        $cookies = $this->csrfCookie();
        $this->asBrowser($cookies)->postJson('/api/cart/lines', ['variation_id' => '101', 'quantity' => 1], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertStatus(201);

        $this->asBrowser($cookies)->putJson('/api/cart/promotion', ['code' => 'NOPE'])->assertStatus(419);
        $this->asBrowser($cookies)->putJson('/api/cart/promotion', ['code' => 'NOPE'], ['X-XSRF-TOKEN' => $cookies['xsrf']])->assertStatus(422);
    }
}
