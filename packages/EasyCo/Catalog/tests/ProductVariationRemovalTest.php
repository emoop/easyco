<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::removeStandardVariation() — catalog-domain-design.md
 * §3.19.3's domain half of variation deletion (G-D2).
 *
 * Purely in-memory, like the rest of this suite: removal detaches a
 * variation from the aggregate and does nothing else — no repository call,
 * no archive, no soft delete. The physical, cross-domain delete is
 * App\Services\CatalogDeletion's job and is covered by
 * tests/Feature/CatalogDeletionTest.php, whose "re-add the same
 * combination" test is the persistence-level counterpart of the
 * combination-freeing test below.
 */
final class ProductVariationRemovalTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_removing_a_standard_variation_detaches_only_that_variation(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $black = $product->addStandardVariation([1 => 5], 'SKU-BLACK');
        $white = $product->addStandardVariation([1 => 6], 'SKU-WHITE');

        $product->removeStandardVariation($black);

        $remaining = $product->variations();

        $this->assertCount(1, $remaining);
        $this->assertSame($white, $remaining[0]);

        // The removed variation is DETACHED, never mutated: no status
        // change, no id loss, no sku rewrite. Archiving has its own
        // operation (Variation::archive()); this one is not it.
        $this->assertSame('SKU-BLACK', $black->sku());
        $this->assertSame([1 => 5], $black->attributeAssignments());
        $this->assertSame([$white], $remaining);
    }

    /**
     * The whole point of the operation for the change-axes flow (stage 4):
     * once detached, the (product_id, attribute_signature) slot is free
     * again INSIDE the same aggregate instance, so a replacement for the
     * same combination can be added without a duplicate-combination
     * failure — and it is a genuinely new variation, not the removed one.
     */
    public function test_the_removed_combination_can_immediately_be_added_back_as_a_new_variation(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $black = $product->addStandardVariation([1 => 5], 'SKU-BLACK');

        $product->removeStandardVariation($black);

        $replacement = $product->addStandardVariation([1 => 5], 'SKU-BLACK-NEW');

        $this->assertNotSame($black, $replacement);
        $this->assertSame('SKU-BLACK-NEW', $replacement->sku());
        $this->assertCount(1, $product->variations());
        $this->assertSame($replacement, $product->variations()[0]);
    }

    public function test_removing_a_variation_belonging_to_another_product_throws(): void
    {
        $productA = Product::createVariable('T-Shirt A', 'SKU-A', 't-shirt-a');
        $productA->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variationOfA = $productA->addStandardVariation([1 => 5], 'SKU-A-BLACK');

        $productB = Product::createVariable('T-Shirt B', 'SKU-B', 't-shirt-b');
        $productB->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This Variation does not belong to this Product.');
        $productB->removeStandardVariation($variationOfA);
    }

    public function test_removing_a_universal_variation_throws_and_leaves_the_product_untouched(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-S', 'nike-air-max');
        $universal = $product->universalVariation();

        $this->assertNotNull($universal);

        try {
            $product->removeStandardVariation($universal);
            $this->fail('A UNIVERSAL variation must not be removable on its own.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Only a STANDARD variation can be removed on its own', $e->getMessage());
        }

        // G-D2: it is deleted only together with its Product, so the
        // failed attempt must not have detached it either.
        $this->assertCount(1, $product->variations());
        $this->assertSame($universal, $product->variations()[0]);
    }
}
