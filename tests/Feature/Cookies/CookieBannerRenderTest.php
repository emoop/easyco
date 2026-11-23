<?php

namespace Tests\Feature\Cookies;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CookieSettings;
use App\Storefront\Header\AppearanceRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/**
 * CC-1 (cookie-consent.md): what the storefront prints. Off: nothing. On: ONE identical block for every visitor (the cookie is never read by the server), two EQUAL choice buttons, nothing
 * pre-ticked, no inline script / style / handler, and no query of its own.
 */
class CookieBannerRenderTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->fresh();
    }

    /** @return array<string, mixed> */
    private function data(array $override = []): array
    {
        return $override + [
            'enabled' => true, 'position' => 'center', 'view' => 'summary', 'months' => 12, 'page' => null, 'color' => '#111111',
            'texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')],
        ];
    }

    private function enable(array $override = []): void
    {
        app(CookieSettings::class)->save($this->data($override));
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->fresh();
    }

    private function page(string $url = '/'): string
    {
        $this->fresh();

        return (string) $this->get($url)->assertOk()->getContent();
    }

    /** the banner's own HTML (from its root to the end of the page) */
    private function banner(string $html): string
    {
        $start = strpos($html, '<div class="sf-cc-bar"');
        $this->assertNotFalse($start, 'the banner is on the page');

        return substr($html, $start);
    }

    /** ★ off: no markup, no script, no footer link */
    public function test_disabled_prints_no_banner_no_script_and_no_footer_link(): void
    {
        $html = $this->page();

        foreach (['data-sf-cc', 'sf-cc-bar', 'storefront-consent', 'sf-cc-dialog', 'easyco_consent'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, $needle);
        }

        // switched on and off again: the same
        $this->enable();
        $this->enable(['enabled' => false]);
        $this->assertStringNotContainsString('data-sf-cc', $this->page());
    }

    /** ★ on: the markup is there; both layers; the whole block `hidden` until JS decides */
    public function test_enabled_prints_the_banner_with_both_layers_hidden_until_script(): void
    {
        $this->enable();
        $html = $this->page();
        $banner = $this->banner($html);

        $this->assertStringContainsString('<div class="sf-cc sf-cc-center" data-sf-cc', $banner);
        $this->assertMatchesRegularExpression('/<div class="sf-cc sf-cc-center"[^>]* hidden\s*>/', $banner, 'hidden until the script decides');
        $this->assertStringContainsString('role="dialog"', $banner);
        $this->assertStringContainsString('aria-modal="true"', $banner, 'centre = modal');
        $this->assertStringContainsString('data-sf-cc-layer="summary"', $banner);
        $this->assertStringContainsString('data-sf-cc-layer="details"', $banner);
        $this->assertStringContainsString('data-sf-cc-layer="details" hidden', $banner, 'the inactive layer is hidden');
        $this->assertStringContainsString('data-sf-cc-bar hidden', $banner, 'the footer link shows once the script runs');
        $this->assertStringContainsString(CookieSettings::defaults('bg')['footer_link'], $banner);
        $this->assertStringContainsString('storefront-consent', $banner, 'the one external script');
        $this->assertStringContainsString('data-version="1"', $banner);
    }

    /** ★ the two choice buttons are ONE class: no accent, no second style */
    public function test_the_two_choice_buttons_share_one_class_on_both_layers(): void
    {
        $this->enable();
        $banner = $this->banner($this->page());

        $this->assertSame(2, preg_match_all('/<button type="button" class="sf-cc-btn" data-sf-cc-action="accept">/', $banner));
        $this->assertSame(2, preg_match_all('/<button type="button" class="sf-cc-btn" data-sf-cc-action="reject">/', $banner));
        $this->assertStringNotContainsString('accent', $banner);
        $this->assertStringNotContainsString('sf-cc-btn-secondary', $banner);
        $this->assertStringContainsString(CookieSettings::defaults('bg')['accept_all'], $banner);
        $this->assertStringContainsString(CookieSettings::defaults('bg')['necessary_only'], $banner);
    }

    /** ★ nothing is pre-ticked: the ads switch is OFF in the markup */
    public function test_the_ads_switch_is_off_by_default(): void
    {
        $this->enable();
        $banner = $this->banner($this->page());

        $this->assertMatchesRegularExpression('/role="switch" aria-checked="false"[^>]*data-sf-cc-switch="ads"/', $banner);
        $this->assertStringNotContainsString('aria-checked="true"', $banner);
        $this->assertStringNotContainsString('checked=', str_replace('aria-checked=', '', $banner));
    }

    /** no close button, no inline script / style / handler */
    public function test_no_close_button_and_nothing_inline(): void
    {
        $this->enable();
        $banner = $this->banner($this->page());

        $this->assertStringNotContainsString('data-sf-cc-action="close"', $banner);
        $this->assertStringNotContainsString('aria-label="Затвори', $banner);
        $this->assertStringNotContainsString('style=', $banner);
        $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $banner), 'no on* attribute');
        $this->assertSame(0, preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $banner), 'no inline script');
        $this->assertSame(0, preg_match('/<style/i', $banner), 'no inline style');
        $this->assertSame(0, preg_match('#https?://#i', $banner), 'no third-party (nor any absolute) address');
    }

    /** ★ the same page with and without the cookie: byte-identical HTML, the same headers (the server never reads it) */
    public function test_the_page_is_identical_with_and_without_the_consent_cookie(): void
    {
        $this->enable();

        $this->fresh();
        $without = $this->get('/');
        $variants = ['v=1&ads=1&t=1700000000', 'v=1&ads=0&t=1700000000', 'v=9&ads=1&t=1', 'garbage'];

        foreach ($variants as $value) {
            $this->fresh();
            $with = $this->call('GET', '/', [], ['easyco_consent' => $value]);

            $this->assertSame($without->getContent(), $with->getContent(), "the HTML differs with the cookie {$value}");

            $a = $without->headers->all();
            $b = $with->headers->all();
            unset($a['date'], $b['date']);
            $this->assertSame($a, $b, "the headers differ with the cookie {$value}");
        }
    }

    /** the cached page is not made private by the banner, and never sets a cookie */
    public function test_the_page_stays_cacheable_and_sets_no_cookie(): void
    {
        $this->enable();
        $this->fresh();
        $response = $this->get('/');

        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('s-maxage=604800', (string) $response->headers->get('Cache-Control'));
    }

    /** ★ no query per storefront request for the banner: a warm page costs the same with and without it */
    public function test_the_banner_adds_no_query(): void
    {
        $count = function (): int {
            $this->fresh();
            $this->get('/')->assertOk(); // warms the application cache entry
            $this->fresh();
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            $this->get('/')->assertOk();

            return $queries;
        };

        $off = $count();
        $this->enable();
        $on = $count();

        $this->assertSame($off, $on, 'a warm storefront page costs the same number of queries with the banner on');
    }

    public function test_position_view_and_months_reach_the_markup(): void
    {
        $this->enable(['position' => 'bottom', 'view' => 'details', 'months' => 6]);
        $banner = $this->banner($this->page());

        $this->assertStringContainsString('sf-cc sf-cc-bottom', $banner);
        $this->assertStringNotContainsString('aria-modal', $banner, 'the bottom card is non-modal');
        $this->assertStringContainsString('data-modal="0"', $banner);
        $this->assertStringContainsString('data-view="details"', $banner);
        $this->assertStringContainsString('data-months="6"', $banner);
        $this->assertStringContainsString('aria-labelledby="sf-cc-title-details"', $banner, 'the opening layer names the dialog');
        $this->assertStringContainsString('data-sf-cc-layer="summary" hidden', $banner);
    }

    /** the texts of site.locale; each key falls back to the shipped default; the banner is never empty */
    public function test_texts_follow_the_shop_language_and_fall_back_per_key(): void
    {
        $en = CookieSettings::defaults('en');
        $en['title'] = 'Hello visitor';
        $this->enable(['texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => $en]]);

        $this->assertStringContainsString(CookieSettings::defaults('bg')['title'], $this->page());

        app(SiteSettingsRepository::class)->set('site.locale', 'en');
        $this->assertStringContainsString('Hello visitor', $this->page());

        // damaged, blank, too long, wrong type: each falls back (per key) to the shipped text
        app(SiteSettingsRepository::class)->set('cookies.texts.en', json_encode(['title' => '   ', 'text' => 123, 'accept_all' => str_repeat('x', 500), 'save' => "bad\nline", 'back' => 'Go back']));
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);
        $html = $this->page();
        $this->assertStringContainsString($en['title'] === 'Hello visitor' ? CookieSettings::defaults('en')['title'] : '', $html);
        $this->assertStringContainsString(CookieSettings::defaults('en')['text'], $html);
        $this->assertStringContainsString(CookieSettings::defaults('en')['accept_all'], $html);
        $this->assertStringContainsString('Go back', $html, 'a good key is kept');

        app(SiteSettingsRepository::class)->set('cookies.texts.en', '{not json');
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);
        $this->assertStringContainsString(CookieSettings::defaults('en')['title'], $this->page());

        // a language with no shipped entry still renders (the app's fallback language)
        app(SiteSettingsRepository::class)->set('site.locale', 'de');
        $this->assertStringContainsString('data-sf-cc', $this->page());
    }

    public function test_the_policy_link_follows_the_picked_published_page_and_texts_are_escaped(): void
    {
        $bg = CookieSettings::defaults('bg');
        $bg['title'] = '<b>Hi</b> & "you"';
        $this->enable(['page' => (int) DB::table('pages')->where('slug', 'about')->value('id'), 'texts' => ['bg' => $bg, 'en' => CookieSettings::defaults('en')]]);
        $banner = $this->banner($this->page());

        $this->assertStringContainsString('<a class="sf-cc-policy" href="/info/about">About</a>', $banner);
        $this->assertStringNotContainsString('<b>Hi</b>', $banner);
        $this->assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt; &amp; &quot;you&quot;', $banner);

        $this->enable(['page' => null]);
        $this->assertStringNotContainsString('sf-cc-policy', $this->banner($this->page()), 'no page, no link');
    }
}
