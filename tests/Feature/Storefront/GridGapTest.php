<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Storefront\Reader\ImageSetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * GAP-1: the category listing's card `sizes` follow the PUBLISHED horizontal gap of «Страници и карти» (the draft never reaches the shop; old data and a bad stored value are the default 16); the page keeps no
 * style attribute and no inline script, and the home page keeps its own `sizes`. The gap values themselves (closed list, the variables) are tests/Unit/Storefront/PageDesign/GridGapDesignTest.
 */
class GridGapTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

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

        for ($i = 1; $i <= 3; $i++) {
            $this->makeProduct($i, ['name' => "P{$i}", 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i)]);
            $this->makeImage($i, $i);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function publish(array $values): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::PAGE, $values + PageDesign::defaults(PageDesign::PAGE), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::PAGE, $clean);
    }

    /** A stored row exactly as written (old data, a hand edit): no validation on the way in. */
    private function storeRaw(string $json): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::PAGE_DESIGN_KEY, $json);
        app(StorefrontAppearance::class)->refresh();
    }

    private function page(string $path): string
    {
        $this->app->forgetScopedInstances();

        return $this->get($path)->assertOk()->getContent();
    }

    /** @return list<string> the distinct `sizes` values of the card images */
    private function sizesOf(string $html): array
    {
        preg_match_all('/<img class="sf-image[^"]*"[^>]*?\ssizes="([^"]*)"/s', $html, $m);

        return array_values(array_unique(array_map('html_entity_decode', $m[1])));
    }

    public function test_a_shop_that_never_set_the_gap_prints_todays_sizes(): void
    {
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 16)], $this->sizesOf($this->page('/product-category/clothing')));
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2)], $this->sizesOf($this->page('/product-category/clothing')));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function gaps(): array
    {
        return ['0' => ['0', 0], '8' => ['8', 8], '24' => ['24', 24], '32' => ['32', 32]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gaps')]
    public function test_the_published_horizontal_gap_reaches_the_sizes(string $gap, int $expected): void
    {
        $this->publish(['gapX' => $gap]);

        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, $expected)], $this->sizesOf($this->page('/product-category/clothing')));
    }

    public function test_the_vertical_gap_does_not_change_the_sizes(): void
    {
        $this->publish(['gapY' => '0']);

        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 16)], $this->sizesOf($this->page('/product-category/clothing')));
    }

    public function test_a_draft_gap_never_reaches_the_shop(): void
    {
        [$draft] = PageDesign::validate(PageDesign::PAGE, ['gapX' => '0'] + PageDesign::defaults(PageDesign::PAGE), false);
        PageDesignStore::saveDraft(PageDesign::PAGE, $draft);

        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 16)], $this->sizesOf($this->page('/product-category/clothing')));
    }

    public function test_old_data_without_the_keys_and_a_hand_edited_bad_value_read_as_16_and_the_other_gap_is_kept(): void
    {
        $this->storeRaw('{"card_radius":"12"}');
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 16)], $this->sizesOf($this->page('/product-category/clothing')), 'old data');

        $this->storeRaw('{"gapX":"17","gapY":"8"}');
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 16)], $this->sizesOf($this->page('/product-category/clothing')), 'a bad gapX alone is 16');

        $this->storeRaw('{"gapX":"8","gapY":["4"]}');
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2, 8)], $this->sizesOf($this->page('/product-category/clothing')), 'a good gapX stays when the other one is bad');
    }

    public function test_the_home_page_keeps_its_own_sizes_and_gap(): void
    {
        $this->publish(['gapX' => '0']);

        $this->assertSame([ImageSetBuilder::SIZES_CARD], $this->sizesOf($this->page('/')));
    }

    public function test_the_page_has_no_style_attribute_no_inline_script_and_is_the_same_for_every_visitor(): void
    {
        $this->publish(['gapX' => '0', 'gapY' => '0']);
        $html = $this->page('/product-category/clothing');

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame($html, $this->page('/product-category/clothing'));
    }
}
