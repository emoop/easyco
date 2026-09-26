<?php

namespace Tests\Feature\Sandbox;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * D1's OFF branch, asserted on both halves: the URLs 404 (the observable
 * behaviour) AND the named routes are absent from the router's table (the
 * stronger fact — D1's own words are "disabled -> the routes do not
 * exist", not "disabled -> the routes refuse"). A future refactor that
 * registered the routes unconditionally and only checked the flag inside
 * the controller would still make the URLs 404 while silently breaking
 * the requirement, and the route-table assertion is the one that catches
 * that.
 *
 * NO RefreshDatabase, AND NO FLAG MANIPULATION, ON PURPOSE: the default
 * really is off (config/sandbox.php's own env('EASYCO_SANDBOX', false),
 * and neither .env nor .env.testing sets it) — this class asserts that
 * default rather than assuming it. Nothing here touches the database,
 * which also means a regression in this area cannot damage any data.
 */
final class SandboxRoutesDisabledTest extends TestCase
{
    public function test_the_flag_defaults_to_off(): void
    {
        $this->assertFalse((bool) config('sandbox.enabled'));
    }

    public function test_the_sandbox_routes_are_not_registered_at_all(): void
    {
        $this->assertFalse(Route::has('sandbox.index'));
        $this->assertFalse(Route::has('sandbox.products.show'));
        $this->assertFalse(Route::has('sandbox.cart'));
        $this->assertFalse(Route::has('sandbox.checkout'));
        $this->assertFalse(Route::has('sandbox.order-placed'));
    }

    public function test_every_sandbox_url_returns_404(): void
    {
        $this->get('/_sandbox')->assertNotFound();
        $this->get('/_sandbox/products/1')->assertNotFound();
        $this->get('/_sandbox/cart')->assertNotFound();
        $this->get('/_sandbox/checkout')->assertNotFound();
        $this->get('/_sandbox/order-placed')->assertNotFound();

        // The guessed-id path a stage-2 confirmation page must NEVER have: with the
        // flag off it 404s because the whole group is absent.
        $this->get('/_sandbox/order-placed/1')->assertNotFound();
    }
}
