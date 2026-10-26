<?php

namespace Tests\Feature;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Pages, stage P1 (pages-design.md section 5): the checkout's terms and privacy links point at a CONTENT PAGE. The terms version is the
 * page's revision (`p{id}r{n}`), so editing the text changes the version by itself and a page that still shows the old one is refused;
 * an unpublished page is no link at all; a page wins over an address; the address mode is what it was.
 */
class CheckoutPagesTest extends TestCase
{
    use \Tests\Feature\Storefront\BuildsMenus;
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    private function set(string $key, ?string $value): void
    {
        $value === null ? app(SiteSettingsRepository::class)->forget($key) : app(SiteSettingsRepository::class)->set($key, $value);
    }

    /** @param array<string, mixed> $override */
    private function page(array $override = []): PageModel
    {
        return app(PageWriter::class)->create(array_merge([
            'title' => 'Terms', 'slug' => 'terms', 'body' => '<p>Version one.</p>', 'status' => 'published',
        ], $override));
    }

    /** One request in a real process starts with empty memos; a test makes several in one process, so it empties them by hand. */
    private function fresh(): void
    {
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(CheckoutFormSettings::class);

        // The router keeps each route's controller instance, and a controller holds the settings reader it was built with.
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /** @param array<string, mixed> $extra */
    private function order(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->fresh();

        return $this->checkout($this->streetFields('BG'), $extra);
    }

    private function shell(): string
    {
        $this->fresh();

        return $this->get('/checkout')->assertOk()->getContent();
    }

    private function termsPage(): PageModel
    {
        $page = $this->page();
        $this->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $page->id);

        return $page;
    }

    // ---- the page and the version ---------------------------------------------------------------------------------------------

    public function test_a_chosen_page_is_the_link_and_its_revision_is_the_version_the_page_shows(): void
    {
        $page = $this->termsPage();
        $privacy = $this->page(['title' => 'Privacy', 'slug' => 'privacy', 'body' => '<p>Privacy.</p>']);
        $this->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, (string) $privacy->id);

        $html = $this->shell();

        $this->assertStringContainsString('data-sf-terms-version="p'.$page->id.'r1"', $html);
        $this->assertStringContainsString('<a href="/info/terms" target="_blank" rel="noopener">terms and conditions</a>', $html);
        $this->assertStringContainsString('<a href="/info/privacy" target="_blank" rel="noopener">privacy policy</a>', $html);
    }

    public function test_the_order_records_the_revision_and_a_text_change_makes_the_old_version_refused(): void
    {
        $page = $this->termsPage();
        $version = 'p'.$page->id.'r1';

        $this->order(['terms_accepted' => true, 'terms_version' => $version])->assertStatus(201);
        $this->assertSame($version, DB::table('orders')->value('terms_version'));
        $this->assertSame($version, DB::table('order_placement_snapshots')->value('terms_version'));
        $this->assertSame('<p>Version one.</p>', DB::table('page_revisions')->where('page_id', $page->id)->where('revision', 1)->value('body'), 'the version names the text she accepted');

        // The merchant edits the text: a new revision, a new version, by itself.
        app(PageWriter::class)->update($page, ['body' => '<p>Version two.</p>']);

        $this->order(['terms_accepted' => true, 'terms_version' => $version])
            ->assertStatus(422)->assertJsonPath('reason', 'terms_version_changed');
        $this->assertSame(1, DB::table('orders')->count(), 'the stale page placed nothing');

        $this->assertStringContainsString('data-sf-terms-version="p'.$page->id.'r2"', $this->shell());
        $this->order(['terms_accepted' => true, 'terms_version' => 'p'.$page->id.'r2'])->assertStatus(201);
        $this->assertSame('p'.$page->id.'r2', DB::table('orders')->orderByDesc('id')->value('terms_version'));
    }

    public function test_a_change_that_is_not_the_text_keeps_the_version(): void
    {
        $page = $this->termsPage();

        app(PageWriter::class)->update($page, ['meta_title' => 'SEO', 'noindex' => true]);

        $this->assertStringContainsString('data-sf-terms-version="p'.$page->id.'r1"', $this->shell());
        $this->order(['terms_accepted' => true, 'terms_version' => 'p'.$page->id.'r1'])->assertStatus(201);
    }

    public function test_a_version_of_another_page_or_the_old_date_style_is_refused(): void
    {
        $page = $this->termsPage();

        foreach (['p999r1', 'p'.$page->id.'r0', 'p'.$page->id, '2026-10-14', 'unversioned'] as $wrong) {
            $this->order(['terms_accepted' => true, 'terms_version' => $wrong])->assertStatus(422)->assertJsonPath('reason', 'terms_version_changed');
        }
    }

    // ---- an unpublished page is no link ------------------------------------------------------------------------------------------

    public function test_an_unpublished_terms_page_shows_no_checkbox_and_the_api_asks_for_nothing(): void
    {
        $page = $this->termsPage();
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/old-terms');

        app(PageWriter::class)->archive($page);

        $html = $this->shell();
        $this->assertStringNotContainsString('name="terms_accepted"', $html);
        $this->assertStringNotContainsString('old-terms', $html, 'a chosen but unpublished page does NOT fall back to the address');

        $this->order()->assertStatus(201);
        $this->assertNull(DB::table('orders')->value('terms_version'));
        $this->order(['terms_accepted' => true, 'terms_version' => 'p'.$page->id.'r1'])->assertStatus(422)->assertJsonValidationErrors(['terms_accepted']);
    }

    public function test_an_unpublished_privacy_page_shows_no_notice(): void
    {
        $privacy = $this->page(['title' => 'Privacy', 'slug' => 'privacy']);
        $this->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, (string) $privacy->id);
        $this->assertStringContainsString('class="sf-privacy-notice"', $this->shell());

        app(PageWriter::class)->archive($privacy);

        $this->assertStringNotContainsString('sf-privacy-notice', $this->shell());
    }

    public function test_a_page_wins_over_an_address_and_the_address_mode_is_unchanged_without_a_page(): void
    {
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/external-terms');
        $this->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-10-14');

        $html = $this->shell();
        $this->assertStringContainsString('href="https://shop.test/external-terms"', $html);
        $this->assertStringContainsString('data-sf-terms-version="2026-10-14"', $html, 'address mode: today\'s behaviour');
        $this->order(['terms_accepted' => true, 'terms_version' => '2026-10-14'])->assertStatus(201);

        $page = $this->termsPage();
        $html = $this->shell();
        $this->assertStringContainsString('href="/info/terms"', $html);
        $this->assertStringNotContainsString('external-terms', $html);
        $this->assertStringContainsString('data-sf-terms-version="p'.$page->id.'r1"', $html);
    }

    public function test_a_draft_or_a_deleted_page_id_stored_in_the_settings_is_no_link_and_no_error(): void
    {
        $this->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, '99999');
        $this->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, 'not-a-number');

        $html = $this->shell();

        $this->assertStringNotContainsString('name="terms_accepted"', $html);
        $this->assertStringNotContainsString('sf-privacy-notice', $html);
        $this->order()->assertStatus(201);
    }

    // ---- the cost ----------------------------------------------------------------------------------------------------------------

    public function test_the_page_lookup_costs_no_query_without_a_page_and_one_with_one_on_the_checkout_request(): void
    {
        $count = function (): \ArrayObject {
            $queries = new \ArrayObject(['pages' => 0, 'all' => 0]);
            DB::listen(function ($query) use ($queries): void {
                $queries['all']++;
                if (str_contains($query->sql, 'from `pages`')) {
                    $queries['pages']++;
                }
            });

            return $queries;
        };

        $this->order()->assertStatus(201); // warm the fixtures (price list, ...)
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'));
        $this->fresh();
        $without = $count();
        $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->assertSame(0, $without['pages'], 'no page chosen: no page lookup, the checkout request cost is what it was');

        $page = $this->termsPage();
        $payload = $this->regionPayload($this->regionCart(), $this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => 'p'.$page->id.'r1']);
        $this->fresh();
        $with = $count();
        $this->postJson('/api/checkout', $payload)->assertStatus(201);
        $this->assertSame(1, $with['pages'], 'a page chosen: ONE indexed read of the published pages');
    }

    public function test_the_checkout_reads_the_pages_once_for_the_terms_and_the_footer_is_the_compiled_menu(): void
    {
        $this->termsPage();
        $returns = $this->page(['title' => 'Returns', 'slug' => 'returns']);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $returns->id])]); // D3: the footer is the footer menu
        $pages = 0;
        $this->fresh();
        DB::listen(function ($query) use (&$pages): void {
            if (str_contains($query->sql, 'from `pages`')) {
                $pages++;
            }
        });

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertSame(1, $pages, 'D3: the terms lookup is the only read of the pages; the footer comes with the appearance (it was the same ONE query before)');
        $this->assertStringContainsString('<a href="/info/returns">Returns</a>', $html);
        $this->assertStringContainsString('name="terms_accepted"', $html);
    }
}
