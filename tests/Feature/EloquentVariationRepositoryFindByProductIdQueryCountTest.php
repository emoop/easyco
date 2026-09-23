<?php

namespace Tests\Feature;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the N+1 fix in EloquentVariationRepository::findByProductId():
 * a variation with zero attribute assignments must never cost an extra
 * query (see that method's own docblock for the `?? []` vs `?? null`
 * reasoning).
 */
class EloquentVariationRepositoryFindByProductIdQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    /**
     * Directly inserts extra catalog_variations rows for one product,
     * each with NO catalog_variation_attribute_values rows — a state the
     * public domain API cannot itself construct (a real Variation always
     * either has no assignments by TYPE, i.e. exactly one UNIVERSAL row
     * per product, or has them by construction, i.e. every STANDARD
     * variation's addStandardVariation() call requires one per declared
     * axis). Bypassing the domain layer here is deliberate and
     * test-only: it is the only way to get MANY assignment-less
     * variations under a single product in one findByProductId() call,
     * which is exactly the shape needed to prove the fix holds
     * regardless of how many such variations exist, not just for the
     * single-UNIVERSAL-variation case a SIMPLE product would give.
     */
    private function addAssignmentlessVariation(string $productId, string $skuSuffix): void
    {
        // type: 'universal' (not 'standard') — Variation::
        // reconstituteFromStorage() itself enforces "a STANDARD
        // combination must have at least one axis/value pair", which a
        // raw, assignment-less row would violate. UNIVERSAL carries no
        // such requirement (see Variation.php's own constructor), so it
        // is the type that lets this fixture actually reconstitute —
        // real domain code never has more than one UNIVERSAL row per
        // product, but nothing at the repository/reconstitution level
        // enforces that count, and this test only needs
        // findByProductId() to see several assignment-less rows.
        VariationModel::create([
            'product_id' => $productId,
            'type' => 'universal',
            'status' => 'draft',
            'attribute_signature' => 'no-assignments-'.$skuSuffix,
            'sku' => 'SKU-NOASSIGN-'.$skuSuffix,
            'is_visible' => false,
            'is_purchasable' => false,
        ]);
    }

    public function test_query_count_does_not_grow_with_the_number_of_variations_lacking_assignments(): void
    {
        $product = Product::createSimple('Product', 'SKU-BASE', 'product-base');
        app(ProductRepository::class)->save($product);
        // createSimple() already gives this product one UNIVERSAL
        // (assignment-less) variation; add nine more directly.
        for ($i = 1; $i <= 9; $i++) {
            $this->addAssignmentlessVariation($product->id(), (string) $i);
        }

        $queriesForTen = $this->countQueries(fn () => app(VariationRepository::class)->findByProductId($product->id()));

        $productWithOne = Product::createSimple('Product One', 'SKU-BASE-ONE', 'product-base-one');
        app(ProductRepository::class)->save($productWithOne);

        $queriesForOne = $this->countQueries(fn () => app(VariationRepository::class)->findByProductId($productWithOne->id()));

        fwrite(STDERR, "\n[query-count] 1 assignment-less variation: {$queriesForOne} queries, 10 assignment-less variations: {$queriesForTen} queries\n");

        $this->assertSame($queriesForOne, $queriesForTen, 'query count must not grow with the number of variations lacking assignments');
    }

    /** The literal comparison the task asked for: assignment-less vs. a product whose variations DO have assignment rows. */
    public function test_query_count_for_a_product_with_no_assignments_equals_a_product_with_assignment_rows(): void
    {
        $noAssignmentsProduct = Product::createSimple('No Assignments', 'SKU-NOASSIGN', 'no-assignments');
        app(ProductRepository::class)->save($noAssignmentsProduct);

        $queriesForNoAssignments = $this->countQueries(
            fn () => app(VariationRepository::class)->findByProductId($noAssignmentsProduct->id())
        );

        [$definition, $black] = $this->persistedColorAxis();
        $withAssignmentsProduct = \EasyCo\Catalog\Product::createVariable('Variable', 'SKU-VAR', 'variable-product');
        $withAssignmentsProduct->declareVariationAxes([new \EasyCo\Catalog\VariationAxis($definition, [$black])]);
        $withAssignmentsProduct->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        app(ProductRepository::class)->save($withAssignmentsProduct);

        $queriesForWithAssignments = $this->countQueries(
            fn () => app(VariationRepository::class)->findByProductId($withAssignmentsProduct->id())
        );

        fwrite(STDERR, "\n[query-count] no assignments: {$queriesForNoAssignments} queries, with assignments: {$queriesForWithAssignments} queries\n");

        $this->assertSame($queriesForNoAssignments, $queriesForWithAssignments);
    }

    /** @return array{0: \EasyCo\Catalog\AttributeDefinition, 1: \EasyCo\Catalog\AttributeValue} */
    private function persistedColorAxis(): array
    {
        $definition = new \EasyCo\Catalog\AttributeDefinition(id: null, code: 'color', name: 'Color', type: \EasyCo\Catalog\Enums\AttributeType::SELECT);
        app(\EasyCo\Catalog\Contracts\AttributeDefinitionRepository::class)->save($definition);

        $black = new \EasyCo\Catalog\AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(\EasyCo\Catalog\Contracts\AttributeValueRepository::class)->save($black);

        return [$definition, $black];
    }
}
