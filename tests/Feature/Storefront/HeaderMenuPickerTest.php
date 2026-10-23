<?php

namespace Tests\Feature\Storefront;

use App\Content\Menus\MenuException;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuWriter;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * DS-2a.1: the header `menu` element can name a menu. `menu` = the id of a menu, null = the menu of the element's PLACE (as before); a deleted, unknown or empty chosen menu falls back to the
 * place's menu; the chosen menu is found among the compiled menus through `menu_keys` of the compiled header (no new query); a menu a header names cannot be deleted.
 */
class HeaderMenuPickerTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private int $promo;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Homepage link', 'url' => '/'])]);
        $this->promo = (int) MenuModel::create(['key' => 'promo', 'name' => 'Promo menu'])->id;
        $this->saveMenu('promo', [$this->menuItem('link', ['label' => 'Sale link', 'url' => '/sale'])]);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    /** The default layout with the desktop main row's menu element and the drawer's menu element given a menu (null = the place's). */
    private function layout(?int $desktop, ?int $drawer = null): array
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings']['menu'] = $desktop;
        $layout['mobile']['drawer'][0]['settings']['menu'] = $drawer;

        return $layout;
    }

    private function publish(array $layout): void
    {
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    /** The markup of the header bar (the desktop layout), without the drawer. */
    private function bar(string $html): string
    {
        preg_match('#sf-hdr-layout sf-hdr-desktop.*?sf-hdr-layout sf-hdr-mobile#s', $html, $m);

        return $m[0] ?? '';
    }

    // ---- the element ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_element_has_the_menu_first_with_null_as_default_and_a_style(): void
    {
        $schema = \App\Storefront\Header\HeaderCatalog::all()['menu']['schema'];

        $this->assertSame(['menu', 'style'], array_keys($schema));
        $this->assertSame(['kind' => 'menu', 'default' => null], $schema['menu']);
        $this->assertSame(['menu' => null], array_diff_key(\App\Storefront\Header\HeaderCatalog::defaultsFor('menu'), ['style' => 1]));
        $this->assertSame(['menu' => null, 'style' => 'links'], HeaderLayout::default()['desktop']['rows']['main']['left'][0]['settings']);
    }

    public function test_the_validator_takes_an_id_or_null_and_refuses_what_the_footer_refuses(): void
    {
        $this->assertSame($this->promo, HeaderValidator::validate($this->layout($this->promo))->layout['desktop']['rows']['main']['left'][0]['settings']['menu']);
        $this->assertNull(HeaderValidator::validate($this->layout(null))->layout['desktop']['rows']['main']['left'][0]['settings']['menu']);
        $this->assertTrue(HeaderValidator::validate($this->layout(null))->passes());

        foreach (['abc', 0, -3, [1], 1.5, true] as $bad) {
            $layout = HeaderLayout::default();
            $layout['desktop']['rows']['main']['left'][0]['settings']['menu'] = $bad;
            $header = HeaderValidator::validate($layout)->errors;

            $footer = FooterLayout::default();
            $footer['upper']['rows'][0]['columns'][0]['blocks'][0] = ['type' => 'menu', 'settings' => ['menu' => $bad, 'heading' => '']];
            $footerErrors = FooterValidator::validate($footer)->errors;

            $this->assertArrayHasKey('desktop.rows.main.left.0.settings.menu', $header, json_encode($bad));
            $this->assertSame(array_values($footerErrors)[0] ?? null, $header['desktop.rows.main.left.0.settings.menu'], 'the same message the footer gives: '.json_encode($bad));
        }
    }

    public function test_a_menu_style_mega_is_still_accepted_in_a_stored_layout(): void
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings']['style'] = 'mega';

        $this->assertTrue(HeaderValidator::validate($layout)->passes());
        $this->assertSame(['links', 'dropdown'], \App\Storefront\Header\HeaderCatalog::all()['menu']['schema']['style']['offer']);
    }

    // ---- compile and render --------------------------------------------------------------------------------------------------------------------

    public function test_a_layout_that_names_no_menu_has_no_menu_keys_and_one_that_does_gets_them(): void
    {
        $this->assertArrayNotHasKey('menu_keys', HeaderCompiler::compiled($this->layout(null))['layout']);
        $this->assertSame([(string) $this->promo => 'promo'], HeaderCompiler::compiled($this->layout($this->promo, $this->promo))['layout']['menu_keys']);
        $this->assertSame(['menu_keys' => 1], array_intersect_key(['menu_keys' => 1], HeaderCompiler::withMenuKeys($this->layout($this->promo))));
    }

    public function test_a_hand_edited_menu_keys_is_refused_and_the_default_header_is_shown(): void
    {
        $layout = HeaderValidator::validate($this->layout($this->promo))->layout;
        $layout['menu_keys'] = ['12' => '<script>'];

        $this->assertFalse(HeaderValidator::validate($layout)->passes());

        $layout['menu_keys'] = ['x' => 'promo'];
        $this->assertFalse(HeaderValidator::validate($layout)->passes());
    }

    public function test_the_explicit_menu_is_shown_instead_of_the_places_menu(): void
    {
        $this->publish($this->layout($this->promo));
        $bar = $this->bar($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('Sale link', $bar);
        $this->assertStringNotContainsString('Homepage link', $bar);
    }

    public function test_null_shows_the_places_menu(): void
    {
        $this->publish($this->layout(null));
        $bar = $this->bar($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('Homepage link', $bar);
        $this->assertStringNotContainsString('Sale link', $bar);
    }

    public function test_the_drawers_menu_element_takes_a_menu_too(): void
    {
        $this->publish($this->layout(null, $this->promo));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Homepage link', $this->bar($html));
        $this->assertStringContainsString('sf-hdr-menu-drawer', $html);
        $this->assertStringContainsString('Sale link', $html, 'the drawer prints the chosen menu');
        $this->assertStringNotContainsString('Sale link', $this->bar($html), 'the bar still shows the place\'s menu');
    }

    public function test_an_empty_or_unknown_chosen_menu_falls_back_to_the_places_menu(): void
    {
        // empty: the menu exists but has no items
        $this->publish($this->layout($this->promo));
        $this->saveMenu('promo', []);
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Homepage link', $this->bar($html), 'an empty chosen menu shows the place\'s menu');
        $this->assertStringNotContainsString('Sale link', $html);

        // unknown: an id nobody has (no key in menu_keys)
        $this->publish($this->layout(987654));
        $this->assertStringContainsString('Homepage link', $this->bar($this->get('/')->assertOk()->getContent()));
    }

    public function test_the_chosen_menu_follows_its_own_edits_without_publishing_the_header_again(): void
    {
        $this->publish($this->layout($this->promo));
        $this->saveMenu('promo', [$this->menuItem('link', ['label' => 'Brand new link', 'url' => '/new'])]);
        $this->fresh();

        $this->assertStringContainsString('Brand new link', $this->bar($this->get('/')->assertOk()->getContent()));
    }

    public function test_choosing_a_menu_costs_no_query(): void
    {
        $count = function (): int {
            $this->fresh();
            $this->get('/')->assertOk();
            $this->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->publish($this->layout(null, null));
        $without = $count();
        $this->publish($this->layout($this->promo, $this->promo));
        $with = $count();

        $this->assertSame($without, $with, 'the chosen menu rides the one appearance read');
    }

    public function test_the_preview_of_a_draft_shows_the_chosen_menu(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layout($this->promo))->layout));
        $this->fresh();

        $html = $this->get(HeaderPreviewController::previewUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Sale link', $this->bar($html));
        $this->fresh();
        $this->assertStringNotContainsString('Sale link', $this->bar($this->get('/')->getContent()), 'the public page never shows the draft');
    }

    // ---- the Menus editor's delete check ------------------------------------------------------------------------------------------------------

    public function test_a_menu_the_published_header_names_cannot_be_deleted_and_the_message_says_header(): void
    {
        $this->publish($this->layout($this->promo));
        $menu = MenuModel::query()->findOrFail($this->promo);

        try {
            app(MenuWriter::class)->delete($menu);
            $this->fail('deleted');
        } catch (MenuException $e) {
            $this->assertStringContainsString('Header', $e->getMessage());
            $this->assertStringContainsString('Promo menu', $e->getMessage());
        }

        $this->assertNotNull(MenuModel::query()->find($this->promo));

        $this->publish($this->layout(null));
        app(MenuWriter::class)->delete($menu->refresh());
        $this->assertNull(MenuModel::query()->find($this->promo));
    }

    public function test_a_menu_only_the_draft_names_cannot_be_deleted_either(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layout(null, $this->promo))->layout));
        $menu = MenuModel::query()->findOrFail($this->promo);

        $this->expectException(MenuException::class);
        app(MenuWriter::class)->delete($menu);
    }

    public function test_a_menu_no_header_names_can_still_be_deleted(): void
    {
        $this->publish($this->layout(null));
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layout(null))->layout));

        app(MenuWriter::class)->delete(MenuModel::query()->findOrFail($this->promo));

        $this->assertNull(MenuModel::query()->find($this->promo));
    }
}
