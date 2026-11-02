<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use App\Storefront\Reader\ImageSetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * SIZES-1: the card images of the CATEGORY grid print the `sizes` of the PUBLISHED column counts (the draft never reaches the shop; the signed preview shows it); the home page keeps its own value. The
 * formula itself is tests/Unit/Storefront/CardSizesFormulaTest.
 */
class CategoryCardSizesTest extends TestCase
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

    private function publish(array $values): void
    {
        [$clean, $errors] = PageDesign::validate(PageDesign::CATALOG, $values + PageDesign::defaults(PageDesign::CATALOG), false);
        $this->assertSame([], $errors);
        PageDesignStore::publish(PageDesign::CATALOG, $clean);
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

    public function test_the_default_page_prints_the_four_and_two_column_value(): void
    {
        $this->assertSame([ImageSetBuilder::cardSizes(4, 2)], $this->sizesOf($this->page('/product-category/clothing')));
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function columns(): array
    {
        return ['3 columns' => ['3', '2', 3, 2], '4 columns' => ['4', '2', 4, 2], '5 columns' => ['5', '2', 5, 2], '5 columns, 1 on a phone' => ['5', '1', 5, 1], '3 columns, 1 on a phone' => ['3', '1', 3, 1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('columns')]
    public function test_a_category_published_with_n_columns_prints_the_sizes_of_n_columns(string $cols, string $phone, int $expectedCols, int $expectedPhone): void
    {
        $this->publish(['cols' => $cols, 'cols_phone' => $phone]);

        $sizes = $this->sizesOf($this->page('/product-category/clothing'));

        $this->assertSame([ImageSetBuilder::cardSizes($expectedCols, $expectedPhone)], $sizes);
        $this->assertStringContainsString("(min-width: 64rem) calc((100vw - {$expectedCols}rem) / {$expectedCols})", $sizes[0]);
    }

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $this->publish(['cols' => '4']);
        [$draft] = PageDesign::validate(PageDesign::CATALOG, ['cols' => '5'] + PageDesign::defaults(PageDesign::CATALOG), false);
        PageDesignStore::saveDraft(PageDesign::CATALOG, $draft);

        $this->assertSame([ImageSetBuilder::cardSizes(4, 2)], $this->sizesOf($this->page('/product-category/clothing')));
        $this->assertSame([ImageSetBuilder::cardSizes(5, 2)], $this->sizesOf($this->page(DesignPreviewController::previewUrl(PageDesign::CATALOG))), 'the preview carries the working copy');
    }

    public function test_the_home_page_keeps_its_own_value(): void
    {
        $this->publish(['cols' => '5']);

        $this->assertSame([ImageSetBuilder::SIZES_CARD], $this->sizesOf($this->page('/')));
    }

    public function test_the_page_has_no_style_attribute_no_inline_script_and_is_the_same_for_every_visitor(): void
    {
        $this->publish(['cols' => '5', 'cols_phone' => '1']);
        $html = $this->page('/product-category/clothing');

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame($html, $this->page('/product-category/clothing'));
    }
}
