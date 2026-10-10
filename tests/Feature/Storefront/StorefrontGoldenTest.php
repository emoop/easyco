<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden files: the EXACT toArray() of the read models, so a field can never appear (or disappear) unnoticed.
 * Fixtures use explicit ids, fixed timestamps and a fixed media URL, so the files are byte-stable.
 *
 * To regenerate after an intended change: `UPDATE_GOLDEN=1 php artisan test tests/Feature/Storefront/StorefrontGoldenTest.php`
 * and REVIEW the diff of tests/Fixtures/storefront/*.json: a changed key is a change of the customer-facing contract.
 */
class StorefrontGoldenTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->showcase();
        $this->app->forgetScopedInstances();
    }

    private function showcase(): void
    {
        $acme = $this->makeBrand(1, 'Acme', 'acme');
        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $dresses = $this->makeCategory(2, 'Dresses', 'dresses', $clothing);
        $this->makeAttribute(1, 'Size', ['S', 'M']);

        $this->makeProduct(1, [
            'name' => 'Summer dress', 'slug' => 'summer-dress', 'brand' => $acme, 'categories' => [$dresses],
            'timeline' => '2026-03-01 10:00:00', 'price' => '100.00', 'sale' => '79.90', 'stock' => 4,
            'short' => 'Light and airy', 'description' => '<p>Soft <strong>cotton</strong></p>',
        ]);
        $this->makeImage(1, 1, 0, alt: 'Front view');
        $this->makeImage(2, 1, 1);

        $this->makeProduct(2, [
            'type' => 'variable', 'name' => 'Linen shirt', 'slug' => 'linen-shirt', 'brand' => $acme, 'categories' => [$clothing],
            'timeline' => '2026-02-01 10:00:00',
            'variations' => [
                ['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 0],
                ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3],
            ],
        ]);
        $this->makeImage(3, 2, 0);

        $this->makeProduct(3, ['name' => 'Sold-out scarf', 'slug' => 'sold-out-scarf', 'timeline' => '2026-01-01 10:00:00', 'price' => '15.00', 'stock' => 0]);
    }

    private function assertGolden(string $name, array $actual): void
    {
        $path = base_path("tests/Fixtures/storefront/{$name}.json");
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($path, $json);
        }

        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($path), $json, "golden file {$name}.json");
    }

    public function test_product_card(): void
    {
        $card = $this->reader()->listing(ListingQuery::fromRequestArray([]))->items[0];

        $this->assertGolden('product_card', $card->toArray());
    }

    public function test_product_page_simple_and_variable(): void
    {
        $this->assertGolden('product_page_simple', $this->reader()->product('summer-dress')->toArray());
        $this->assertGolden('product_page_variable', $this->reader()->product('linen-shirt')->toArray());
    }

    public function test_category_page_and_tree(): void
    {
        $this->assertGolden('category_page', $this->reader()->category('wrong/dresses')->toArray());
        $this->assertGolden('category_tree', array_map(fn ($n) => $n->toArray(), $this->reader()->categoryTree()));
    }

    public function test_listing_page(): void
    {
        $this->assertGolden('listing_page', $this->reader()->listing(ListingQuery::fromRequestArray(['per_page' => '12']))->toArray());
    }

    private function reader(): CatalogReader
    {
        return app(CatalogReader::class);
    }
}
