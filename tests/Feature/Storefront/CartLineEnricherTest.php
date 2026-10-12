<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Cart\CartLineEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/** Part A of S3b: product_url and image on every cart line, from the ONE visibility rule and the read layer's image rules. */
class CartLineEnricherTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function enrich(string ...$variationIds): array
    {
        return app(CartLineEnricher::class)->enrich(array_values($variationIds));
    }

    /** An image owned by a VARIATION (makeImage() attaches to a product). */
    private function makeVariationImage(int $mediaId, int $variationId, int $sort = 0, string $status = 'ready', ?string $alt = null, string $type = 'image'): void
    {
        $this->makeImage($mediaId, 1, 99, $status, $alt, null, $type);
        DB::table('catalog_product_media')->where('media_id', $mediaId)->delete();
        DB::table('catalog_variation_media')->insert(['variation_id' => $variationId, 'media_id' => $mediaId, 'sort_order' => $sort]);
    }

    // ---- product_url ------------------------------------------------------------------------------------------

    public function test_the_url_is_present_for_a_visible_product_and_null_for_hidden_draft_archived_and_deleted(): void
    {
        $this->makeProduct(1, ['slug' => 'shown', 'price' => '10.00']);
        $this->makeProduct(2, ['slug' => 'hidden', 'visibility' => 'hidden', 'price' => '10.00']);
        $this->makeProduct(3, ['slug' => 'draft', 'status' => 'draft', 'price' => '10.00']);
        $this->makeProduct(4, ['slug' => 'archived', 'status' => 'archived', 'price' => '10.00']);
        $this->makeProduct(5, ['slug' => 'deleted', 'deleted' => true, 'price' => '10.00']);

        $result = $this->enrich('101', '201', '301', '401', '501');

        $this->assertSame('/product/shown', $result['101']['product_url']);
        foreach (['201', '301', '401', '501'] as $id) {
            $this->assertNull($result[$id]['product_url'], "variation {$id}");
        }
    }

    public function test_the_url_is_the_read_layers_percent_encoded_one(): void
    {
        $this->makeProduct(1, ['slug' => 'лятна-рокля', 'price' => '10.00']);

        $this->assertSame('/product/%D0%BB%D1%8F%D1%82%D0%BD%D0%B0-%D1%80%D0%BE%D0%BA%D0%BB%D1%8F', $this->enrich('101')['101']['product_url']);
    }

    // ---- image ------------------------------------------------------------------------------------------------

    public function test_the_image_is_the_variations_own_first_image_else_the_products_else_null(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $this->makeProduct(1, ['type' => 'variable', 'slug' => 'shirt', 'name' => 'Shirt', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '10.00'],   // 101: has its own image
            ['attrs' => [1 => 'M'], 'price' => '10.00'],   // 102: falls back to the product's
        ]]);
        $this->makeProduct(2, ['slug' => 'bare', 'price' => '10.00']);   // 201: no image anywhere

        $this->makeImage(10, 1, 0);                      // the product's first image
        $this->makeVariationImage(20, 101, 0);
        $this->makeVariationImage(21, 101, 1);

        $result = $this->enrich('101', '102', '201');

        $this->assertStringContainsString('p/20-thumbnail.webp', $result['101']['image']['src'], 'the variation\'s own first image');
        $this->assertStringContainsString('p/10-thumbnail.webp', $result['102']['image']['src'], 'the product\'s first image');
        $this->assertNull($result['201']['image']);
    }

    public function test_the_image_uses_the_thumbnail_rendition_and_never_the_admin_crop(): void
    {
        $this->makeProduct(1, ['price' => '10.00', 'name' => 'Thing']);
        $this->makeImage(10, 1);

        $image = $this->enrich('101')['101']['image'];

        $this->assertSame('https://cdn.test/storage/p/10-thumbnail.webp', $image['src']);
        $this->assertSame([400, 300], [$image['width'], $image['height']]);
        $this->assertSame(
            'https://cdn.test/storage/p/10-thumbnail.webp 400w, https://cdn.test/storage/p/10-medium.webp 900w, https://cdn.test/storage/p/10-large.webp 1600w',
            $image['srcset'],
        );
        $this->assertSame('96px', $image['sizes']);
        $this->assertStringNotContainsString('admin_grid', json_encode($image));
        $this->assertSame(['src', 'srcset', 'sizes', 'width', 'height', 'alt'], array_keys($image));
    }

    public function test_without_a_thumbnail_tier_the_default_rendition_stands_in(): void
    {
        $this->makeProduct(1, ['price' => '10.00']);
        $this->makeImage(10, 1, variants: [
            ['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 82, 'path' => 'p/10-medium.webp'],
            ['tier' => 'large', 'width' => 1600, 'height' => 1200, 'quality' => 85, 'path' => 'p/10-large.webp'],
        ]);

        $image = $this->enrich('101')['101']['image'];

        $this->assertSame('https://cdn.test/storage/p/10-medium.webp', $image['src']);
        $this->assertSame([900, 675], [$image['width'], $image['height']]);
    }

    public function test_only_ready_images_count_and_the_next_usable_one_is_taken(): void
    {
        $this->makeProduct(1, ['price' => '10.00']);
        $this->makeImage(10, 1, 0, status: 'processing');
        $this->makeImage(11, 1, 1, status: 'failed');
        $this->makeImage(12, 1, 2, type: 'video');
        $this->makeImage(13, 1, 3, variants: []);          // ready, but no usable variant
        $this->makeImage(14, 1, 4);

        $this->assertStringContainsString('p/14-thumbnail.webp', $this->enrich('101')['101']['image']['src']);

        $this->makeProduct(2, ['price' => '10.00']);
        $this->makeImage(20, 2, 0, status: 'processing');
        $this->assertNull($this->enrich('201')['201']['image']);
    }

    public function test_alt_is_the_assets_alt_text_else_the_product_name_raw(): void
    {
        $this->makeProduct(1, ['name' => 'Name <b>raw</b>', 'price' => '10.00']);
        $this->makeImage(10, 1);
        $this->makeProduct(2, ['name' => 'Other', 'price' => '10.00']);
        $this->makeImage(11, 2, alt: 'A photo');

        $result = $this->enrich('101', '201');

        $this->assertSame('Name <b>raw</b>', $result['101']['image']['alt']);
        $this->assertSame('A photo', $result['201']['image']['alt']);
    }

    public function test_a_hidden_products_line_keeps_its_image_but_has_no_url(): void
    {
        $this->makeProduct(1, ['slug' => 'gone', 'visibility' => 'hidden', 'price' => '10.00']);
        $this->makeImage(10, 1);

        $result = $this->enrich('101');

        $this->assertNull($result['101']['product_url']);
        $this->assertNotNull($result['101']['image']);
    }

    public function test_an_empty_cart_runs_no_query(): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->assertSame([], app(CartLineEnricher::class)->enrich([]));
        $this->assertSame([], $sql);
    }

    // ---- the cost ---------------------------------------------------------------------------------------------

    public function test_the_cost_is_a_fixed_four_queries_for_one_line_and_for_six_with_one_image_or_five(): void
    {
        $this->makeProduct(1, ['slug' => 'one', 'price' => '10.00']);
        $this->makeImage(10, 1);

        foreach (range(2, 6) as $n) {
            $this->makeProduct($n, ['slug' => "p{$n}", 'price' => '10.00']);

            foreach (range(1, 5) as $i) {
                $this->makeImage($n * 100 + $i, $n, $i);
            }

            $this->makeVariationImage($n * 1000, $n * 100 + 1, 0);
        }

        $count = function (array $ids): int {
            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });

            app(CartLineEnricher::class)->enrich($ids);

            return count($sql);
        };

        $one = $count(['101']);
        $six = $count(['101', '201', '301', '401', '501', '601']);

        fwrite(STDERR, "\n[query-count] cart line enricher: 1 line/1 image = {$one} queries, 6 lines/up to 6 images each = {$six} queries (fixed delta of GET /api/cart)\n");

        $this->assertSame(4, $one);
        $this->assertSame(4, $six, 'independent of the number of lines and images');
    }

    // ---- through the cart API --------------------------------------------------------------------------------

    private function addLine(string $variationId, int $quantity = 1): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/cart/lines', ['variation_id' => $variationId, 'quantity' => $quantity]);
    }

    public function test_every_cart_response_carries_the_two_fields_on_every_line(): void
    {
        $this->makeProduct(1, ['slug' => 'pretty', 'name' => 'Pretty', 'price' => '10.00', 'stock' => 9]);
        $this->makeImage(10, 1);
        $this->makeProduct(2, ['slug' => 'plain', 'price' => '5.00', 'stock' => 9]);

        $first = $this->addLine('101')->assertStatus(201);
        $this->assertSame('/product/pretty', $first->json('lines.0.product_url'), 'the write that returns the cart carries it too');
        $added = $this->addLine('201')->assertStatus(201);

        foreach ([$added, $this->getJson('/api/cart')->assertOk(), $this->patchJson('/api/cart/lines/101', ['quantity' => 2])->assertOk()] as $response) {
            $lines = collect($response->json('lines'))->keyBy('variation_id');

            $this->assertSame('/product/pretty', $lines['101']['product_url']);
            $this->assertSame('https://cdn.test/storage/p/10-thumbnail.webp', $lines['101']['image']['src']);
            $this->assertSame('/product/plain', $lines['201']['product_url']);
            $this->assertNull($lines['201']['image']);
        }
    }

    public function test_the_existing_line_keys_are_unchanged_and_the_two_are_added_at_the_end(): void
    {
        $this->makeProduct(1, ['price' => '10.00', 'stock' => 9]);
        $this->addLine('101')->assertStatus(201);

        $this->assertSame(
            ['variation_id', 'quantity', 'price_at_add', 'unit_price', 'line_total', 'price_changed_since_add', 'price_available', 'product_name', 'sku', 'attributes', 'product_url', 'image'],
            array_keys($this->getJson('/api/cart')->json('lines.0')),
        );
    }

    public function test_a_line_whose_product_was_hidden_after_it_was_added_has_a_null_url(): void
    {
        $this->makeProduct(1, ['slug' => 'later-hidden', 'price' => '10.00', 'stock' => 9]);
        $this->addLine('101')->assertStatus(201);
        $this->assertSame('/product/later-hidden', $this->getJson('/api/cart')->json('lines.0.product_url'));

        DB::table('catalog_products')->where('id', 1)->update(['catalog_visibility' => 'hidden']);

        $this->assertNull($this->getJson('/api/cart')->assertOk()->json('lines.0.product_url'));
    }

    public function test_the_cart_read_costs_exactly_the_enrichers_four_queries_more_and_not_more_for_more_lines(): void
    {
        foreach (range(1, 6) as $n) {
            $this->makeProduct($n, ['slug' => "c{$n}", 'price' => '10.00', 'stock' => 9]);
            $this->makeImage($n, $n);
        }

        $count = function (): int {
            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });
            $this->getJson('/api/cart')->assertOk();

            return count(array_filter($sql, fn (string $q) => str_contains($q, 'catalog_variation_media') || str_contains($q, 'catalog_product_media') || (str_contains($q, 'from `catalog_products`') && str_contains($q, '`slug`')) || (str_contains($q, 'select `product_id`, `id` from `catalog_variations`'))));
        };

        $this->addLine('101');
        $one = $count();

        foreach (range(2, 6) as $n) {
            $this->addLine((string) ($n * 100 + 1));
        }

        $this->assertSame($one, $count(), 'the enricher\'s share of GET /api/cart does not grow with the lines');
    }
}
