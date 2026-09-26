<?php

namespace Tests\Feature;

use App\Services\VariationDisplayReader;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cart API's DISPLAY-ONLY line fields — product_name, sku, attributes —
 * added so the sandbox storefront (stage D4) can render real lines instead of
 * bare variation ids. Purely additive: every pre-existing field keeps its name,
 * type and value, which test 1 below pins down key by key.
 *
 * The batched-read requirement lives in
 * test_the_attribute_read_is_one_batch_for_two_lines_and_for_ten_lines(): the
 * attribute labels come from VariationDisplayReader, the ONE batched walk the
 * checkout snapshot also uses, so a cart with 2 lines and a cart with 10 lines
 * cost the SAME number of attribute-table queries. The test prints both totals
 * and both attribute-table counts, so the reader can see exactly what the whole
 * request costs and which part of it is constant.
 */
class CartLineDisplayFieldsTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();

        // See account-domain-design.md §10 — Sanctum's stateful pipeline needs a
        // recognized Referer to engage the session at all, which the guest
        // cart_token depends on.
        $this->withHeader('Referer', 'http://localhost/');
    }

    /** @return array{variation_id: string, name: string, sku: string} */
    private function simpleProduct(): array
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;
        $name = "Product {$suffix}";
        $sku = "SKU-{$suffix}";

        $product = Product::createSimple($name, $sku, "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return ['variation_id' => $product->variations()[0]->id(), 'name' => $name, 'sku' => $sku];
    }

    /**
     * A VARIABLE product with one SELECT axis (Size) and one real standard variation.
     *
     * @return array{variation_id: string, name: string, sku: string, value: string}
     */
    private function variableProduct(string $value = 'M'): array
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;
        $name = "Product {$suffix}";
        $sku = "SKU-VAR-{$suffix}";

        $definition = new AttributeDefinition(id: null, code: "size-{$suffix}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $attributeValue = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $value);
        app(AttributeValueRepository::class)->save($attributeValue);

        $product = Product::createVariable($name, $sku, "variable-slug-{$suffix}");
        $product->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $product->declareVariationAxes([new VariationAxis($definition, [$attributeValue])]);
        $variation = $product->addStandardVariation([$definition->id() => $attributeValue->id()], "{$sku}-{$value}");
        $variation->activate();
        $product->publish();
        app(ProductRepository::class)->save($product);

        return [
            'variation_id' => $variation->id(),
            'name' => $name,
            'sku' => "{$sku}-{$value}",
            'value' => $value,
        ];
    }

    private function setPrice(string $variationId, string $decimalAmount): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function addLineViaHttp(string $variationId, int $quantity = 1): array
    {
        $response = $this->postJson('/api/cart/lines', [
            'variation_id' => $variationId,
            'quantity' => $quantity,
        ])->assertStatus(201);

        return $response->json('lines');
    }

    public function test_a_simple_products_line_carries_its_name_sku_and_an_empty_attribute_list(): void
    {
        $product = $this->simpleProduct();
        $this->setPrice($product['variation_id'], '10.00');
        app(StockLevelRepository::class)->save(StockLevel::forVariation($product['variation_id'], 5));

        $lines = $this->addLineViaHttp($product['variation_id'], 2);

        $this->assertCount(1, $lines);
        $line = $lines[0];

        // The pre-existing contract, field by field — unchanged by this task.
        $this->assertSame($product['variation_id'], $line['variation_id']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(1000, $line['unit_price']['minor']);
        $this->assertSame(2000, $line['line_total']['minor']);
        $this->assertSame('EUR', $line['line_total']['currency']);
        $this->assertFalse($line['price_changed_since_add']);
        $this->assertTrue($line['price_available']);

        // The additive display fields.
        $this->assertSame($product['name'], $line['product_name']);
        $this->assertSame($product['sku'], $line['sku']);
        $this->assertSame([], $line['attributes'], 'a SIMPLE product\'s universal variation has no attributes');

        // Nothing else was added — ten keys, exactly these.
        $this->assertSame([
            'variation_id',
            'quantity',
            'price_at_add',
            'unit_price',
            'line_total',
            'price_changed_since_add',
            'price_available',
            'product_name',
            'sku',
            'attributes',
        ], array_keys($line));
    }

    public function test_a_variable_products_line_carries_its_attribute_as_a_name_value_pair(): void
    {
        $product = $this->variableProduct('M');
        $this->setPrice($product['variation_id'], '12.50');
        app(StockLevelRepository::class)->save(StockLevel::forVariation($product['variation_id'], 3));

        $lines = $this->addLineViaHttp($product['variation_id']);
        $readLines = $this->getJson('/api/cart')->assertOk()->json('lines');

        // Both the write's own response and a later read carry the same labels —
        // one code path (serializeCart()) produces both.
        foreach ([$lines[0], $readLines[0]] as $line) {
            $this->assertSame($product['name'], $line['product_name']);
            $this->assertSame($product['sku'], $line['sku']);
            $this->assertSame(
                [['name' => 'Size', 'value' => 'M']],
                $line['attributes'],
                'the sold attribute is labelled by its definition name and its value'
            );
        }
    }

    /**
     * The batched-read requirement, measured: a 2-line cart and a 10-line cart cost
     * the SAME number of attribute-table queries, because both go through
     * VariationDisplayReader::lineDisplayFor()'s single batched walk. The totals are
     * printed as well, and deliberately NOT asserted equal: the pre-existing part of
     * serializeCart() (price and catalog-scope resolution) is per line by design and
     * is not this task's to change — the numbers are printed so that growth is
     * visible and attributable rather than hidden.
     */
    public function test_the_attribute_read_is_one_batch_for_two_lines_and_for_ten_lines(): void
    {
        $two = $this->measureCartRead(2);

        $this->flushSession();

        $ten = $this->measureCartRead(10);

        fwrite(STDERR, sprintf(
            "\n[query-count] GET /api/cart: 2 lines: %d queries (%d on the attribute tables), 10 lines: %d queries (%d on the attribute tables)\n",
            $two['total'],
            $two['attribute_tables'],
            $ten['total'],
            $ten['attribute_tables'],
        ));

        $this->assertSame(
            $two['attribute_tables'],
            $ten['attribute_tables'],
            'the batched attribute read must not grow with the number of cart lines'
        );
        $this->assertSame(2, $two['attribute_tables'], 'exactly two: the batched definitions query and the batched values query');
    }

    /** @return array{total: int, attribute_tables: int} */
    private function measureCartRead(int $lineCount): array
    {
        for ($i = 0; $i < $lineCount; $i++) {
            $product = $this->simpleProduct();
            $this->setPrice($product['variation_id'], '10.00');
            app(StockLevelRepository::class)->save(StockLevel::forVariation($product['variation_id'], 5));

            $this->addLineViaHttp($product['variation_id']);
        }

        $counts = ['total' => 0, 'attribute_tables' => 0];

        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;

            if (str_contains($query->sql, 'catalog_attribute_definitions') || str_contains($query->sql, 'catalog_attribute_values')) {
                $counts['attribute_tables']++;
            }
        });

        $this->getJson('/api/cart')->assertOk();

        DB::flushQueryLog();

        return $counts;
    }
}
