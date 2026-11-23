<?php

namespace Tests\Feature\Cookies;

use App\Content\Models\PageModel;
use App\Filament\Pages\Settings\CookieSettingsPage;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CookieSettings;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use App\Storefront\Header\AppearanceRecompiler;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/**
 * CC-1 (cookie-consent.md): «Поверителност → Бисквитки»: the permission (open, Livewire, save), the closed lists and ranges, the colour's contrast, the text version, and that a save refreshes
 * the storefront like a «Дизайн» publish (the next page shows the new text and colour, one purge, no manual cache clear). A policy page that is renamed, unpublished or deleted never leaves a dead link.
 */
class CookieSettingsPageTest extends TestCase
{
    use BuildsCacheShop;
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->fresh();
    }

    private function settings(): CookieSettings
    {
        return app(CookieSettings::class);
    }

    /** @return array<string, mixed> */
    private function data(array $override = []): array
    {
        return $override + [
            'enabled' => true, 'position' => 'center', 'view' => 'summary', 'months' => 12, 'page' => null, 'color' => '#111111',
            'texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')],
        ];
    }

    private function save(array $override = []): void
    {
        $this->settings()->save($this->data($override));
    }

    private function version(): int
    {
        return (int) app(SiteSettingsRepository::class)->get(CookieSettings::VERSION);
    }

    private function aboutId(): int
    {
        return (int) DB::table('pages')->where('slug', 'about')->value('id');
    }

    private function page(string $url = '/'): string
    {
        $this->fresh();

        return (string) $this->get($url)->assertOk()->getContent();
    }

    private function clickSave(\Livewire\Features\SupportTesting\Testable $page): \Livewire\Features\SupportTesting\Testable
    {
        return $page->callAction(TestAction::make('save')->schemaComponent('form-actions', schema: 'content'));
    }

    // ---- the permission -------------------------------------------------------------------------------------------------------------

    /** ★ PRIVACY_MANAGE on open, on every Livewire request and in the save */
    public function test_the_page_needs_privacy_manage_on_the_server(): void
    {
        $this->actingAsCustomRole([Permission::SETTINGS_MANAGE, Permission::CUSTOMER_VIEW]);
        $this->get('/admin/privacy-cookies')->assertForbidden();
        $this->assertFalse(CookieSettingsPage::canAccess());

        $this->actingAsStaff('Administrator');
        $page = Livewire::test(CookieSettingsPage::class);
        $this->get('/admin/privacy-cookies')->assertOk();

        // the save is protected: a hand-made call is refused
        $threw = false;

        try {
            $page->call('persist');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertNull(app(SiteSettingsRepository::class)->get(CookieSettings::ENABLED));

        // a user who lost the permission is refused on the next request, and the save writes nothing
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        $forbidden = false;

        try {
            $this->clickSave($page->set('data.enabled', true));
        } catch (\Throwable) {
            $forbidden = true;
        }

        $this->assertTrue($forbidden);
        $this->assertNull(app(SiteSettingsRepository::class)->get(CookieSettings::ENABLED));
    }

    public function test_the_item_sits_in_the_privacy_group_third_with_its_full_title_as_tooltip(): void
    {
        $this->assertSame(\App\Filament\NavigationGroup::PRIVACY, CookieSettingsPage::getNavigationGroup());
        $this->assertSame(30, CookieSettingsPage::getNavigationSort());
        $this->assertSame(__('cookies_admin.title'), CookieSettingsPage::getNavigationItems()[0]->getExtraAttributes()['title']);
        $this->assertSame('Бисквитки', __('cookies_admin.navigation_label', [], 'bg'));
        $this->assertSame('Бисквитки и съгласие', __('cookies_admin.title', [], 'bg'));
        $this->assertGreaterThan(\App\Filament\Pages\Settings\PrivacySettingsPage::getNavigationSort(), CookieSettingsPage::getNavigationSort());
    }

    // ---- the values -----------------------------------------------------------------------------------------------------------------

    public function test_the_defaults_are_off_center_summary_12_months(): void
    {
        $current = $this->settings()->current();

        $this->assertFalse($current['enabled']);
        $this->assertSame('center', $current['position']);
        $this->assertSame('summary', $current['view']);
        $this->assertSame(12, $current['months']);
        $this->assertNull($current['page']);
        $this->assertSame('#111111', $current['color']);
        $this->assertSame(CookieSettings::defaults('bg'), $current['texts']['bg'], 'prefilled with the shipped texts');
        $this->assertNull(CookieSettings::payload([]), 'nothing stored = no banner');
    }

    public function test_closed_lists_ranges_and_formats_are_enforced(): void
    {
        $bad = [
            'position' => ['left', ''], 'view' => ['both', null], 'months' => [0, 14, 'x', 1.5, -3],
            'color' => ['red', '#ABCDEF', '#123', '#12345g', ''], 'page' => [999999, 'abc'],
        ];

        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                try {
                    $this->save([$field => $value]);
                    $this->fail("{$field} = ".var_export($value, true).' was accepted');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey("data.{$field}", $e->errors(), (string) $field);
                }
            }
        }

        $this->assertNull(app(SiteSettingsRepository::class)->get(CookieSettings::ENABLED), 'a refused save writes nothing');

        foreach ([1, 13] as $months) {
            $this->save(['months' => $months]);
            $this->assertSame($months, $this->settings()->current()['months']);
        }
    }

    public function test_a_page_that_is_a_draft_cannot_be_picked(): void
    {
        $draft = PageModel::query()->create(['slug' => 'policy-draft', 'title' => 'Policy', 'body' => '<p>x</p>', 'status' => 'draft', 'revision' => 1]);

        $this->expectException(ValidationException::class);
        $this->save(['page' => (int) $draft->getKey()]);
    }

    /** every text of every language is required, plain, and within its limit */
    public function test_every_text_is_required_plain_and_limited(): void
    {
        $cases = [
            'blank' => ['   ', 'data.texts.bg.title'],
            'newline' => ["a\nb", 'data.texts.bg.title'],
            'too long' => [str_repeat('x', CookieSettings::TEXT_LIMITS['accept_all'] + 1), 'data.texts.bg.accept_all'],
        ];

        foreach ($cases as $name => [$value, $field]) {
            $key = substr($field, strrpos($field, '.') + 1);
            $texts = ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')];
            $texts['bg'][$key] = $value;

            try {
                $this->save(['texts' => $texts]);
                $this->fail("{$name} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $name);
            }
        }

        $texts = ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')];
        unset($texts['en']['save']);

        try {
            $this->save(['texts' => $texts]);
            $this->fail('a missing english text was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.texts.en.save', $e->errors());
        }
    }

    // ---- the colour -----------------------------------------------------------------------------------------------------------------

    /** ★ a colour whose best text colour is below 4.5:1, or that is below 3:1 against the white dialog, is refused */
    public function test_a_colour_that_fails_the_contrast_is_refused(): void
    {
        // (the 4.5:1 rule for the text inside the buttons can never fire with a computed white-or-black text: the worse colour of a mid-tone still gives >= 4.58:1, see ContrastTest)

        // a light yellow: black text reads fine, but the button is under 3:1 against the white dialog
        $this->assertGreaterThanOrEqual(4.5, \App\Storefront\Cookies\Contrast::bestText('#ffe680')['ratio']);

        try {
            $this->save(['color' => '#ffe680']);
            $this->fail('a button colour that blends into the white dialog was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.color', $e->errors());
        }

        $this->save(['color' => '#1d4ed8']);
        $this->assertSame('#1d4ed8', $this->settings()->current()['color']);
    }

    // ---- the text version ----------------------------------------------------------------------------------------------------------

    /** ★ bumped by a text or the picked policy page; NOT by position, colour, view or validity */
    public function test_the_text_version_bumps_on_text_or_policy_page_only(): void
    {
        $this->save();
        $first = $this->version();
        $this->assertSame(1, $first);

        $this->save(['position' => 'bottom', 'view' => 'details', 'months' => 6, 'color' => '#1d4ed8', 'enabled' => false]);
        $this->assertSame($first, $this->version(), 'position, view, validity, colour and the switch never bump');

        $this->save(['position' => 'bottom', 'view' => 'details', 'months' => 6, 'color' => '#1d4ed8']);
        $this->assertSame($first, $this->version(), 'the same values again: no bump');

        $texts = ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')];
        $texts['en']['title'] = 'Another title';
        $this->save(['texts' => $texts]);
        $this->assertSame($first + 1, $this->version(), 'a text of ANY language bumps');

        $this->save(['texts' => $texts, 'page' => $this->aboutId()]);
        $this->assertSame($first + 2, $this->version(), 'the picked policy page bumps');

        $this->save(['texts' => $texts, 'page' => $this->aboutId()]);
        $this->assertSame($first + 2, $this->version());

        $this->save(['texts' => $texts, 'page' => null]);
        $this->assertSame($first + 3, $this->version(), 'removing the page bumps too');
    }

    // ---- a save refreshes the storefront like a «Дизайн» publish ------------------------------------------------------------------

    /** ★ the save through the page: the next render shows the new text and colour with no manual cache clear, and one purge of every page */
    public function test_a_save_refreshes_text_colour_and_purges_like_a_design_publish(): void
    {
        $purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $purger);
        app(CachePurgeCollector::class)->reset();

        $this->actingAsStaff('Administrator');
        $texts = ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')];
        $texts['bg']['title'] = 'Нов заглавен текст';

        $page = Livewire::test(CookieSettingsPage::class);
        $page->fillForm($this->data(['color' => '#1d4ed8', 'texts' => $texts]));
        $this->clickSave($page)->assertHasNoFormErrors();

        $html = $this->page();
        $this->assertStringContainsString('Нов заглавен текст', $html, 'the next render shows the new text');

        $link = $this->tokensCss($html);
        $this->assertStringContainsString('--sf-cc-btn: #1d4ed8;', $link);
        $this->assertStringContainsString('--sf-cc-btn-text: #ffffff;', $link, 'the text colour is computed, never typed');

        app(CachePurgeCollector::class)->flush();
        $this->assertContains('all', $purger->keys(), 'every cached page is purged');
    }

    /** the colour returns to the fallback (no variable) when it is the default or the banner is off */
    public function test_the_colour_variables_exist_only_while_on_and_not_default(): void
    {
        $this->save(['color' => '#1d4ed8']);
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->assertStringContainsString('--sf-cc-btn', $this->tokensCss($this->page()));

        $this->save(['color' => '#1d4ed8', 'enabled' => false]);
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->assertStringNotContainsString('--sf-cc-btn', $this->tokensCss($this->page()));

        $this->save(['color' => '#111111']);
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->assertStringNotContainsString('--sf-cc-btn', $this->tokensCss($this->page()));
    }

    /** a cache entry written before this package (no `cookies` key) is rebuilt once */
    public function test_an_old_cached_payload_is_rebuilt(): void
    {
        $this->save();
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $old = \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY);
        unset($old['cookies']);
        \Illuminate\Support\Facades\Cache::forever(StorefrontAppearance::CACHE_KEY, $old);

        $this->assertStringContainsString('data-sf-cc', $this->page());
        $this->assertArrayHasKey('cookies', \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY));
    }

    private function tokensCss(string $html): string
    {
        if (preg_match('#<link rel="stylesheet" href="(/storage/appearance/tokens-[0-9a-f]{12}\.css)">#', $html, $m) !== 1) {
            return '';
        }

        return (string) Storage::disk('public')->get(substr($m[1], strlen('/storage/')));
    }

    // ---- the policy page never leaves a dead link --------------------------------------------------------------------------------

    /** ★ renamed: the link follows; unpublished and deleted: NO link, and the admin warns */
    public function test_a_renamed_unpublished_or_deleted_policy_page_never_leaves_a_dead_link(): void
    {
        $this->save(['page' => $this->aboutId()]);
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->assertStringContainsString('<a class="sf-cc-policy" href="/info/about">About</a>', $this->page());

        // renamed (slug AND title): the model event refreshes the payload
        $about = PageModel::query()->findOrFail($this->aboutId());
        $about->update(['slug' => 'about-us', 'title' => 'About us']);
        $html = $this->page();
        $this->assertStringContainsString('<a class="sf-cc-policy" href="/info/about-us">About us</a>', $html);
        $this->assertStringNotContainsString('href="/info/about"', $html);

        // unpublished
        $about->refresh()->update(['status' => 'draft']);
        $this->assertStringNotContainsString('sf-cc-policy', $this->page(), 'a draft page leaves no link');
        $this->assertFalse($this->settings()->hasPolicyLink());

        // republished: the link comes back (the picked page is still the policy page)
        $about->refresh()->update(['status' => 'published']);
        $this->assertStringContainsString('href="/info/about-us"', $this->page());

        // deleted
        $about->refresh()->delete();
        $this->assertStringNotContainsString('sf-cc-policy', $this->page(), 'a deleted page leaves no link');
        $this->assertStringContainsString('data-sf-cc', $this->page(), 'the banner itself stays');
    }

    /** the visible warning follows the picked page: shown with none or with a page that is no longer published */
    public function test_the_admin_warns_while_no_published_policy_page_is_picked(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CookieSettingsPage::class)->assertSee(__('cookies_admin.warning_no_page'));

        $this->save(['page' => $this->aboutId()]);
        Livewire::test(CookieSettingsPage::class)->assertDontSee(__('cookies_admin.warning_no_page'));

        PageModel::query()->findOrFail($this->aboutId())->update(['status' => 'draft']);
        Livewire::test(CookieSettingsPage::class)->assertSee(__('cookies_admin.warning_no_page'));
    }

    // ---- the preview ------------------------------------------------------------------------------------------------------------------

    /** the preview is a signed, inert page: the real partial, the draft values, no script, no cookie, never indexed or cached */
    public function test_the_preview_is_signed_inert_and_never_writes_a_cookie(): void
    {
        $url = \App\Http\Controllers\Storefront\CookiePreviewController::previewUrl([
            'position' => 'bottom', 'view' => 'summary', 'color' => '#1d4ed8', 'page' => null, 'layer' => 'details',
            'texts' => ['bg' => ['title' => 'Чернова заглавие']],
        ]);

        $response = $this->get($url)->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Чернова заглавие', $html);
        $this->assertStringContainsString('sf-cc sf-cc-bottom', $html);
        $this->assertStringNotContainsString('<script', $html, 'no script at all');
        $this->assertStringNotContainsString('data-sf-cc-bar', $html, 'no footer link in the preview');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertMatchesRegularExpression('#/storage/appearance/cookie-preview-[0-9a-f]{12}\.css#', $html);

        $this->get('/cookie-preview?t='.str_repeat('a', 40))->assertNotFound();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=bad', $url))->assertNotFound();
    }
}
