<?php

namespace Tests\Feature\Sandbox;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D1's SECOND condition, on the real boot path: the flag is ON and the
 * environment is production, so the routes must still not exist.
 *
 * This is the one test in the sandbox suite that runs its whole method in
 * a production application, because the condition being tested IS
 * "app()->isProduction()". See SandboxTestEnvironment for why APP_ENV and
 * DB_DATABASE are set before the container is built, and why restoring
 * them in tearDown() is mandatory. No RefreshDatabase and no database
 * access here — the point is the router's table, and a test that cannot
 * touch the database cannot damage the wrong one.
 *
 * WHAT THIS PROVES, PRECISELY: with EASYCO_SANDBOX=true, the application
 * boots, config('sandbox.enabled') is genuinely true (asserted, not
 * assumed — otherwise this test could pass for the wrong reason, with the
 * flag simply off), and the routes are still absent because
 * app()->isProduction() is true. That single fact is the entire second
 * half of D1's gate.
 */
final class SandboxRoutesProductionTest extends TestCase
{
    /**
     * PUBLIC, matching Illuminate\Foundation\Testing\TestCase's own
     * (public) signature — narrowing it to protected is a fatal error.
     *
     * @return Application
     */
    public function createApplication()
    {
        SandboxTestEnvironment::enableSandboxFlag();
        SandboxTestEnvironment::forceProductionEnvironment();

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SandboxTestEnvironment::restoreProductionEnvironment();
        SandboxTestEnvironment::restoreSandboxFlag();
    }

    public function test_the_app_really_is_production_and_the_flag_really_is_on(): void
    {
        $this->assertTrue($this->app->isProduction());
        $this->assertTrue((bool) config('sandbox.enabled'));
    }

    public function test_the_sandbox_routes_are_not_registered_in_production_even_with_the_flag_on(): void
    {
        $this->assertFalse(Route::has('sandbox.index'));
        $this->assertFalse(Route::has('sandbox.products.show'));
        $this->assertFalse(Route::has('sandbox.cart'));
        $this->assertFalse(Route::has('sandbox.checkout'));
        $this->assertFalse(Route::has('sandbox.order-placed'));

        $this->get('/_sandbox')->assertNotFound();
        $this->get('/_sandbox/products/1')->assertNotFound();
        $this->get('/_sandbox/cart')->assertNotFound();
        $this->get('/_sandbox/checkout')->assertNotFound();
        $this->get('/_sandbox/order-placed')->assertNotFound();
    }
}
