<?php

namespace Tests\Feature\Security;

use App\Security\Csp\CspSources;
use App\Storefront\Support\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/** P8d: the trap URL, the security headers + the report-only CSP on the storefront, and POST /csp-report. */
class TrapHeadersAndCspReportTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
    }

    // ---- the trap ---------------------------------------------------------------------------------------------------------------------

    /** ★ the trap answers the storefront 404 (never 200), is not cacheable and is logged once with the IP and a truncated agent */
    public function test_the_trap_is_a_logged_uncacheable_404(): void
    {
        Log::spy();

        $response = $this->withHeader('User-Agent', str_repeat('A', 400))->get('/__ec/trap?x=1');

        $response->assertNotFound();
        $this->assertNull($response->headers->get('xkey'));
        $this->assertStringNotContainsString('s-maxage', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('<html', $response->getContent());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'Trap URL hit.'
                && $context['ip'] === '127.0.0.1'
                && strlen($context['user_agent']) === 120
                && $context['path'] === '/__ec/trap';
        })->once();
    }

    public function test_robots_txt_disallows_the_trap_and_never_links_it(): void
    {
        $this->assertStringContainsString("Disallow: /__ec/\n", $this->get('/robots.txt')->getContent());
        $this->assertStringNotContainsString('__ec', $this->get('/')->getContent());
    }

    // ---- the headers ------------------------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function pages(): array
    {
        return ['/', '/product/alpha', '/product-category/clothing', '/brand/acme', '/info/about', '/search?q=alpha', '/cart', '/checkout', '/__ec/trap', '/product/missing-one'];
    }

    /** ★ every storefront page (and the 404) carries the headers and EXACTLY the registry's policy */
    public function test_every_storefront_page_carries_the_headers_and_the_registry_policy(): void
    {
        $policy = app(CspSources::class)->policy();

        foreach ($this->pages() as $url) {
            $this->fresh();
            $response = $this->get($url);
            $headers = $response->headers;

            $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'), $url);
            $this->assertSame(SecurityHeaders::PERMISSIONS_POLICY, $headers->get('Permissions-Policy'), $url);
            $this->assertSame($policy, $headers->get('Content-Security-Policy-Report-Only'), $url);
            $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'), $url);
            $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $url);
            $this->assertNull($headers->get('Content-Security-Policy'), "{$url}: report-only, never enforced");
        }
    }

    /** ★ no 'unsafe-inline' (and nothing else that weakens the core) in the policy */
    public function test_the_policy_is_the_core_without_unsafe_inline(): void
    {
        config(['app.url' => 'https://shop.test']);
        $this->fakePublicDiskWithUrl('https://shop.test/storage');
        $policy = app(CspSources::class)->policy();

        $this->assertSame("default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; report-uri /csp-report", $policy);
        $this->assertStringNotContainsString('unsafe', $policy);
        $this->assertStringNotContainsString('nonce', $policy);
    }

    public function test_the_media_host_is_added_to_img_src_only_when_it_is_not_this_site(): void
    {
        config(['app.url' => 'https://shop.test']);
        $this->fakePublicDiskWithUrl('https://cdn.example.com/storage');
        $this->assertStringContainsString("img-src 'self' data: https://cdn.example.com;", app(CspSources::class)->policy());

        $this->fakePublicDiskWithUrl('https://shop.test/storage');
        $this->assertStringContainsString("img-src 'self' data:;", app(CspSources::class)->policy());
    }

    public function test_an_integration_adds_a_source_through_the_registry_and_nothing_else_gets_in(): void
    {
        $sources = new CspSources;
        $sources->allow('script-src', 'https://js.example.com');
        $sources->allow('script-src', 'https://js.example.com');

        $this->assertStringContainsString("script-src 'self' https://js.example.com;", $sources->policy());

        foreach ([['script-src', "'unsafe-inline'"], ['script-src', '*'], ['script-src', 'http://insecure.test'], ['default-src', 'https://a.test'], ['report-uri', 'https://a.test']] as [$directive, $source]) {
            try {
                $sources->allow($directive, $source);
                $this->fail("{$directive} {$source} must be refused");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_admin_gets_no_csp_from_this_package(): void
    {
        $this->assertNull($this->get('/admin/login')->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_the_order_received_page_keeps_no_referrer_and_gets_the_new_headers(): void
    {
        $response = $this->get('/order/received/does-not-exist');

        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /** the new headers do not break the P8b caching contract */
    public function test_cacheable_pages_keep_their_cache_control_and_xkey(): void
    {
        foreach (['/', '/product/alpha', '/product-category/clothing', '/robots.txt'] as $url) {
            $this->fresh();
            $response = $this->get($url)->assertOk();

            $this->assertSame('max-age=0, public, s-maxage=604800', $response->headers->get('Cache-Control'), $url);
            $this->assertStringContainsString('all', (string) $response->headers->get('xkey'), $url);
        }
    }

    // ---- /csp-report ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function legacyReport(string $directive = 'img-src', string $blocked = 'https://evil.example.com/p.png'): array
    {
        return ['csp-report' => ['document-uri' => 'https://shop.test/product/alpha?utm=1', 'effective-directive' => $directive, 'blocked-uri' => $blocked]];
    }

    private function report(string $type, string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => '*/*'], $body);
    }

    public function test_both_content_types_answer_204_without_cookies_or_keys_and_store_nothing(): void
    {
        $before = DB::table('activity_log')->count();

        $legacy = $this->report('application/csp-report', json_encode($this->legacyReport()));
        $modern = $this->report('application/reports+json', json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src', 'blockedURL' => 'inline', 'documentURL' => 'https://shop.test/']]]));

        foreach ([$legacy, $modern] as $response) {
            $response->assertNoContent();
            $this->assertSame([], $response->headers->getCookies());
            $this->assertNull($response->headers->get('xkey'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        $this->assertSame($before, DB::table('activity_log')->count());
        $this->assertSame([], DB::select("select 1 from information_schema.tables where table_schema = database() and table_name like '%csp%'"), 'no table for reports');
    }

    public function test_a_body_over_eight_kilobytes_is_a_413(): void
    {
        $this->report('application/csp-report', str_repeat('x', 8193))->assertStatus(413);
        $this->report('application/csp-report', json_encode($this->legacyReport()))->assertNoContent();
    }

    /** ★ the 31st report in a minute is a 429 */
    public function test_the_thirty_first_report_in_a_minute_is_throttled(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->report('application/csp-report', json_encode($this->legacyReport()))->assertNoContent();
        }

        $this->report('application/csp-report', json_encode($this->legacyReport()))->assertStatus(429);
    }

    /** one log line per (directive, blocked host) per minute, with the page path without its query */
    public function test_the_same_violation_is_logged_once_per_minute(): void
    {
        Log::spy();

        $this->report('application/csp-report', json_encode($this->legacyReport()));
        $this->report('application/csp-report', json_encode($this->legacyReport()));
        $this->report('application/csp-report', json_encode($this->legacyReport('img-src', 'https://evil.example.com/other.png'))); // same host
        $this->report('application/csp-report', json_encode($this->legacyReport('img-src', 'https://other.example.com/x.png')));
        $this->report('application/csp-report', json_encode($this->legacyReport('script-src', 'https://evil.example.com/x.js')));

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []): bool => $m === 'CSP report-only violation.' && $c['page'] === '/product/alpha')->times(3);
    }

    public function test_a_content_type_that_is_not_a_report_is_answered_and_ignored(): void
    {
        Log::spy();

        $this->report('text/plain', json_encode($this->legacyReport()))->assertNoContent();

        Log::shouldNotHaveReceived('info');
    }
}
