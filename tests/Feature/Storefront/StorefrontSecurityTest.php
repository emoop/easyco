<?php

namespace Tests\Feature\Storefront;

use App\Services\ProductPricingAndStock;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontSecurityTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    private function reader(): CatalogReader
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class);
    }

    /** @return list<string> every array key, at any depth */
    private function allKeys(array $data): array
    {
        $keys = [];

        $walk = function (array $node) use (&$walk, &$keys): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keys[$key] = true;
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($data);

        return array_keys($keys);
    }

    public function test_a_product_that_stops_being_visible_vanishes_from_every_read(): void
    {
        $category = $this->makeCategory(1, 'Things', 'things');
        $brand = $this->makeBrand(1, 'Acme', 'acme');
        $tag = $this->makeTag(1, 'Summer', 'summer');

        $states = [
            'hidden' => ['catalog_visibility' => 'hidden'],
            'draft' => ['status' => 'draft'],
            'archived' => ['status' => 'archived'],
            'soft-deleted' => ['deleted_at' => '2026-01-01 00:00:00'],
        ];

        foreach ($states as $label => $change) {
            $this->makeProduct(1, ['slug' => 'only-one', 'categories' => [$category], 'brand' => $brand, 'tags' => [$tag], 'price' => '10.00', 'stock' => 1]);
            $this->makeImage(1, 1);

            $reader = $this->reader();
            $this->assertNotNull($reader->product('only-one'), "{$label}: visible first");
            $this->assertSame(1, $reader->listing(ListingQuery::fromRequestArray([]))->total);
            $this->assertSame(1, $reader->categoryTree()[0]->productCount);

            DB::table('catalog_products')->where('id', 1)->update($change);

            $reader = $this->reader();
            $this->assertNull($reader->product('only-one'), "{$label}: product()");

            foreach ([[], ['category' => '1'], ['brand' => '1'], ['tag' => '1']] as $request) {
                $this->assertSame(0, $reader->listing(ListingQuery::fromRequestArray($request))->total, "{$label}: listing ".json_encode($request));
            }

            $this->assertSame([], $reader->categoryTree(), "{$label}: tree");
            $this->assertNull($reader->category('things'), "{$label}: category()");

            DB::table('catalog_media')->delete();
            DB::table('stock_levels')->delete();
            DB::table('catalog_variations')->delete();
            DB::table('catalog_product_categories')->delete();
            DB::table('catalog_product_tags')->delete();
            DB::table('catalog_products')->delete();
        }
    }

    public function test_no_read_model_exposes_a_field_that_is_not_for_customers(): void
    {
        $this->makeAttribute(1, 'Size', ['S']);
        $category = $this->makeCategory(1, 'Things', 'things');
        $brand = $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, [
            'type' => 'variable', 'slug' => 'p', 'brand' => $brand, 'categories' => [$category], 'short' => 'short', 'description' => 'long',
            'variations' => [['attrs' => [1 => 'S'], 'price' => '20.00', 'stock' => 2]],
        ]);
        $this->makeImage(1, 1);
        app(ProductPricingAndStock::class)->writeCost('101', '7.77');
        DB::table('catalog_variations')->where('id', 101)->update(['barcode' => '5901234123457', 'weight_grams' => 4321, 'shipping_class' => 'bulky']);
        DB::table('catalog_products')->where('id', 1)->update(['base_sku' => 'SECRET-BASE-SKU']);

        $reader = $this->reader();
        $outputs = [
            'product' => $reader->product('p')->toArray(),
            'listing' => $reader->listing(ListingQuery::fromRequestArray([]))->toArray(),
            'category' => $reader->category('things')->toArray(),
            'tree' => array_map(fn ($n) => $n->toArray(), $reader->categoryTree()),
        ];

        $forbiddenKeys = ['cost', 'cost_minor', 'supplier', 'notes', 'internal_note', 'barcode', 'weight_grams', 'length_mm', 'shipping_class', 'base_sku',
            'created_at', 'updated_at', 'deleted_at', 'timeline_at', 'status', 'catalog_visibility', 'is_visible', 'is_purchasable', 'is_featured',
            'brand_id', 'product_id', 'variation_id', 'quantity', 'stock', 'attribute_signature', 'processing_status', 'disk', 'media_id'];

        foreach ($outputs as $name => $output) {
            $this->assertSame([], array_values(array_intersect($forbiddenKeys, $this->allKeys($output))), "{$name} must not carry internal keys");

            $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['7.77', '777', 'SECRET-BASE-SKU', '5901234123457', '4321', 'bulky'] as $needle) {
                $this->assertStringNotContainsString($needle, $json, "{$name} leaks {$needle}");
            }
        }
    }

    public function test_the_exact_key_sets_of_a_card_a_product_and_a_variation(): void
    {
        $this->makeProduct(1, ['slug' => 'p', 'price' => '1.00', 'stock' => 1]);
        $reader = $this->reader();

        $card = $reader->listing(ListingQuery::fromRequestArray([]))->items[0]->toArray();
        $page = $reader->product('p')->toArray();

        $this->assertSame(['id', 'slug', 'url', 'name', 'brand', 'image', 'price', 'badges', 'in_stock'], array_keys($card));
        $this->assertSame(
            ['id', 'slug', 'url', 'name', 'type', 'short_description', 'description', 'brand', 'breadcrumbs', 'images', 'price', 'in_stock', 'options', 'variations', 'badges'],
            array_keys($page),
        );
        $this->assertSame(['id', 'sku', 'label', 'attributes', 'price', 'in_stock', 'purchasable'], array_keys($page['variations'][0]));
        $this->assertSame(['from_minor', 'to_minor', 'currency', 'regular_from_minor'], array_keys($page['price']));
    }

    public function test_text_is_kept_raw_never_pre_escaped(): void
    {
        $brand = $this->makeBrand(1, '<b>Acme</b> & Co', 'acme');
        $category = $this->makeCategory(1, '<i>Things</i>', 'things');
        $this->makeProduct(1, ['name' => '<script>alert(1)</script> & "quotes"', 'slug' => 'raw', 'brand' => $brand, 'categories' => [$category], 'price' => '1.00', 'short' => '<em>x</em>']);
        $this->makeImage(1, 1, alt: '<img src=x onerror=alert(1)>');

        $reader = $this->reader();
        $page = $reader->product('raw');
        $card = $reader->listing(ListingQuery::fromRequestArray([]))->items[0];

        $this->assertSame('<script>alert(1)</script> & "quotes"', $page->name);
        $this->assertSame('<script>alert(1)</script> & "quotes"', $card->name);
        $this->assertSame('<b>Acme</b> & Co', $page->brand['name']);
        $this->assertSame('<img src=x onerror=alert(1)>', $page->images[0]->alt);
        $this->assertSame('<i>Things</i>', $reader->categoryTree()[0]->name);
        $this->assertSame('<script>alert(1)</script> & "quotes"', $page->breadcrumbs[1]->label);
        $this->assertStringNotContainsString('&lt;', json_encode($page->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_lookups_are_parameter_bound(): void
    {
        $this->makeProduct(1, ['slug' => 'real', 'price' => '1.00']);
        $this->makeCategory(1, 'Real', 'real');

        foreach (["real' OR '1'='1", 'real"; DROP TABLE catalog_products; --', 'real%', 'real\\', "real\0"] as $hostile) {
            $this->assertNull($this->reader()->product($hostile), $hostile);
            $this->assertNull($this->reader()->category($hostile), $hostile);
        }

        $this->assertSame(1, DB::table('catalog_products')->count(), 'nothing was dropped or altered');
    }

    public function test_the_product_page_is_a_pure_read(): void
    {
        $this->makeProduct(1, ['slug' => 'p', 'price' => '10.00', 'stock' => 2]);
        $before = [DB::table('catalog_products')->get()->toArray(), DB::table('stock_levels')->get()->toArray(), DB::table('catalog_variations')->get()->toArray()];

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $reader = $this->reader();
        $reader->product('p');
        $reader->listing(ListingQuery::fromRequestArray([]));
        $reader->categoryTree();

        $this->assertSame([], $writes);
        $this->assertEquals($before, [DB::table('catalog_products')->get()->toArray(), DB::table('stock_levels')->get()->toArray(), DB::table('catalog_variations')->get()->toArray()]);
    }
}
