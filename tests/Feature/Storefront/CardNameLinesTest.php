<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DRIBNI-4: the setting «Име на картата» of the «Каталог» zone (`cardName`: 1 | 2, default 1). One line is the default and writes NO class (the card's markup is what it always was); two lines write
 * `sf-card-name-2` on the name. The FULL name is in the HTML either way (the cut is CSS). The class is the same on the listing, the home product grid and the product carousel; a bad or missing stored value is
 * one line (that value alone); a page costs the same number of queries with either choice.
 */
class CardNameLinesTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const LONG = 'A very long product name that certainly does not fit on one line of a narrow card at all';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');

        foreach ([1 => 'P1', 2 => 'P2', 3 => self::LONG] as $i => $name) {
            $this->makeProduct($i, ['name' => $name, 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i)]);
        }
    }

    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    private function publishCard(string $value): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['cardName' => $value] + PageDesign::defaults(PageDesign::CATALOG), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);
    }

    private function storeRaw(string $json): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::CATALOG_DESIGN_KEY, $json);
        app(StorefrontAppearance::class)->refresh();
    }

    private function publishHome(): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => [
            ['type' => 'product_grid', 'settings' => []],
            ['type' => 'carousel', 'settings' => ['kind' => 'products']],
        ]]);
        $this->assertSame([], $errors);
        HomeDrafts::publish($clean);
    }

    /** @return array{0: int, 1: int} the cards' names with and without the two-line class */
    private function counts(string $html): array
    {
        return [substr_count($html, '<h3 class="sf-card-name sf-card-name-2">'), substr_count($html, '<h3 class="sf-card-name">')];
    }

    private function queries(string $url): int
    {
        $n = 0;
        $this->app->forgetScopedInstances();
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->get($url)->assertOk();

        return $n;
    }

    public function test_the_default_is_one_line_and_writes_no_class(): void
    {
        $html = $this->visit('/product-category/clothing')->assertOk()->getContent();

        $this->assertSame([0, 3], $this->counts($html));
        $this->assertStringNotContainsString('sf-card-name-2', $html);
    }

    public function test_two_lines_write_the_class_on_every_card(): void
    {
        $this->publishCard('2');

        $this->assertSame([3, 0], $this->counts($this->visit('/product-category/clothing')->getContent()));
    }

    public function test_the_full_name_is_in_the_html_for_both_choices(): void
    {
        foreach (['1', '2'] as $value) {
            $this->publishCard($value);
            $html = $this->visit('/product-category/clothing')->getContent();

            $this->assertStringContainsString('>'.self::LONG.'</h3>', $html, "choice {$value}: the whole name is the text");
            $this->assertStringContainsString('href="/product/p3"', $html, "choice {$value}: the link is the product's");
        }
    }

    public function test_a_bad_or_missing_stored_value_is_one_line_and_touches_nothing_else(): void
    {
        $this->storeRaw('{"cardName":"9"}');
        $this->assertSame([0, 3], $this->counts($this->visit('/product-category/clothing')->getContent()), 'a bad value');

        $this->storeRaw('{"cols":"5"}');
        $this->assertSame([0, 3], $this->counts($this->visit('/product-category/clothing')->getContent()), 'old data without the key');

        $this->storeRaw('{"layout":"sidebar","cardName":"x"}');
        $html = $this->visit('/product-category/clothing')->getContent();
        $this->assertSame([0, 3], $this->counts($html));
        $this->assertStringContainsString('sf-cat-layout-sidebar', $html, 'the bad card choice did not turn the side panel off');
    }

    public function test_the_admin_refuses_a_bad_value(): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, ['cardName' => '3'] + PageDesign::defaults(PageDesign::CATALOG), false);

        $this->assertArrayHasKey('cardName', $errors);
        $this->assertSame('1', $clean['cardName']);
    }

    public function test_the_home_grid_and_the_carousel_carry_the_same_class(): void
    {
        $this->publishHome();

        [$twoBefore, $oneBefore] = $this->counts($this->visit('/')->getContent());
        $this->assertSame(0, $twoBefore);
        $this->assertGreaterThan(0, $oneBefore);

        $this->publishCard('2');
        [$two, $one] = $this->counts($this->visit('/')->getContent());

        $this->assertSame(0, $one);
        $this->assertSame($oneBefore, $two, 'every card of the grid and of the carousel has the class');
    }

    public function test_the_choice_costs_no_query(): void
    {
        $this->publishHome();

        $oneListing = $this->queries('/product-category/clothing');
        $oneHome = $this->queries('/');

        $this->publishCard('2');

        $this->assertSame($oneListing, $this->queries('/product-category/clothing'), 'the listing');
        $this->assertSame($oneHome, $this->queries('/'), 'the home page');
    }
}
