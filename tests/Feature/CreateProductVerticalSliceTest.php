<?php

namespace Tests\Feature;

use ArrayObject;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OutOfBoundsException;
use Tests\TestCase;

/**
 * Exercises the Catalog -> Pricing vertical slice through the real HTTP
 * route (POST /api/products), with the PriceResolver binding swapped for a
 * test double so these tests never depend on the temporary hardcoded
 * InMemoryPriceResolver seed (priceableId "1") or on guessing what
 * auto-increment id a fresh test database will hand out.
 */
class CreateProductVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    private Price $fixedPrice;

    private ArrayObject $capturedPriceableIds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixedPrice = Price::exclusiveOfTax(
            Money::fromMinorUnits(4999, Currency::EUR())
        );
        $this->capturedPriceableIds = new ArrayObject();

        $this->bindAlwaysResolvingPriceResolver($this->fixedPrice, $this->capturedPriceableIds);
    }

    public function test_creating_a_product_resolves_a_price_end_to_end(): void
    {
        $response = $this->postJson('/api/products', ['name' => 'Nike Air Max', 'base_sku' => 'TEST-SKU-1']);

        $response->assertStatus(200);

        $body = $response->json();

        self::assertSame('Nike Air Max', $body['name']);
        // No slug was posted, so the 'catalog.product.slug' filter
        // auto-generates one from the name via
        // CatalogSlugGeneratorServiceProvider — see
        // extensibility-design-and-hooks.md's Hook Reference.
        self::assertSame('nike-air-max', $body['slug']);
        self::assertSame('49.99', $body['price']);

        if (array_key_exists('price_unavailable', $body)) {
            self::assertFalse($body['price_unavailable'], 'price_unavailable must be absent or false when a price resolves');
        }

        $productId = $body['product_id'] ?? null;
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Nike Air Max',
            'slug' => 'nike-air-max',
        ]);

        $variation = DB::table('catalog_variations')
            ->where('product_id', $productId)
            ->where('type', 'universal')
            ->first();

        self::assertNotNull($variation, 'expected a universal variation row for this product');

        // The resolver double recorded every priceableId it was actually
        // called with — confirm the controller resolved a price for
        // exactly this variation's real (persisted) id.
        self::assertCount(1, $this->capturedPriceableIds);
        self::assertSame((string) $variation->id, $this->capturedPriceableIds[0]);
    }

    public function test_creating_a_product_when_price_resolution_fails_returns_price_unavailable(): void
    {
        $this->app->singleton(PriceResolver::class, function () {
            return new class implements PriceResolver
            {
                public function resolve(PriceContext $context): PriceQuote
                {
                    throw new OutOfBoundsException('No price seeded for this priceableId in this test.');
                }
            };
        });

        $response = $this->postJson('/api/products', ['name' => 'Some Product', 'base_sku' => 'TEST-SKU-2']);

        // Creation must succeed even though price resolution failed — a
        // price miss is not a reason to fail the whole request.
        $response->assertStatus(200);

        $body = $response->json();

        self::assertSame('Some Product', $body['name']);
        self::assertSame('some-product', $body['slug']);
        self::assertNull($body['price']);
        self::assertTrue($body['price_unavailable'] ?? false);

        $productId = $body['product_id'] ?? null;
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Some Product',
            'slug' => 'some-product',
        ]);
    }

    public function test_creating_a_product_without_a_slug_gets_a_sensible_auto_generated_slug(): void
    {
        $response = $this->postJson('/api/products', [
            'name' => 'Wireless Mouse',
            'base_sku' => 'TEST-SKU-3',
        ]);

        $response->assertStatus(200);

        $slug = $response->json('slug');
        self::assertSame('wireless-mouse', $slug);

        $productId = $response->json('product_id');
        self::assertNotNull($productId, 'response must include product_id');

        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'Wireless Mouse',
            'slug' => $slug,
        ]);
    }

    /**
     * Previously this test proved base_sku was a required field (422
     * without one). That's no longer true: as of the real
     * 'catalog.product.base_sku' generator
     * (App\Providers\CatalogSkuGeneratorServiceProvider), omitting
     * base_sku is the documented way to request an auto-generated
     * sequence value — not an error. See
     * tests/Feature/CatalogSkuGeneratorTest.php for that behavior's real
     * coverage; this file's validation is now `nullable`, not `required`
     * (see ProductController::store()).
     */
    public function test_creating_a_product_without_a_base_sku_still_succeeds_with_an_auto_generated_one(): void
    {
        $response = $this->postJson('/api/products', ['name' => 'No Base Sku Given']);

        $response->assertStatus(200);

        $productId = $response->json('product_id');
        $this->assertDatabaseHas('catalog_products', [
            'id' => $productId,
            'name' => 'No Base Sku Given',
        ]);
    }

    private function bindAlwaysResolvingPriceResolver(Price $price, ArrayObject $captured): void
    {
        $this->app->singleton(PriceResolver::class, function () use ($price, $captured) {
            return new class($price, $captured) implements PriceResolver
            {
                public function __construct(
                    private readonly Price $price,
                    private readonly ArrayObject $captured,
                ) {
                }

                public function resolve(PriceContext $context): PriceQuote
                {
                    $this->captured[] = $context->priceableId;

                    return new PriceQuote(regular: $this->price, final: $this->price);
                }
            };
        });
    }
}
