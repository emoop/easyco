<?php

namespace Tests\Feature\Cookies;

use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuValidator;
use App\Filament\Pages\Settings\CookieSettingsPage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CookieSettings;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/**
 * CC-1: the menu item kind `consent` («Настройки за бисквитки»): validated, compiled only while the banner is on, resolved at read time (the banner's text, the policy page's URL or `#`), printed
 * with `data-consent-open` by every link renderer, and the fixed fallback strip / the admin's warning that depend on whether a menu a GUEST sees holds it.
 */
class CookieMenuItemTest extends TestCase
{
    use BuildsCacheShop;
    use BuildsMenus;
    use BuildsShippingZones;
    use RefreshDatabase;

    private const LINK = '~<a [^>]*href="%s"[^>]* data-consent-open[^>]*>\s*%s~u';

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->fresh();
    }

    private function consent(array $extra = []): array
    {
        return $this->menuItem('consent', $extra);
    }

    /** @return array<string, mixed> */
    private function data(array $override = []): array
    {
        return $override + [
            'enabled' => true, 'position' => 'center', 'view' => 'summary', 'months' => 12, 'page' => null, 'color' => '#111111',
            'texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')],
        ];
    }

    private function banner(array $override = []): void
    {
        app(CookieSettings::class)->save($this->data($override));
        $this->fresh();
    }

    private function aboutId(): int
    {
        return (int) DB::table('pages')->where('slug', 'about')->value('id');
    }

    private function html(): string
    {
        Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);
        $this->fresh();

        return (string) $this->get('/')->assertOk()->getContent();
    }

    private function label(): string
    {
        return preg_quote(CookieSettings::defaults('bg')['footer_link'], '#');
    }

    /** The main menu holds these items and sits at the header AND the drawer (the seed leaves the drawer empty); the published default header shows it. @param  list<array<string, mixed>>  $items */
    private function saveMain(array $items): void
    {
        $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
        app(\App\Content\Menus\MenuWriter::class)->save($menu, $menu->name, $items, ['header', 'drawer']);
        app(\App\Storefront\Header\HeaderCompiler::class)->publish(\App\Storefront\Header\HeaderLayout::default(), app(SiteSettingsRepository::class));
        $this->fresh();
    }

    private function footerBlockMenu(?int $menuId = null): void
    {
        $layout = FooterLayout::default();
        $settings = ['menu' => $menuId] + FooterCatalog::defaultsFor('menu');
        $layout['upper']['rows'] = [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'menu', 'settings' => $settings]]]]]];
        app(FooterCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->fresh();
    }

    // ---- the kind is validated ---------------------------------------------------------------------------------------------------

    /** ★ allowed where a link may stand; never a column's child of a mega, never with children; nothing typed survives */
    public function test_the_kind_is_validated(): void
    {
        $this->assertSame([], MenuValidator::validate([$this->consent()])->errors, 'a top-level item (the footer menu block prints those)');

        $nested = MenuValidator::validate([$this->menuItem('link', ['label' => 'Help', 'url' => '/help', 'submenu' => 'dropdown', 'children' => [$this->consent(), $this->menuItem('link', ['label' => 'Sub', 'url' => '/s', 'children' => [$this->consent()]])]])]);
        $this->assertSame([], $nested->errors, 'in a dropdown and one level deeper');

        $mega = MenuValidator::validate([$this->megaItem('Shop', '/shop', 2, [$this->columnItem([$this->consent()])])]);
        $this->assertSame([], $mega->errors, 'in a mega column');

        $direct = MenuValidator::validate([$this->menuItem('link', ['label' => 'Shop', 'url' => '/shop', 'submenu' => 'mega', 'mega_columns' => 1, 'children' => [$this->consent()]])]);
        $this->assertNotSame([], $direct->errors, 'a mega item holds columns only');

        $this->assertNotSame([], MenuValidator::validate([$this->consent(['children' => [$this->consent()]])])->errors, 'a consent item has no children');
        $this->assertNotSame([], MenuValidator::validate([$this->consent(['target_id' => 3])])->errors, 'no target');
        $this->assertNotSame([], MenuValidator::validate([$this->menuItem('cookie')])->errors, 'an unknown kind');
        $this->assertNotSame([], MenuValidator::validate([$this->menuItem('consents')])->errors);

        // what the merchant typed is dropped: the text and the address are the banner's
        $item = MenuValidator::validate([$this->consent(['label' => 'My text', 'url' => 'https://evil.example', 'badge_text' => 'NEW', 'badge_color' => 'red', 'new_tab' => true, 'submenu' => 'dropdown'])]);
        $this->assertSame([], $item->errors);
        $this->assertNull($item->items[0]['label']);
        $this->assertNull($item->items[0]['url']);
        $this->assertNull($item->items[0]['badge_text']);
        $this->assertFalse($item->items[0]['new_tab']);
        $this->assertSame('none', $item->items[0]['submenu']);
    }

    /** the database refuses what the validator refuses: the CHECK names the new kind and no other */
    public function test_the_database_check_accepts_the_kind_and_refuses_an_unknown_one(): void
    {
        $menu = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $row = ['menu_id' => $menu, 'sort' => 0, 'submenu' => 'none', 'created_at' => now(), 'updated_at' => now()];

        DB::table('menu_items')->insert($row + ['type' => 'consent']);
        $this->assertSame(1, DB::table('menu_items')->where('type', 'consent')->count());

        $this->expectException(QueryException::class);
        DB::table('menu_items')->insert($row + ['type' => 'bogus']);
    }

    // ---- the compile step ---------------------------------------------------------------------------------------------------------

    /** ★ the compiled menus hold the item only while the banner is on; a flip of the switch rebuilds them */
    public function test_compile_skips_the_item_while_the_banner_is_off_and_a_flip_rebuilds(): void
    {
        $compiled = static fn (): string => (string) app(SiteSettingsRepository::class)->get(AppearanceSettings::MENUS_COMPILED_KEY);

        $this->saveMenu('footer', [$this->consent()]);
        $this->assertStringNotContainsString('"consent"', $compiled(), 'banner off: not compiled');

        $this->banner();
        $this->assertStringContainsString('"consent":true', $compiled(), 'switched on: the menus are compiled again with the item');

        $this->banner(['enabled' => false]);
        $this->assertStringNotContainsString('"consent"', $compiled(), 'switched off: gone again');
    }

    /** ★ and a stale compiled copy never prints it while the banner is off */
    public function test_a_stale_compiled_item_is_dropped_at_read_time_while_the_banner_is_off(): void
    {
        $this->banner();
        $this->saveMenu('footer', [$this->consent()]);
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '#', $this->label()), $this->html());

        // the banner goes off WITHOUT the menus being rebuilt (a hand edit): the read drops the item
        app(SiteSettingsRepository::class)->set(CookieSettings::ENABLED, '0');
        $html = $this->html();
        $this->assertStringNotContainsString('data-consent-open', $html);
        $this->assertStringNotContainsString(CookieSettings::defaults('bg')['footer_link'], $html);
    }

    // ---- all five renderers ---------------------------------------------------------------------------------------------------------

    /** ★ the old footer (no footer published): the footer location's menu */
    public function test_the_old_footer_prints_it(): void
    {
        $this->banner();
        $this->saveMenu('footer', [$this->consent()]);

        $this->assertMatchesRegularExpression(sprintf(self::LINK, '#', $this->label()), $this->html());
    }

    /** ★ the footer `menu` block (top-level links of the chosen menu) */
    public function test_the_footer_menu_block_prints_it(): void
    {
        $this->banner(['page' => $this->aboutId()]);
        $id = (int) MenuModel::query()->where('key', 'footer')->value('id');
        $this->saveMenu('footer', [$this->consent()]);
        $this->footerBlockMenu($id);
        $html = $this->html();

        $this->assertStringContainsString('sf-ft-menu', $html);
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '/info/about', $this->label()), $html);
    }

    /** ★ the header menu: a top-level item, a dropdown child and a mega column's link (partials/menu) */
    public function test_the_header_menu_prints_it_at_every_level(): void
    {
        $this->banner(['page' => $this->aboutId()]);
        $this->saveMain([
            $this->consent(),
            $this->menuItem('link', ['label' => 'Help', 'url' => '/help', 'submenu' => 'dropdown', 'children' => [$this->consent()]]),
            $this->megaItem('Shop', '/shop', 1, [$this->columnItem([$this->consent()])]),
        ]);
        $html = $this->html();

        $this->assertGreaterThanOrEqual(3, preg_match_all(sprintf(self::LINK, '/info/about', $this->label()), $html), 'top level, dropdown and mega column in the header');
        $this->assertStringContainsString('sf-menu-link', $html);
    }

    /** ★ the drawer (the accordion): a top-level item and a sub-item, two anchors of partials/menu-accordion */
    public function test_the_drawer_accordion_prints_it(): void
    {
        $this->banner(['page' => $this->aboutId()]);
        $this->saveMain([
            $this->consent(),
            $this->menuItem('link', ['label' => 'Help', 'url' => '/help', 'submenu' => 'dropdown', 'children' => [$this->consent()]]),
        ]);
        $html = $this->html();

        $this->assertSame(1, preg_match_all('#<a class="sf-acc-link"[^>]*href="/info/about"[^>]* data-consent-open#u', $html), 'the accordion anchor of a top-level item (a leaf)');
        $this->assertStringContainsString('sf-acc-sub', $html, 'the drawer has its dropdown');
    }

    // ---- the address ----------------------------------------------------------------------------------------------------------------

    /** ★ the policy page's URL; `#` with none, and `#` again when that page is unpublished or deleted (never a dead link) */
    public function test_href_is_the_policy_url_or_a_hash_and_never_dead(): void
    {
        $this->saveMenu('footer', [$this->consent()]);
        $this->banner();
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '#', $this->label()), $this->html(), 'no policy page: #');

        $this->banner(['page' => $this->aboutId()]);
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '/info/about', $this->label()), $this->html());

        $about = \App\Content\Models\PageModel::query()->findOrFail($this->aboutId());
        $about->update(['status' => 'draft']);
        $html = $this->html();
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '#', $this->label()), $html, 'an unpublished policy page falls back to #');
        $this->assertStringNotContainsString('href="/info/about"', $html);

        $about->refresh()->update(['status' => 'published']);
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '/info/about', $this->label()), $this->html());

        $about->refresh()->delete();
        $this->assertMatchesRegularExpression(sprintf(self::LINK, '#', $this->label()), $this->html(), 'a deleted policy page falls back to #');
    }

    /** the label is the banner's own «footer link» text of the shop's language */
    public function test_the_label_is_the_banner_text_of_the_shop_language(): void
    {
        $en = CookieSettings::defaults('en');
        $en['footer_link'] = 'Manage cookies';
        $this->saveMenu('footer', [$this->consent()]);
        $this->banner(['texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => $en]]);

        $this->assertStringContainsString('>'.CookieSettings::defaults('bg')['footer_link'], $this->html());

        app(SiteSettingsRepository::class)->set('site.locale', 'en');
        $this->assertMatchesRegularExpression('#<a [^>]*data-consent-open[^>]*>\s*Manage cookies#', $this->html());
    }

    // ---- the fallback strip ----------------------------------------------------------------------------------------------------------

    /** ★ no item in any guest-visible menu: the strip is printed */
    public function test_the_strip_is_printed_while_no_visible_menu_holds_the_item(): void
    {
        $this->banner();

        $this->assertStringContainsString('sf-cc-bar', $this->html());

        // an item in a menu that exists but is attached to no header, drawer or footer does not count
        $extra = app(\App\Content\Menus\MenuWriter::class)->create('spare', 'Spare');
        app(\App\Content\Menus\MenuWriter::class)->save($extra, 'Spare', [$this->consent()]);
        $this->fresh();
        $html = $this->html();
        $this->assertStringContainsString('sf-cc-bar', $html, 'an unattached menu does not count');
        $this->assertStringNotContainsString('data-consent-open', $html);
    }

    /** ★ once such an item is shown the strip is not printed (footer location, header, or a footer menu block) */
    public function test_the_strip_is_not_printed_once_a_visible_menu_holds_the_item(): void
    {
        $this->banner();

        // the footer location (the old footer)
        $this->saveMenu('footer', [$this->consent()]);
        $html = $this->html();
        $this->assertStringContainsString('data-consent-open', $html);
        $this->assertStringNotContainsString('sf-cc-bar', $html, 'the footer menu');
        $this->assertStringNotContainsString('data-sf-cc-bar', $html);

        // taken out again: the strip returns
        $this->saveMenu('footer', []);
        $this->assertStringContainsString('sf-cc-bar', $this->html());

        // the header / drawer menu
        $this->saveMain([$this->consent()]);
        $this->assertStringNotContainsString('sf-cc-bar', $this->html(), 'the header menu');
        $this->saveMain([]);

        // a footer block that shows a chosen menu
        $spare = app(\App\Content\Menus\MenuWriter::class)->create('spare2', 'Spare 2');
        app(\App\Content\Menus\MenuWriter::class)->save($spare, 'Spare 2', [$this->consent()]);
        $this->assertStringContainsString('sf-cc-bar', $this->html(), 'not yet attached');
        $this->footerBlockMenu((int) $spare->id);
        $html = $this->html();
        $this->assertStringContainsString('data-consent-open', $html);
        $this->assertStringNotContainsString('sf-cc-bar', $html, 'a footer menu block');
    }

    /** the banner script opens layer 2 on click through delegation (no inline handler on the link) */
    public function test_the_link_carries_no_inline_handler(): void
    {
        $this->banner();
        $this->saveMenu('footer', [$this->consent()]);
        $html = $this->html();

        $this->assertSame(0, preg_match('#<a [^>]*data-consent-open[^>]*\son[a-z]+=#i', $html));
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/storefront-consent.js');
        $this->assertStringContainsString("[data-consent-open]", $js);
        $this->assertStringContainsString("open('details', false)", $js);
    }

    // ---- the admin warning ----------------------------------------------------------------------------------------------------------

    /** ★ visible while the banner is on and no guest-visible menu has the item; hidden once one has it, and while the banner is off */
    public function test_the_admin_warns_while_the_fallback_strip_is_used(): void
    {
        $this->actingAsStaff('Administrator');
        $warning = __('cookies_admin.warning_fallback_strip');

        // banner off: nothing to warn about
        Livewire::test(CookieSettingsPage::class)->assertDontSee($warning);

        $this->banner();
        Livewire::test(CookieSettingsPage::class)->assertSee($warning);

        // an item in a menu that is attached to nothing: still the strip
        $extra = app(\App\Content\Menus\MenuWriter::class)->create('spare3', 'Spare 3');
        app(\App\Content\Menus\MenuWriter::class)->save($extra, 'Spare 3', [$this->consent()]);
        $this->fresh();
        Livewire::test(CookieSettingsPage::class)->assertSee($warning);

        // a footer menu holds it: the warning goes
        $this->saveMenu('footer', [$this->consent()]);
        Livewire::test(CookieSettingsPage::class)->assertDontSee($warning);

        // and comes back when it is taken out
        $this->saveMenu('footer', []);
        Livewire::test(CookieSettingsPage::class)->assertSee($warning);
    }
}
