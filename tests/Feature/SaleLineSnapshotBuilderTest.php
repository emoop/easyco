<?php

namespace Tests\Feature;

use App\Services\SaleLineSnapshotBuilder;
use DateTimeImmutable;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * App\Services\SaleLineSnapshotBuilder — the one app-layer builder
 * behind operational-sales-domain-design.md §3.13's stage 4a (E-D1/E-D4).
 * Exercises buildForCart() directly rather than only through
 * CheckoutOrchestrator, isolating what this class alone is responsible
 * for: the amount/netPaidAmount/profit formulas (D4) and the batched
 * soldAttributes read (D5).
 */
class SaleLineSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function builder(): SaleLineSnapshotBuilder
    {
        return app(SaleLineSnapshotBuilder::class);
    }

    private function simpleVariationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue} */
    private function persistedAxis(string $code, string $name, string $value): array
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: $name, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $attributeValue = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $value);
        app(AttributeValueRepository::class)->save($attributeValue);

        return [$definition, $attributeValue];
    }

    private function baseLine(string $variationId, array $overrides = []): array
    {
        return array_merge([
            'variationId' => $variationId,
            'quantity' => 1,
            'regularUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'finalUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'unitCost' => null,
            'productName' => 'Product One',
            'sku' => 'SKU-1',
            'promotionDiscountShare' => Money::zero('EUR'),
            'discretionaryDiscount' => Money::zero('EUR'),
        ], $overrides);
    }

    private function build(array $lines): array
    {
        return $this->builder()->buildForCart(
            lines: $lines,
            transactionId: '',
            clientId: 'client-1',
            status: SaleLineStatus::COMPLETED,
            recordedAt: new DateTimeImmutable('2026-09-25 12:00:00'),
            effectiveAt: new DateTimeImmutable('2026-09-25 12:00:00'),
        );
    }

    public function test_a_simple_product_line_gets_an_empty_sold_attributes_list(): void
    {
        $variationId = $this->simpleVariationId();

        $lines = $this->build([$this->baseLine($variationId)]);

        $this->assertSame([], $lines[0]->soldAttributes());
    }

    /**
     * D5 — no authoritative axis order exists anywhere in this codebase
     * (verified against the real installed source, see
     * SaleLineSnapshotBuilder's own class docblock); this asserts the
     * chosen deterministic fallback (sorted by attribute_definition_id
     * ascending) rather than trusting unordered DB row-return order.
     * Two axes declared in reverse alphabetical/definition-id order
     * (size THEN color) prove the result is genuinely re-sorted, not
     * just happening to already be in the right order.
     */
    public function test_a_variable_product_line_gets_an_ordered_sold_attributes_list(): void
    {
        [$colorDefinition, $black] = $this->persistedAxis('color', 'Color', 'Black');
        [$sizeDefinition, $large] = $this->persistedAxis('size', 'Size', 'L');

        $product = Product::createVariable('Variable One', 'SKU-VAR', 'variable-one');
        $product->declareVariationAxes([
            new VariationAxis($sizeDefinition, [$large]),
            new VariationAxis($colorDefinition, [$black]),
        ]);
        $product->addStandardVariation([
            $sizeDefinition->id() => $large->id(),
            $colorDefinition->id() => $black->id(),
        ], 'SKU-VAR-L-BLACK');
        app(ProductRepository::class)->save($product);

        $variationId = $product->variations()[0]->id();

        $lines = $this->build([$this->baseLine($variationId)]);
        $soldAttributes = $lines[0]->soldAttributes();

        $this->assertCount(2, $soldAttributes);
        // Sorted by attribute_definition_id ascending — $colorDefinition
        // was persisted first, so it has the lower id.
        $this->assertSame($colorDefinition->id(), $soldAttributes[0]['definitionId']);
        $this->assertSame('color', $soldAttributes[0]['definitionCode']);
        $this->assertSame('Color', $soldAttributes[0]['definitionName']);
        $this->assertSame($black->id(), $soldAttributes[0]['valueId']);
        $this->assertSame('Black', $soldAttributes[0]['value']);
        $this->assertSame($sizeDefinition->id(), $soldAttributes[1]['definitionId']);
        $this->assertSame('L', $soldAttributes[1]['value']);
    }

    public function test_a_discounted_line_carries_both_regular_and_final_unit_price(): void
    {
        $variationId = $this->simpleVariationId();

        $lines = $this->build([$this->baseLine($variationId, [
            'regularUnitPrice' => Money::fromMinorUnits(1200, 'EUR'),
            'finalUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
        ])]);

        $this->assertSame(1200, $lines[0]->regularUnitPrice()->minorValue());
        $this->assertSame(1000, $lines[0]->finalUnitPrice()->minorValue());
        $this->assertSame(1000, $lines[0]->amount()->minorValue());
    }

    /**
     * D4 — profit on net, cost KNOWN: quantity 3, finalUnitPrice 10.00,
     * unitCost 4.00 -> amount 30.00, netPaidAmount 30.00 (no discount),
     * profit = netPaidAmount - unitCost*quantity = 30.00 - 12.00 = 18.00.
     */
    public function test_profit_on_net_with_a_known_cost(): void
    {
        $variationId = $this->simpleVariationId();

        $lines = $this->build([$this->baseLine($variationId, [
            'quantity' => 3,
            'regularUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'finalUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'unitCost' => Money::fromMinorUnits(400, 'EUR'),
        ])]);

        $this->assertSame(3000, $lines[0]->amount()->minorValue());
        $this->assertSame(3000, $lines[0]->netPaidAmount()->minorValue());
        $this->assertSame(1800, $lines[0]->profit()->minorValue());
        $this->assertSame(400, $lines[0]->unitCost()->minorValue());
    }

    /**
     * D4/§3.13 Q2 — cost UNKNOWN: unitCost stays NULL (not zero), and
     * profit == netPaidAmount exactly, never netPaidAmount - 0 computed
     * as if a zero cost had been verified.
     */
    public function test_profit_on_net_with_an_unknown_cost(): void
    {
        $variationId = $this->simpleVariationId();

        $lines = $this->build([$this->baseLine($variationId, ['unitCost' => null])]);

        $this->assertNull($lines[0]->unitCost());
        $this->assertTrue($lines[0]->profit()->equals($lines[0]->netPaidAmount()));
        $this->assertSame(1000, $lines[0]->profit()->minorValue());
    }

    public function test_a_promotion_share_reduces_net_paid_amount_and_profit(): void
    {
        $variationId = $this->simpleVariationId();

        $lines = $this->build([$this->baseLine($variationId, [
            'unitCost' => Money::fromMinorUnits(400, 'EUR'),
            'promotionDiscountShare' => Money::fromMinorUnits(100, 'EUR'),
        ])]);

        // amount 1000 - share 100 - discretionary 0 = netPaidAmount 900.
        $this->assertSame(900, $lines[0]->netPaidAmount()->minorValue());
        $this->assertSame(500, $lines[0]->profit()->minorValue());
    }

    /**
     * discretionaryDiscount is PER LINE, not per cart (this review's own
     * fix — E-D3's own field is a per-SaleLine fact, exactly the POS
     * shape E-D4 exists for: a cashier may knock a courtesy discount off
     * ONE line, not the whole ticket). Two lines with DIFFERENT
     * discretionary discounts must each compute their own net/profit
     * independently — a cart-wide parameter could never express this.
     */
    public function test_different_discretionary_discounts_per_line_produce_independent_net_and_profit(): void
    {
        $variationA = $this->simpleVariationId();
        $variationB = $this->simpleVariationId();

        $lines = $this->build([
            $this->baseLine($variationA, [
                'unitCost' => Money::fromMinorUnits(400, 'EUR'),
                'discretionaryDiscount' => Money::fromMinorUnits(50, 'EUR'),
            ]),
            $this->baseLine($variationB, [
                'unitCost' => Money::fromMinorUnits(400, 'EUR'),
                'discretionaryDiscount' => Money::fromMinorUnits(200, 'EUR'),
            ]),
        ]);

        // Line A: amount 1000 - share 0 - discretionary 50 = net 950; profit 950 - 400 = 550.
        $this->assertSame(50, $lines[0]->discretionaryDiscount()->minorValue());
        $this->assertSame(950, $lines[0]->netPaidAmount()->minorValue());
        $this->assertSame(550, $lines[0]->profit()->minorValue());

        // Line B: amount 1000 - share 0 - discretionary 200 = net 800; profit 800 - 400 = 400.
        $this->assertSame(200, $lines[1]->discretionaryDiscount()->minorValue());
        $this->assertSame(800, $lines[1]->netPaidAmount()->minorValue());
        $this->assertSame(400, $lines[1]->profit()->minorValue());
    }

    /**
     * "No silent fallbacks" review fix — a variation id this class is
     * asked to snapshot but VariationRepository::findByIds() doesn't
     * return must throw, never silently default to an empty
     * soldAttributes list.
     */
    public function test_a_variation_id_not_returned_by_find_by_ids_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('variation "nonexistent-variation-id" was not returned by VariationRepository::findByIds()');

        $this->build([$this->baseLine('nonexistent-variation-id')]);
    }

    /**
     * "No silent fallbacks" review fix — an attributeAssignments entry
     * referencing an attribute_definition_id/attribute_value_id that no
     * longer exists must throw, never silently skip that attribute. This
     * is structurally unreachable through the real domain API (Catalog's
     * own restrictOnDelete FKs forbid deleting a referenced definition/
     * value — confirmed by reading the real migration before writing
     * this test), so it's proven here via a fabricated Variation
     * (Variation::reconstituteFromStorage() with made-up ids, no DB
     * validation involved) behind a fake VariationRepository binding —
     * the only way to exercise this defensive code path at all.
     */
    public function test_an_assignment_referencing_a_missing_definition_throws(): void
    {
        $variationId = 'fabricated-variation-1';
        $fabricated = Variation::reconstituteFromStorage(
            id: $variationId,
            productId: 'fabricated-product-1',
            type: VariationType::STANDARD,
            status: VariationStatus::ACTIVE,
            attributeAssignments: [999999 => 888888], // neither id exists
            sku: 'SKU-FABRICATED',
        );

        $this->app->bind(VariationRepository::class, fn () => new class($fabricated) implements VariationRepository {
            public function __construct(private Variation $fabricated)
            {
            }

            public function findById(string $id): ?Variation
            {
                return null;
            }

            public function findBySku(string $sku): ?Variation
            {
                return null;
            }

            public function findByBarcode(string $barcode): ?Variation
            {
                return null;
            }

            public function findByProductId(string $productId): array
            {
                return [];
            }

            public function findByIds(array $variationIds): array
            {
                return [$this->fabricated->id() => $this->fabricated];
            }

            public function updateSortOrders(string $productId, array $orderedVariationIds): void
            {
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('variation "fabricated-variation-1" references attribute_definition_id "999999", which does not exist');

        $this->build([$this->baseLine($variationId)]);
    }
}
