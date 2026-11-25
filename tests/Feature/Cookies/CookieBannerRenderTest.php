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
 * pre-ticked, no inline script / style / handler, and no query of its own. CC-2: THREE equal buttons, a «×» that closes without a choice, and a marker on the policy page.
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

    /** ★ CC-2: THREE buttons of equal weight on the first layer, all of ONE class: no accent, no second style, no link-looking third choice */
    public function test_the_three_choice_buttons_of_the_first_layer_share_one_class(): void
    {
        $this->enable();
        $banner = $this->banner($this->page());
        $summary = substr($banner, (int) strpos($banner, 'data-sf-cc-layer="summary"'), (int) strpos($banner, 'data-sf-cc-layer="details"') - (int) strpos($banner, 'data-sf-cc-layer="summary"'));
        $texts = CookieSettings::defaults('bg');

        $this->assertSame(1, preg_match('#<div class="sf-cc-actions">\s*<button type="button" class="sf-cc-btn" data-sf-cc-action="accept">'.preg_quote($texts['accept_all'], '#').'</button>\s*<button type="button" class="sf-cc-btn" data-sf-cc-action="reject">'.preg_quote($texts['reject'], '#').'</button>\s*<button type="button" class="sf-cc-btn" data-sf-cc-action="details">'.preg_quote($texts['settings_link'], '#').'</button>\s*</div>#u', $summary), 'accept all, reject, settings: one class, one row');
        $this->assertSame(3, substr_count($summary, 'class="sf-cc-btn"'));
        $this->assertStringNotContainsString('sf-cc-link', $banner, 'no link-looking choice any more');
        $this->assertSame('Отказвам', $texts['reject']);

        // the second layer: accept and reject share the class too (the one «Запази избора» is the full-width button below)
        $this->assertSame(2, preg_match_all('/<button type="button" class="sf-cc-btn" data-sf-cc-action="accept">/', $banner));
        $this->assertSame(2, preg_match_all('/<button type="button" class="sf-cc-btn" data-sf-cc-action="reject">/', $banner));
        $this->assertStringNotContainsString('accent', $banner);
        $this->assertStringNotContainsString('sf-cc-btn-secondary', $banner);
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

    /** CC-2: a real «×» button with an accessible name, in the dialog; nothing inline anywhere */
    public function test_the_close_button_exists_and_nothing_is_inline(): void
    {
        $this->enable();
        $banner = $this->banner($this->page());
        $close = CookieSettings::defaults('bg')['close'];

        $this->assertSame(1, preg_match('#<button type="button" class="sf-cc-close" data-sf-cc-action="dismiss" aria-label="'.preg_quote($close, '#').'"><span aria-hidden="true">×</span></button>#u', $banner));
        $this->assertSame('Затвори', $close);
        $this->assertStringNotContainsString('data-sf-cc-action="close"', $banner);
        $this->assertStringNotContainsString('style=', $banner);
        $this->assertSame(0, preg_match('/\son[a-z]+\s*=/i', $banner), 'no on* attribute');
        $this->assertSame(0, preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $banner), 'no inline script');
        $this->assertSame(0, preg_match('/<style/i', $banner), 'no inline style');
        $this->assertSame(0, preg_match('#https?://#i', $banner), 'no third-party (nor any absolute) address');
    }

    /** ★ CC-2: the policy page carries a marker, no other page does, and the marker is the same for every visitor (it depends on the URL only) */
    public function test_the_policy_page_carries_the_marker_and_no_other_page_does(): void
    {
        $this->enable(['page' => (int) DB::table('pages')->where('slug', 'about')->value('id')]);

        $policy = $this->banner($this->page('/info/about'));
        $this->assertMatchesRegularExpression('/<div class="sf-cc sf-cc-center"[^>]* data-policy-page="1"/', $policy);
        $this->assertSame(1, substr_count($policy, 'data-policy-page'));
        $this->assertStringContainsString('class="sf-cc-short"', $policy, 'the one short line of the thin bar');

        $this->assertStringNotContainsString('data-policy-page', $this->banner($this->page('/')), 'the home page');

        $otherPage = (string) DB::table('pages')->where('slug', '!=', 'about')->value('slug');

        if ($otherPage !== '') {
            $this->assertStringNotContainsString('data-policy-page', $this->banner($this->page('/info/'.$otherPage)), 'another page');
        }

        // the same for two different visitors (a cookie, a language header, an agent): the server never looks at any of them
        $this->fresh();
        $first = $this->call('GET', '/info/about', [], ['easyco_consent' => 'v=1&ads=0&t=1700000000'])->getContent();
        $this->fresh();
        $second = $this->withHeaders(['Accept-Language' => 'en', 'User-Agent' => 'other'])->get('/info/about')->getContent();
        $this->assertSame($first, $second);

        // and with no policy page picked, no page carries it
        $this->enable(['page' => null]);
        $this->assertStringNotContainsString('data-policy-page', $this->banner($this->page('/info/about')));
    }

    /** ★ CC-2: a payload cached BEFORE the new text keys existed (it lives in the cache across deploys) never breaks the page: the missing keys are the shipped defaults */
    public function test_a_cached_payload_without_the_new_text_keys_still_renders_with_the_shipped_defaults(): void
    {
        $this->enable();
        $this->page();

        $cached = \Illuminate\Support\Facades\Cache::get(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);
        $this->assertIsArray($cached);

        foreach (['bg', 'en'] as $locale) {
            unset($cached['cookies']['texts'][$locale]['reject'], $cached['cookies']['texts'][$locale]['close'], $cached['cookies']['texts'][$locale]['bar_text']);
        }

        \Illuminate\Support\Facades\Cache::forever(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY, $cached);
        $this->fresh();
        $banner = $this->banner((string) $this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('>'.CookieSettings::defaults('bg')['reject'].'</button>', $banner);
        $this->assertStringContainsString('aria-label="'.CookieSettings::defaults('bg')['close'].'"', $banner);
        $this->assertStringContainsString('class="sf-cc-short">'.CookieSettings::defaults('bg')['bar_text'].'<', $banner);
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
