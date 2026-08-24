<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationSignature;
use PHPUnit\Framework\TestCase;

/**
 * See catalog-domain-design.md §"Atomic variation combination changes".
 * Product::changeVariationCombination() is the ONLY sanctioned way to
 * change an existing Variation's attribute combination — these tests
 * cover the success path, the uniqueness-conflict path, and (most
 * importantly) that a rejected change never partially mutates the
 * variation.
 */
final class ProductCombinationMutationTest extends TestCase
{
    use BuildsVariationAxes;

    private function colorAndSizeProduct(): Product
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        return $product;
    }

    public function test_valid_combination_change_updates_assignments_and_signature_together(): void
    {
        $product = $this->colorAndSizeProduct();
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2'); // Black / M
        $oldSignature = $variation->attributeSignature();

        $product->changeVariationCombination($variation, [1 => 6, 2 => 9]); // -> White / M

        $this->assertSame([1 => 6, 2 => 9], $variation->attributeAssignments());
        $this->assertFalse($variation->attributeSignature()->equals($oldSignature));
        $this->assertTrue(
            $variation->attributeSignature()->equals(VariationSignature::forCombination([1 => 6, 2 => 9])),
            'signature must always correspond to the new assignments'
        );
    }

    public function test_changing_to_a_combination_already_used_by_another_variation_is_rejected(): void
    {
        $product = $this->colorAndSizeProduct();
        $product->addStandardVariation([1 => 6, 2 => 9], 'SKU-1B'); // White / M — already exists
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2'); // Black / M

        $this->expectException(DuplicateVariationCombinationException::class);
        $product->changeVariationCombination($variation, [1 => 6, 2 => 9]); // collides with White/M
    }

    public function test_a_variation_can_be_changed_to_its_own_existing_combination_without_a_false_conflict(): void
    {
        // Changing "to itself" (e.g. a no-op edit in a UI form) must not
        // be rejected as a duplicate against itself.
        $product = $this->colorAndSizeProduct();
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');

        $product->changeVariationCombination($variation, [1 => 5, 2 => 9]);

        $this->assertSame([1 => 5, 2 => 9], $variation->attributeAssignments());
    }

    public function test_rejected_change_leaves_the_variation_completely_untouched(): void
    {
        $product = $this->colorAndSizeProduct();
        $product->addStandardVariation([1 => 6, 2 => 9], 'SKU-1B'); // White / M — occupies the target combo
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2'); // Black / M
        $originalAssignments = $variation->attributeAssignments();
        $originalSignature = $variation->attributeSignature();

        try {
            $product->changeVariationCombination($variation, [1 => 6, 2 => 9]); // rejected: duplicate
            $this->fail('Expected DuplicateVariationCombinationException was not thrown.');
        } catch (DuplicateVariationCombinationException) {
            // expected
        }

        $this->assertSame($originalAssignments, $variation->attributeAssignments());
        $this->assertTrue($originalSignature->equals($variation->attributeSignature()));
    }

    public function test_change_to_an_undeclared_axis_is_rejected_and_leaves_variation_untouched(): void
    {
        $product = $this->colorAndSizeProduct();
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');
        $originalAssignments = $variation->attributeAssignments();

        try {
            $product->changeVariationCombination($variation, [1 => 5, 99 => 1]); // 99 = undeclared axis
            $this->fail('Expected InvalidVariationAxisException was not thrown.');
        } catch (InvalidVariationAxisException) {
            // expected
        }

        $this->assertSame($originalAssignments, $variation->attributeAssignments());
    }

    public function test_cannot_change_combination_of_a_variation_belonging_to_a_different_product(): void
    {
        $productA = $this->colorAndSizeProduct();
        $productB = $this->colorAndSizeProduct();
        $variationOfA = $productA->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');

        $this->expectException(\LogicException::class);
        $productB->changeVariationCombination($variationOfA, [1 => 6, 2 => 9]);
    }

    public function test_cannot_change_combination_of_the_universal_variation(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $universal = $product->universalVariation();

        $this->expectException(\LogicException::class);
        $product->changeVariationCombination($universal, [1 => 5]);
    }
}
