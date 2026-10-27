<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * SC-1, test 1 (byte-identity): the product page of four products, rendered with NO stored layout for the right column, is exactly the page the shop printed BEFORE the right column became a list of
 * components. The four fixtures `tests/Fixtures/storefront/product-summary-default-{1..4}.html` were captured from the unchanged code (a simple product in stock with brand, short description and
 * price; a variable product; an out-of-stock product; a product with no price, brand and short description). Nothing is normalised: the pages are sessionless and carry no per-run value.
 */
class ProductSummaryBaselineTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->showcase();
        $this->app->forgetScopedInstances();
    }

    private function showcase(): void
    {
        $acme = $this->makeBrand(1, 'Acme', 'acme');
        $this->makeAttribute(1, 'Size', ['S', 'M']);

        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $acme, 'price' => '100.00', 'sale' => '79.90', 'stock' => 4, 'short' => 'Light and airy', 'description' => '<p>Soft <strong>cotton</strong></p>']);
        $this->makeImage(1, 1, 0, alt: 'Front view');
        $this->makeProduct(2, [
            'type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'brand' => $acme,
            'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]],
        ]);
        $this->makeProduct(3, ['name' => 'Sold-out scarf', 'slug' => 'sold-out-scarf', 'price' => '15.00', 'stock' => 0, 'short' => 'Gone for now']);
        $this->makeProduct(4, ['name' => 'Plain thing', 'slug' => 'plain-thing', 'stock' => 2]);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function pages(): array
    {
        return [
            'simple, in stock, brand + short description + price' => [1, 'summer-dress'],
            'variable product with variations' => [2, 'linen-shirt'],
            'out of stock' => [3, 'sold-out-scarf'],
            'no price, brand or short description' => [4, 'plain-thing'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_default_layout_prints_the_page_the_shop_always_printed(int $n, string $slug): void
    {
        $html = $this->get('/product/'.$slug)->assertOk()->getContent();
        $path = base_path("tests/Fixtures/storefront/product-summary-default-{$n}.html");

        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($path), $html, "product-summary-default-{$n}.html");
    }
}
