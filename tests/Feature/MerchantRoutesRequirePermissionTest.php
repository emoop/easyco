<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A real audit of the route table (Route::getRoutes(), not the source
 * text of routes/api.php) — staff-access-domain-design.md §5 rule 1 /
 * §11's own framing of this as the single most important test in the
 * document. Every route belonging to this project's API surface
 * (App\Http\Controllers\Api\*) is either an explicitly-listed
 * storefront/customer route, or must carry BOTH auth:staff and a
 * non-empty staff.can:* permission. There is no third category.
 *
 * This is deliberately a closed classification: a brand-new route that
 * is neither added to STOREFRONT_ROUTES below nor given the merchant
 * middleware pair fails this test either way. That is the intended
 * behavior, not a gap to fix — staff-access-domain-design.md's whole
 * "deny by default... gets noticed" philosophy (§5) means a new route
 * forces someone to make the classification decision explicitly,
 * rather than silently inheriting whatever the surrounding code did.
 *
 * NOTE: array_any() (PHP 8.4) is not available — this project targets
 * PHP 8.3 (composer.json) — so the permission-declared check below uses
 * a plain foreach instead.
 */
class MerchantRoutesRequirePermissionTest extends TestCase
{
    /**
     * "METHOD uri" exactly as real Route::uri() reports it — verified
     * for real via Route::getRoutes()/gatherMiddleware() before writing
     * this list: no leading 'api/' is missing (it IS present), params
     * use the plain '{name}' placeholder syntax, and gatherMiddleware()
     * returns the alias strings ('auth:staff', 'staff.can:x') rather
     * than resolved FQCNs — confirmed directly, not assumed from
     * `route:list`'s display output, which expands aliases to class
     * names and would have been misleading here.
     */
    private const STOREFRONT_ROUTES = [
        'POST api/account/register',
        'POST api/account/login',
        'POST api/account/logout',
        'GET api/account/me',
        'GET api/addresses',
        'POST api/addresses',
        'PUT api/addresses/{addressId}',
        'GET api/cart',
        'POST api/cart/lines',
        'PATCH api/cart/lines/{variationId}',
        'DELETE api/cart/lines/{variationId}',
        'PUT api/cart/promotion',
        'DELETE api/cart/promotion',
        'POST api/checkout',
    ];

    public function test_every_merchant_route_requires_auth_staff_and_a_declared_permission(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Api\\')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = "{$method} {$route->uri()}";

                if (in_array($key, self::STOREFRONT_ROUTES, true)) {
                    continue;
                }

                $middleware = $route->gatherMiddleware();
                $hasAuthStaff = in_array('auth:staff', $middleware, true);

                $hasDeclaredPermission = false;
                foreach ($middleware as $m) {
                    if (str_starts_with($m, 'staff.can:') && $m !== 'staff.can:') {
                        $hasDeclaredPermission = true;
                        break;
                    }
                }

                if (! $hasAuthStaff || ! $hasDeclaredPermission) {
                    $failures[] = $key;
                }
            }
        }

        $this->assertEmpty(
            $failures,
            'These routes are missing auth:staff and/or a declared staff.can:* permission: '
                .implode(', ', $failures)
        );
    }

    /**
     * The other direction of drift: an entry in STOREFRONT_ROUTES that
     * no longer matches any real registered route (renamed, removed)
     * silently stops being excluded from anything and just vanishes —
     * assert every listed entry still matches something real.
     */
    public function test_every_storefront_allowlist_entry_still_matches_a_real_route(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $registered[] = "{$method} {$route->uri()}";
            }
        }

        foreach (self::STOREFRONT_ROUTES as $expected) {
            $this->assertContains($expected, $registered, "Storefront allowlist entry \"{$expected}\" no longer matches any registered route.");
        }
    }

    /**
     * Guards against this whole audit trivially "passing" because
     * Route::getRoutes() came back empty or barely populated in the
     * test environment — a real, substantive count confirms the audit
     * actually iterated something.
     */
    public function test_the_route_table_actually_contains_the_expected_number_of_api_routes(): void
    {
        $apiRoutes = array_filter(
            iterator_to_array(Route::getRoutes()),
            static fn ($route) => str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\Api\\')
        );

        $this->assertGreaterThanOrEqual(30, count($apiRoutes), 'Expected at least 30 API routes to exist — the route table looks suspiciously small; confirm this test is actually seeing the real route list.');
    }
}
