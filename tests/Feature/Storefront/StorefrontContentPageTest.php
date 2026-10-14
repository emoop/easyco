<?php

namespace Tests\Feature\Storefront;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Http\Controllers\Storefront\PageController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Pages, stage P1 (pages-design.md section 4 and 6): GET /info/{slug}, the signed preview and the footer, as a visitor sees them.
 * A page is published or it is the storefront's one 404; its body is sanitized AGAIN at render; nothing else of a page is printed raw.
 */
class StorefrontContentPageTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop', 'app.url' => 'https://shop.test']);
        $this->seedPricing();
    }

    /** @param array<string, mixed> $override */
    private function page(array $override = []): PageModel
    {
        return app(PageWriter::class)->create(array_merge([
            'title' => 'Delivery', 'slug' => 'delivery', 'body' => '<h2>How we deliver</h2><p>Within 2 days.</p>', 'status' => 'published',
        ], $override));
    }

    // ---- the page ------------------------------------------------------------------------------------------------------

    public function test_a_published_page_renders_its_title_its_body_and_its_seo(): void
    {
        $this->page(['meta_title' => 'Delivery times', 'meta_description' => 'How long it takes.']);

        $response = $this->get('/info/delivery')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<title>Delivery times — Raf Shop</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="How long it takes.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/info/delivery">', $html);
        $this->assertStringNotContainsString('name="robots"', $html);
        $this->assertStringContainsString('<h1 class="sf-title">Delivery</h1>', $html);
        $this->assertMatchesRegularExpression('/<div class="sf-description"><h2>How we deliver<\/h2><p>Within 2 days\.<\/p><\/div>/', $html);
        $this->assertFalse($response->headers->has('Set-Cookie'), 'cookie-free like the rest of the storefront');
        $this->assertFalse($this->app['session']->driver()->isStarted());
    }

    public function test_the_seo_falls_back_to_the_title_and_the_first_160_characters_of_the_text(): void
    {
        $this->page(['body' => '<p>'.str_repeat('Word ', 60).'</p>']);

        $html = $this->get('/info/delivery')->getContent();

        $this->assertStringContainsString('<title>Delivery — Raf Shop</title>', $html);
        preg_match('/<meta name="description" content="([^"]*)">/', $html, $description);
        $this->assertLessThanOrEqual(160, mb_strlen(html_entity_decode($description[1])));
        $this->assertStringStartsWith('Word Word Word', $description[1]);
    }

    public function test_noindex_asks_for_the_robots_meta_and_keeps_the_canonical(): void
    {
        $this->page(['noindex' => true]);

        $html = $this->get('/info/delivery')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/info/delivery">', $html);
    }

    public function test_a_draft_an_unknown_slug_and_a_malformed_one_are_the_storefronts_own_404_with_the_same_body(): void
    {
        $this->page(['slug' => 'secret', 'status' => 'draft', 'body' => '<p>Not yet.</p>']);

        $reference = $this->get('/product/this-product-does-not-exist')->assertNotFound()->getContent();

        foreach (['/info/secret', '/info/nothing-here', '/info/UPPER', '/info/a%20b', '/info/'.str_repeat('a', 300)] as $url) {
            $response = $this->get($url)->assertNotFound();

            $this->assertSame($reference, $response->getContent(), $url.': the same body as any missing page');
        }

        $this->assertStringNotContainsString('Not yet.', $reference);
    }

    public function test_an_archived_page_is_a_404_again(): void
    {
        $page = $this->page();
        $this->get('/info/delivery')->assertOk();

        app(PageWriter::class)->archive($page);

        $this->get('/info/delivery')->assertNotFound();
    }

    public function test_the_body_is_sanitized_again_at_render_so_a_row_edited_by_hand_cannot_carry_a_script(): void
    {
        $page = $this->page();
        // What a stored body must never hold, put there by hand (the writer would have cleaned it).
        DB::table('pages')->where('id', $page->id)->update(['body' => '<p>Hello</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)" onclick="x()">link</a><p style="color:red">styled</p><iframe src="https://evil.test"></iframe>']);

        $html = $this->get('/info/delivery')->assertOk()->getContent();

        $this->assertStringContainsString('<p>Hello</p>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('style="color', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('<a rel="noopener nofollow">link</a>', $html, 'the link is kept as text, its dangerous href dropped');
    }

    public function test_the_title_and_the_seo_fields_are_escaped_never_printed_raw(): void
    {
        $page = $this->page();
        // Plain-text fields are escaped at render even if a row was edited by hand.
        DB::table('pages')->where('id', $page->id)->update(['title' => '<script>alert(1)</script>', 'meta_title' => '"><script>x</script>', 'meta_description' => '"><img src=x onerror=y>']);

        $html = $this->get('/info/delivery')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_cyrillic_slug_is_served_and_a_slug_that_differs_by_case_is_a_301_to_the_canonical_url(): void
    {
        $this->page(['slug' => 'доставка', 'title' => 'Доставка']);

        $this->get('/info/'.rawurlencode('доставка'))->assertOk()->assertSee('Доставка');
        $this->get('/info/'.rawurlencode('Доставка'))->assertStatus(301)->assertRedirect('/info/'.rawurlencode('доставка'));
    }

    // ---- the signed preview ----------------------------------------------------------------------------------------------

    public function test_a_signed_preview_shows_a_draft_and_is_never_indexed(): void
    {
        $draft = $this->page(['slug' => 'draft-page', 'status' => 'draft', 'body' => '<p>Work in progress.</p>']);

        $response = $this->get(PageController::previewUrl($draft))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Work in progress.', $html);
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_an_unsigned_tampered_or_expired_preview_is_the_storefronts_404(): void
    {
        $draft = $this->page(['slug' => 'draft-page', 'status' => 'draft']);
        $other = $this->page(['slug' => 'other-draft', 'title' => 'Other', 'status' => 'draft', 'body' => '<p>Other secret.</p>']);
        $reference = $this->get('/product/nope')->getContent();

        $this->get('/info-preview/'.$draft->id)->assertNotFound();

        $signed = PageController::previewUrl($draft);
        $this->get(str_replace('/info-preview/'.$draft->id, '/info-preview/'.$other->id, $signed))->assertNotFound();
        $this->assertSame($reference, $this->get('/info-preview/'.$draft->id)->getContent());
        $this->get($signed.'x')->assertNotFound();

        $this->travel(PageController::PREVIEW_MINUTES + 1)->minutes();
        $this->get($signed)->assertNotFound();
    }

    public function test_the_preview_bypasses_only_the_published_check(): void
    {
        $page = $this->page();
        DB::table('pages')->where('id', $page->id)->update(['body' => '<p>ok</p><script>alert(1)</script>']);

        $html = $this->get(PageController::previewUrl($page))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html, 'a preview is sanitized like the page');
        $this->assertTrue(Route::has('storefront.page.preview'));
        $this->assertTrue(URL::hasValidSignature(request()->create(PageController::previewUrl($page))));
    }

    // ---- the footer --------------------------------------------------------------------------------------------------------

    public function test_the_footer_lists_published_footer_pages_in_order_and_nothing_else(): void
    {
        $this->page(['slug' => 'returns', 'title' => 'Returns', 'in_footer' => true, 'footer_order' => 20]);
        $this->page(['slug' => 'contacts', 'title' => 'Contacts', 'in_footer' => true, 'footer_order' => 10]);
        $this->page(['slug' => 'about', 'title' => 'About', 'in_footer' => true, 'footer_order' => 10]);
        $this->page(['slug' => 'доставка', 'title' => 'Доставка <b>', 'in_footer' => true, 'footer_order' => 30]);
        $this->page(['slug' => 'not-in-footer', 'title' => 'Hidden', 'in_footer' => false]);
        $this->page(['slug' => 'a-draft', 'title' => 'Draft', 'in_footer' => true, 'status' => 'draft']);

        $html = $this->get('/')->assertOk()->getContent();
        preg_match('/<nav class="sf-footer-pages"[^>]*>(.*?)<\/nav>/s', $html, $nav);
        preg_match_all('/<a href="([^"]+)">([^<]*)<\/a>/', $nav[1], $links, PREG_SET_ORDER);

        $this->assertSame([
            ['/info/about', 'About'], ['/info/contacts', 'Contacts'], ['/info/returns', 'Returns'],
            ['/info/'.rawurlencode('доставка'), 'Доставка &lt;b&gt;'],
        ], array_map(fn ($link) => [$link[1], $link[2]], $links), 'footer_order, then title; escaped; published only');
        $this->assertStringNotContainsString('Hidden', $nav[1]);
        $this->assertStringNotContainsString('Draft', $nav[1]);
    }

    public function test_the_footer_is_on_every_kind_of_storefront_page_and_absent_when_there_is_none(): void
    {
        $this->assertStringNotContainsString('sf-footer-pages', $this->get('/')->getContent());

        $this->page(['in_footer' => true]);

        foreach (['/', '/cart', '/checkout', '/info/delivery', '/info/missing'] as $url) {
            $this->app->forgetScopedInstances(); // one request, one memo: a real request never sees the previous one's list
            $this->assertStringContainsString('<a href="/info/delivery">Delivery</a>', $this->get($url)->getContent(), $url);
        }
    }

    public function test_the_footer_costs_one_query_whatever_the_number_of_pages_and_the_page_view_adds_none(): void
    {
        $count = function (string $url): int {
            $this->app->forgetScopedInstances();
            $queries = 0;
            DB::listen(function ($query) use (&$queries): void {
                if (str_contains($query->sql, 'from `pages`')) {
                    $queries++;
                }
            });
            $this->get($url);

            return $queries;
        };

        $this->assertSame(1, $count('/'), 'none flagged: still ONE read of the published pages');

        for ($n = 1; $n <= 8; $n++) {
            $this->page(['slug' => 'p-'.$n, 'title' => 'Page '.$n, 'in_footer' => $n % 2 === 0]);
        }

        $this->assertSame(1, $count('/'), 'eight pages, four in the footer: the same ONE query');
        $this->assertSame(2, $count('/info/p-3'), 'a page view: the footer\'s list plus the page itself (its body)');
        $this->assertSame(2, $count('/info/missing'), 'a 404: the lookup that found nothing, plus the footer');
    }
}
