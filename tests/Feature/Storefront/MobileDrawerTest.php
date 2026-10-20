<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * MENU-4: the phone drawer in the order of a modern shop's. Top bar: the search (the title "Menu" until the search exists) and the close button; the menu as rows (the name is a link,
 * the chevron a SEPARATE button); the account rows (each only when its route exists); then text / button / social. The uppercase choice is a class, never a style.
 */
class MobileDrawerTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $child = $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent, $child], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->saveMenu('main', [
            $this->menuItem('link', ['label' => 'Home', 'url' => '/']),
            $this->menuItem('category', ['target_id' => $parent, 'submenu' => 'dropdown', 'children' => [$this->menuItem('category', ['target_id' => $child])]]),
        ]);
    }

    /** @param  list<string>  $routes */
    private function routes(array $routes): void
    {
        foreach ($routes as $name) {
            Route::get('/'.$name, fn () => $name)->name('storefront.'.$name);
        }

        app('router')->getRoutes()->refreshNameLookups();
    }

    /** @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change */
    private function drawer(?callable $change = null): string
    {
        $layout = HeaderLayout::default();
        $layout = $change ? $change($layout) : $layout;
        app(HeaderCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $start = strpos($html, 'class="sf-drawer ');
        $this->assertNotFalse($start, 'the drawer is in the page');

        return substr($html, $start);
    }

    /** @param  list<string>  $needles */
    private function assertOrder(string $html, array $needles): void
    {
        $last = -1;

        foreach ($needles as $needle) {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, "{$needle} is in the drawer");
            $this->assertGreaterThan($last, $at, "{$needle} comes after the previous part");
            $last = $at;
        }
    }

    public function test_without_a_search_route_the_top_bar_shows_the_title_and_without_account_routes_there_are_no_account_rows(): void
    {
        $html = $this->drawer();

        $this->assertStringContainsString('<p class="sf-drawer-title" id="sf-drawer-title">Menu</p>', $html);
        $this->assertStringNotContainsString('sf-drawer-search', $html);
        $this->assertStringNotContainsString('sf-acc-account', $html, 'no routes, no rows');
        $this->assertStringNotContainsString('Wishlist', $html);
    }

    public function test_with_the_routes_the_order_is_search_menu_account_rows_then_the_rest(): void
    {
        $this->routes(['search', 'wishlist', 'account']);
        $html = $this->drawer(function (array $l): array {
            // the builder's order is deliberately the opposite one, with an old `account` element in the middle
            $l['mobile']['drawer'] = [
                ['type' => 'social', 'settings' => ['items' => [['network' => 'facebook', 'url' => 'https://example.com/fb']]]],
                ['type' => 'account', 'settings' => ['style' => 'icon']],
                ['type' => 'text', 'settings' => ['text' => 'Free delivery', 'url' => '']],
                ['type' => 'menu', 'settings' => ['style' => 'links']],
            ];

            return $l;
        });

        $this->assertOrder($html, ['class="sf-drawer-search"', 'class="sf-drawer-close"', 'sf-hdr-menu-drawer', 'sf-acc-account', 'href="/wishlist"', 'href="/account"', 'sf-hdr-social', 'Free delivery']);
        $this->assertSame(1, substr_count($html, 'href="/account"'), 'an account element of an old layout does not print a second time');
        $this->assertStringContainsString('placeholder="Search products…"', $html);
        $this->assertStringNotContainsString('<p class="sf-drawer-title"', $html);
        $this->assertStringContainsString('Wishlist', $html);
        $this->assertStringContainsString('Sign in', $html, 'nobody is signed in');
    }

    public function test_each_account_row_needs_its_own_route(): void
    {
        $this->routes(['account']);
        $html = $this->drawer();

        $this->assertStringContainsString('href="/account"', $html);
        $this->assertStringNotContainsString('Wishlist', $html);
        $this->assertStringNotContainsString('href="/wishlist"', $html);
    }

    public function test_the_name_is_a_link_and_the_chevron_a_separate_button(): void
    {
        $html = $this->drawer();

        $this->assertMatchesRegularExpression('#<div class="sf-acc-row">\s*<a class="sf-acc-link" href="/product-category/clothing">Clothing</a>\s*<button class="sf-acc-toggle" type="button" aria-expanded="false" aria-controls="sf-acc-drawer-0-1" data-sf-acc-toggle>#', $html);
        $this->assertStringContainsString('<div class="sf-acc-sub" id="sf-acc-drawer-0-1">', $html);
        $this->assertSame(1, substr_count($html, 'data-sf-acc-toggle'), 'only the parent has a chevron');
        $this->assertStringContainsString('<a class="sf-acc-link" href="/">Home</a>', $html, 'a row without children is a plain link');
    }

    public function test_uppercase_is_a_class_on_by_default_and_off_when_the_setting_says_so_and_never_a_style(): void
    {
        $this->assertTrue(HeaderValidator::validate(HeaderLayout::default())->layout['mobile']['drawer_upper']);
        $on = $this->drawer();
        $off = $this->drawer(function (array $l): array {
            $l['mobile']['drawer_upper'] = false;

            return $l;
        });

        $this->assertStringContainsString('class="sf-drawer sf-drawer-left sf-drawer-upper"', $on);
        $this->assertStringContainsString('class="sf-drawer sf-drawer-left"', $off);
        $this->assertStringNotContainsString('sf-drawer-upper', $off);
        $this->assertStringNotContainsString('style=', $on);
        $this->assertStringNotContainsString('style=', $off);
        $this->assertStringNotContainsString('text-transform', $on);
    }

    public function test_the_uppercase_setting_is_validated_like_the_other_booleans(): void
    {
        $layout = HeaderLayout::default();
        $layout['mobile']['drawer_upper'] = 'yes';

        $validation = HeaderValidator::validate($layout);

        $this->assertFalse($validation->passes());
        $this->assertArrayHasKey('mobile.drawer_upper', $validation->errors);
    }

    public function test_a_mega_item_is_stacked_sections_with_an_optional_small_title_in_the_drawer(): void
    {
        $this->saveMenu('main', [
            $this->menuItem('link', ['label' => 'Boys', 'url' => '/boys', 'submenu' => 'mega', 'mega_columns' => 2, 'children' => [
                $this->columnItem([$this->menuItem('link', ['label' => 'Jackets', 'url' => '/jackets'])], 'Clothes'),
                $this->columnItem([$this->menuItem('link', ['label' => 'Boots', 'url' => '/boots'])]),
            ]]),
        ]);
        $html = $this->drawer();

        $this->assertSame(2, substr_count($html, 'class="sf-acc-section"'));
        $this->assertSame(1, substr_count($html, '<p class="sf-mega-heading">Clothes</p>'), 'a heading only where there is one');
        $this->assertStringNotContainsString('<img', $html, 'no picture on the phone');
    }

    public function test_the_drawer_rules_never_sit_in_a_desktop_media_block(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        preg_match_all('/@media \(min-width: (?:64rem|1024px)\) \{.*?\n\}\n/s', $css, $m);

        $this->assertNotEmpty($m[0], 'the desktop media blocks are found');
        $this->assertStringNotContainsString('sf-drawer', implode("\n", $m[0]));
        $this->assertStringNotContainsString('sf-acc', implode("\n", $m[0]));
    }
}
