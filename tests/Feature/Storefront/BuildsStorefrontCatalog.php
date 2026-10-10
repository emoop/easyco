<?php

namespace Tests\Feature\Storefront;

use App\Services\ProductPricingAndStock;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Raw-row fixtures for the storefront read layer. Rows are inserted DIRECTLY with EXPLICIT ids and fixed timestamps
 * so the golden files are byte-stable (MySQL auto-increment is not rolled back with the test transaction).
 * Prices go through the real ProductPricingAndStock writer (the same one the admin uses), so the real price
 * resolvers answer.
 *
 * Variation ids are `productId * 100 + n`.
 */
trait BuildsStorefrontCatalog
{
    private const STAMP = '2026-01-01 10:00:00';

    protected function seedPricing(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        config(['services.pricing.default_tax_rate_basis_points' => 0]);
    }

    protected function makeBrand(int $id, string $name, string $slug): int
    {
        DB::table('catalog_brands')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    protected function makeCategory(int $id, string $name, string $slug, ?int $parent = null): int
    {
        DB::table('catalog_categories')->insert(['id' => $id, 'parent_id' => $parent, 'name' => $name, 'slug' => $slug, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    protected function makeTag(int $id, string $name, string $slug): int
    {
        DB::table('catalog_tags')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        return $id;
    }

    /** Attribute definition + values (explicit ids: definition id, then value ids 1000*def + n). @param list<string> $values */
    protected function makeAttribute(int $id, string $name, array $values): void
    {
        DB::table('catalog_attribute_definitions')->insert(['id' => $id, 'code' => strtolower($name), 'name' => $name, 'type' => 'select', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        foreach ($values as $i => $value) {
            DB::table('catalog_attribute_values')->insert([
                'id' => $id * 1000 + $i + 1, 'attribute_definition_id' => $id, 'value' => $value, 'sort_order' => $i,
                'created_at' => self::STAMP, 'updated_at' => self::STAMP,
            ]);
        }
    }

    /**
     * A product with its variations.
     *
     * @param array{
     *   name?: string, slug?: string, type?: string, status?: string, visibility?: string, deleted?: bool,
     *   brand?: ?int, categories?: list<int>, tags?: list<int>, timeline?: string, description?: ?string, short?: ?string,
     *   price?: ?string, sale?: ?string, stock?: int,
     *   variations?: list<array{attrs?: array<int, string>, status?: string, visible?: bool, purchasable?: bool, deleted?: bool, price?: ?string, sale?: ?string, stock?: int, sku?: string, type?: string}>
     * } $o
     */
    protected function makeProduct(int $id, array $o = []): int
    {
        $type = $o['type'] ?? 'simple';
        $slug = $o['slug'] ?? "product-{$id}";

        DB::table('catalog_products')->insert([
            'id' => $id,
            'type' => $type,
            'name' => $o['name'] ?? "Product {$id}",
            'slug' => $slug,
            'base_sku' => "BASE-{$id}",
            'short_description' => $o['short'] ?? null,
            'description' => $o['description'] ?? null,
            'brand_id' => $o['brand'] ?? null,
            'status' => $o['status'] ?? 'active',
            'catalog_visibility' => $o['visibility'] ?? 'visible',
            'is_featured' => false,
            'created_at' => self::STAMP,
            'updated_at' => self::STAMP,
            'timeline_at' => $o['timeline'] ?? self::STAMP,
            'deleted_at' => ($o['deleted'] ?? false) ? self::STAMP : null,
        ]);

        foreach ($o['categories'] ?? [] as $categoryId) {
            DB::table('catalog_product_categories')->insert(['product_id' => $id, 'category_id' => $categoryId]);
        }

        foreach ($o['tags'] ?? [] as $tagId) {
            DB::table('catalog_product_tags')->insert(['product_id' => $id, 'tag_id' => $tagId]);
        }

        $variations = $o['variations'] ?? [[
            'type' => $type === 'simple' ? 'universal' : 'standard',
            'price' => $o['price'] ?? null,
            'sale' => $o['sale'] ?? null,
            'stock' => $o['stock'] ?? 0,
        ]];

        foreach (array_values($variations) as $n => $v) {
            $variationId = $id * 100 + $n + 1;
            $variationType = $v['type'] ?? ($type === 'simple' ? 'universal' : 'standard');

            DB::table('catalog_variations')->insert([
                'id' => $variationId,
                'product_id' => $id,
                'sort_order' => $n,
                'type' => $variationType,
                'status' => $v['status'] ?? 'active',
                'attribute_signature' => hash('sha256', "{$id}-{$n}"),
                'sku' => $v['sku'] ?? "SKU-{$variationId}",
                'is_visible' => $v['visible'] ?? ($variationType === 'standard'),
                'is_purchasable' => $v['purchasable'] ?? true,
                'created_at' => self::STAMP,
                'updated_at' => self::STAMP,
                'deleted_at' => ($v['deleted'] ?? false) ? self::STAMP : null,
            ]);

            foreach ($v['attrs'] ?? [] as $definitionId => $value) {
                $valueId = DB::table('catalog_attribute_values')->where('attribute_definition_id', $definitionId)->where('value', $value)->value('id');
                DB::table('catalog_variation_attribute_values')->insert([
                    'variation_id' => $variationId, 'attribute_definition_id' => $definitionId, 'attribute_value_id' => $valueId,
                    'created_at' => self::STAMP, 'updated_at' => self::STAMP,
                ]);
                DB::table('catalog_product_axis_values')->insertOrIgnore([
                    'product_id' => $id, 'attribute_definition_id' => $definitionId, 'attribute_value_id' => $valueId,
                    'created_at' => self::STAMP, 'updated_at' => self::STAMP,
                ]);
            }

            if (($v['stock'] ?? 0) > 0) {
                DB::table('stock_levels')->insert(['variation_id' => $variationId, 'quantity' => $v['stock'], 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
            }

            $pricing = app(ProductPricingAndStock::class);

            if (($v['price'] ?? null) !== null) {
                $pricing->writeRegularPrice((string) $variationId, $v['price']);
            }

            if (($v['sale'] ?? null) !== null) {
                $pricing->writeSalePrice((string) $variationId, $v['sale']);
            }
        }

        return $id;
    }

    /**
     * An image asset attached to a product. `variants` defaults to the three customer tiers plus the admin crop.
     *
     * @param list<array{tier: string, width: int, height: int, quality: int, path: string}>|null $variants
     */
    protected function makeImage(int $mediaId, int $productId, int $sort = 0, string $status = 'ready', ?string $alt = null, ?array $variants = null, string $type = 'image'): int
    {
        $variants ??= $status === 'ready' ? [
            ['tier' => 'thumbnail', 'width' => 400, 'height' => 300, 'quality' => 80, 'path' => "p/{$mediaId}-thumbnail.webp"],
            ['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 82, 'path' => "p/{$mediaId}-medium.webp"],
            ['tier' => 'large', 'width' => 1600, 'height' => 1200, 'quality' => 85, 'path' => "p/{$mediaId}-large.webp"],
            ['tier' => 'admin_grid', 'width' => 42, 'height' => 42, 'quality' => 80, 'path' => "p/{$mediaId}-admin_grid.webp"],
        ] : [];

        DB::table('catalog_media')->insert([
            'id' => $mediaId, 'type' => $type, 'disk' => 'public', 'path' => "p/{$mediaId}.jpg", 'alt_text' => $alt,
            'processing_status' => $status, 'variants' => json_encode($variants),
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
        DB::table('catalog_product_media')->insert(['product_id' => $productId, 'media_id' => $mediaId, 'sort_order' => $sort]);

        return $mediaId;
    }
}
