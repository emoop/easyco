<?php

namespace Tests\Feature\Sandbox;

use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * The CSRF half of "the sandbox's pages must not weaken the real API": a stateful
 * write to the storefront API without the CSRF header is REJECTED.
 *
 * WHY THIS TEST NEEDS A PRODUCTION APPLICATION, AND WHY THAT IS THE ONLY WAY TO
 * WRITE IT: Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::handle() reads
 *
 *     $this->isReading($request) || $this->runningUnitTests() || ...
 *
 * and runningUnitTests() is true whenever the application's environment is
 * 'testing' — i.e. for every other test in this suite. CSRF is therefore switched
 * off for the whole PHPUnit run, by the framework, and a "without the header it is
 * rejected" assertion written in the ordinary testing environment could never
 * pass, no matter how broken or fixed the middleware was. Booting the application
 * with APP_ENV=production makes runningUnitTests() false and the middleware real,
 * which is exactly the situation a deployed storefront is in.
 *
 * WHAT THAT COSTS, AND HOW IT IS PAID FOR: with APP_ENV=production Laravel stops
 * loading .env.testing and would fall back to .env — the DEV database. See
 * SandboxTestEnvironment::forceProductionEnvironment(): it reads the test database
 * name while .env.testing is still in play and pins it into DB_DATABASE before the
 * container is built, precisely so that this test cannot reach development data.
 * This class additionally touches no tables at all: every request below fails (or
 * passes) at the middleware or at VALIDATION, before any query runs — which is
 * also why no RefreshDatabase is used here.
 *
 * The sandbox flag is deliberately NOT enabled in this class: the sandbox's own
 * routes must not exist in production (SandboxRoutesProductionTest), and what is
 * under test here is the API's CSRF contract, not the sandbox's routes.
 */
final class SandboxCartApiCsrfTest extends TestCase
{
    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own (public)
     * signature — narrowing it to protected is a fatal error.
     *
     * @return Application
     */
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

    public function test_the_application_really_is_production_and_so_really_has_csrf_active(): void
    {
        $this->assertTrue($this->app->isProduction());
        $this->assertFalse(
            $this->app->runningUnitTests(),
            'if this were true, VerifyCsrfToken would disable itself and the assertion below would prove nothing'
        );
    }

    public function test_a_stateful_api_write_without_the_csrf_header_is_rejected(): void
    {
        // The Referer is what makes the request 'stateful' (Sanctum's
        // EnsureFrontendRequestsAreStateful), and only a stateful request runs the
        // session + CSRF pipeline at all — the same two facts a browser's fetch()
        // from a storefront page satisfies, and the reason the sandbox's own pages
        // send X-XSRF-TOKEN.
        $response = $this->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/checkout', []);

        $response->assertStatus(419);
    }

    public function test_the_same_write_gets_past_the_csrf_check_when_the_token_is_sent(): void
    {
        $this->withHeader('Referer', 'http://localhost/');

        // Starting the session the same way a browser's first page load would.
        $this->getJson('/api/cart')->assertOk();

        $token = (string) $this->app['session.store']->token();

        $response = $this->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/checkout', []);

        // 422, not 419: the request passed the CSRF check and was rejected by the
        // checkout's own validation — which is the difference this test is about.
        $response->assertStatus(422);
    }
}
