<?php

namespace Tests\Feature\Security;

use App\Mail\RenderedMailable;
use App\Security\Lockout\AdminLoginLockout;
use EasyCo\Staff\Contracts\PasswordHasher as StaffPasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/** P8d.1: the storefront 404 for every unknown address (api / admin / livewire excepted), the alarm mail cap, SECURITY_MODE in Docker. */
class FallbackAndAlarmCapTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- the fallback -----------------------------------------------------------------------------------------------------------------

    /** ★ an unknown address is the storefront's own 404 on the same URL: no cookie, no xkey, the P8d headers */
    public function test_an_unknown_address_shows_the_storefront_404(): void
    {
        foreach (['/abvgd', '/some/deep/unknown/path', '/abvgd?x=1'] as $url) {
            $this->fresh();
            $response = $this->get($url);

            $response->assertNotFound();
            $this->assertFalse($response->isRedirection(), "{$url}: no redirect");
            $this->assertStringContainsString('class="sf-', $response->getContent(), "{$url}: the storefront markup, not Laravel's grey page");
            $this->assertStringNotContainsString('normalize.css', $response->getContent());
            $this->assertSame([], $response->headers->getCookies(), "{$url}: no Set-Cookie");
            $this->assertFalse($response->headers->has('xkey'));
            $this->assertStringNotContainsString('s-maxage', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
            $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        }
    }

    /** ★ api/ is JSON, admin and livewire are a plain 404 (never the storefront page inside the admin) */
    public function test_api_admin_and_livewire_keep_their_own_404(): void
    {
        $api = $this->get('/api/nope');
        $api->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $this->assertStringContainsString('application/json', (string) $api->headers->get('Content-Type'));

        foreach (['/admin/nope', '/admin', '/livewire-abc/nope'] as $url) {
            $response = $this->get($url);

            if ($response->getStatusCode() === 404) {
                $this->assertStringNotContainsString('class="sf-', $response->getContent(), "{$url}: not the storefront page");
            } else {
                $this->assertContains($response->getStatusCode(), [301, 302, 403], "{$url}: the admin answers itself");
            }
        }

        $this->get('/admin/nope')->assertNotFound();
    }

    public function test_the_fallback_costs_the_pinned_404_queries(): void
    {
        $this->get('/abvgd'); // warm
        $this->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/abvgd')->assertNotFound();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // the 404 of a missing product pins 3 (it also looks the product up); the fallback has nothing to look up: 2
        fwrite(STDERR, "
[query-count] P8d.1 fallback 404: {$count} queries (pinned ceiling 3)
");
        $this->assertLessThanOrEqual(3, $count, 'never more than the pin of the existing 404');
    }

    public function test_no_existing_route_is_caught_by_the_fallback(): void
    {
        foreach (['/', '/robots.txt', '/sitemap.xml', '/info/about', '/product/alpha', '/cart'] as $url) {
            $this->fresh();
            $this->assertNotSame(404, $this->get($url)->getStatusCode(), $url);
        }
    }

    // ---- the alarm cap ----------------------------------------------------------------------------------------------------------------

    private function staff(string $email): void
    {
        $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));
            $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');
        }

        app(StaffRepository::class)->save(Staff::create($email, app(StaffPasswordHasher::class)->hash('x-password-1'), 'Staff', $role));
    }

    private function twentyFailures(string $email, string $ipBase): void
    {
        for ($i = 1; $i <= 20; $i++) {
            app(AdminLoginLockout::class)->recordFailure($email, "{$ipBase}.{$i}");
        }
    }

    /** ★ a NON-existing email reaching the threshold: no mail, one warning */
    public function test_an_unknown_email_is_only_logged(): void
    {
        Mail::fake();
        Log::spy();

        $this->twentyFailures('nobody@example.com', '10.1.0');

        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []): bool => str_contains($m, 'Many failed admin logins') && $c['mailed'] === false)->once();
    }

    public function test_an_existing_staff_email_gets_one_mail(): void
    {
        Mail::fake();
        $this->staff('owner@example.com');

        $this->twentyFailures('Owner@Example.com', '10.2.0');

        Mail::assertSent(RenderedMailable::class, 1);
    }

    /** ★ at most 3 alarm mails an hour in total: the 4th account is only logged; an hour later the budget is back */
    public function test_at_most_three_alarm_mails_an_hour_in_total(): void
    {
        Mail::fake();
        Log::spy();

        foreach (['a', 'b', 'c', 'd'] as $n => $name) {
            $this->staff("{$name}@example.com");
            $this->twentyFailures("{$name}@example.com", '10.3.'.$n);
        }

        Mail::assertSent(RenderedMailable::class, 3);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []): bool => str_contains($m, 'Many failed admin logins') && $c['mailed'] === false)->once();

        Carbon::setTestNow(Carbon::now()->addSeconds(3601));
        $this->twentyFailures('d@example.com', '10.4.0');

        Mail::assertSent(RenderedMailable::class, 4);
    }

    public function test_the_alarm_still_never_blocks_anything(): void
    {
        Mail::fake();
        $this->staff('owner@example.com');
        $this->twentyFailures('owner@example.com', '10.5.0');

        for ($i = 1; $i <= 20; $i++) {
            $this->assertSame(0, app(AdminLoginLockout::class)->lockedFor('owner@example.com', "10.5.0.{$i}"));
        }
    }

    // ---- Docker -----------------------------------------------------------------------------------------------------------------------

    public function test_the_compose_file_passes_security_mode_with_log_as_the_default(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('SECURITY_MODE: ${SECURITY_MODE:-log}', $compose);
    }
}
