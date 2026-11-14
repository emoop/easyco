<?php

namespace Tests\Feature\Storefront\Search;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * SRCH-1 C: the header's search element (icon -> field under the header), the phone drawer's search row with its own switch, and the placeholder setting.
 * The operator switch (`storefront.search.enabled`) is what makes search exist; OFF it prints exactly what the header printed before.
 */
class SearchHeaderTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop', 'storefront.search.enabled' => true]);
        app()->setLocale('en');
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Home', 'url' => '/'])]);
    }

    /** @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change */
    private function home(?callable $change = null): string
    {
        $layout = HeaderLayout::default();
        $layout = $change ? $change($layout) : $layout;
        app(HeaderCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    // ---- the desktop element -------------------------------------------------------------------------------------------------------

    public function test_the_icon_button_is_accessible_and_opens_a_labelled_search_form(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('<button class="sf-hdr-icon-btn" type="button" aria-controls="sf-search-row" aria-expanded="false" data-sf-search-toggle>', $html);
        $this->assertStringContainsString('<span class="sf-sr-only">Search</span>', $html, 'the accessible name');
        $this->assertSame(1, substr_count($html, 'id="sf-search-row"'));
        $this->assertStringContainsString('<form class="sf-search-row" id="sf-search-row" role="search" method="get" action="/search" data-sf-search-row hidden>', $html);
        $this->assertStringContainsString('<label class="sf-sr-only" for="sf-search-row-input">Search</label>', $html);
        $this->assertStringContainsString('<input class="sf-search-input" id="sf-search-row-input" name="q" type="search"', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function test_the_field_mode_prints_the_form_in_place(): void
    {
        $html = $this->home(function (array $l): array {
            $l['desktop']['rows']['main']['right'][0]['settings']['mode'] = 'field';

            return $l;
        });

        $this->assertStringContainsString('<form class="sf-search-field" role="search" method="get" action="/search">', $html);
    }

    public function test_the_placeholder_is_validated_stored_only_when_set_and_printed_everywhere(): void
    {
        $default = HeaderValidator::validate(HeaderLayout::default());
        $this->assertTrue($default->passes());
        $this->assertSame(['mode' => 'icon_row'], $default->layout['desktop']['rows']['main']['right'][0]['settings'], 'unset: nothing stored, the layout is what it was');

        $tooLong = HeaderLayout::default();
        $tooLong['desktop']['rows']['main']['right'][0]['settings']['placeholder'] = str_repeat('я', 61);
        $this->assertFalse(HeaderValidator::validate($tooLong)->passes(), 'at most 60 characters');

        $ok = HeaderLayout::default();
        $ok['desktop']['rows']['main']['right'][0]['settings']['placeholder'] = str_repeat('я', 60);
        $this->assertTrue(HeaderValidator::validate($ok)->passes());

        $html = $this->home(function (array $l): array {
            $l['desktop']['rows']['main']['right'][0]['settings']['placeholder'] = 'Find a <dress>';
            $l['mobile']['drawer_search'] = true;

            return $l;
        });

        $this->assertStringContainsString('placeholder="Find a &lt;dress&gt;"', $html);
        $this->assertStringNotContainsString('placeholder="Find a <dress>"', $html);
        $this->assertSame(2, substr_count($html, 'placeholder="Find a &lt;dress&gt;"'), 'the shared row and the drawer field');
    }

    // ---- the phone drawer row --------------------------------------------------------------------------------------------------------

    public function test_the_drawer_row_is_the_first_one_when_its_switch_is_on(): void
    {
        $html = $this->home(function (array $l): array {
            $l['mobile']['drawer_search'] = true;

            return $l;
        });
        $drawer = substr($html, (int) strpos($html, 'class="sf-drawer '));

        $search = strpos($drawer, 'class="sf-drawer-search"');
        $menu = strpos($drawer, 'sf-hdr-menu-drawer');
        $this->assertNotFalse($search);
        $this->assertNotFalse($menu);
        $this->assertLessThan($menu, $search, 'above the menu');
        $this->assertLessThan(strpos($drawer, 'class="sf-drawer-body"'), $search, 'in the drawer head, before the body');
        $this->assertStringContainsString('role="search" method="get" action="/search"', $drawer);
    }

    public function test_the_drawer_row_is_off_by_default_and_without_the_operator_switch(): void
    {
        $off = $this->home();
        $this->assertStringNotContainsString('sf-drawer-search', $off, 'the layout switch is off');
        $this->assertStringContainsString('<p class="sf-drawer-title" id="sf-drawer-title">Menu</p>', $off, 'the title as before');

        config(['storefront.search.enabled' => false]);
        $none = $this->home(function (array $l): array {
            $l['mobile']['drawer_search'] = true;

            return $l;
        });
        $this->assertStringNotContainsString('sf-drawer-search', $none, 'the layout switch alone does nothing');
        $this->assertStringNotContainsString('data-sf-search', $none, 'and with search off the header has no search at all');
        $this->assertStringNotContainsString('sf-search-row', $none);
    }

    public function test_the_switch_is_stored_only_when_on_and_survives_the_design_screen_round_trip(): void
    {
        $this->assertArrayNotHasKey('drawer_search', HeaderValidator::validate(HeaderLayout::default())->layout['mobile'], 'absent = off: every old layout validates to what it did');

        $on = HeaderLayout::default();
        $on['mobile']['drawer_search'] = true;
        $stored = HeaderValidator::validate($on)->layout;

        $this->assertTrue($stored['mobile']['drawer_search']);
        $this->assertSame($stored, HeaderAdapter::fromTree(HeaderAdapter::toTree($stored)));

        $plain = HeaderValidator::validate(HeaderLayout::default())->layout;
        $this->assertSame($plain, HeaderAdapter::fromTree(HeaderAdapter::toTree($plain)), 'and off stays off, still absent');
    }
}
