<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException;
use EasyCo\Catalog\Product;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductStandardVariationTest extends TestCase
{
    use BuildsVariationAxes;

    private function colorOnlyProduct(): Product
    {
        $product = Product::createVariable('Mug', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        return $product;
    }

    private function colorAndSizeProduct(): Product
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        return $product;
    }

    public function test_adding_a_standard_variation_to_a_variable_product(): void
    {
        $product = $this->colorAndSizeProduct();

        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2'); // Color:Black, Size:M

        $this->assertSame(VariationType::STANDARD, $variation->type());
        $this->assertSame(VariationStatus::DRAFT, $variation->status(), 'new variations start as DRAFT until the merchant confirms them');
        $this->assertCount(1, $product->variations());
        $this->assertSame([1 => 5, 2 => 9], $variation->attributeAssignments());
    }

    public function test_cannot_add_standard_variation_to_a_simple_product(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1');

        $this->expectException(LogicException::class);
        $product->addStandardVariation([1 => 5], 'SKU-2');
    }

    public function test_adding_the_same_combination_twice_is_rejected_in_memory(): void
    {
        $product = $this->colorAndSizeProduct();
        $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');

        $this->expectException(DuplicateVariationCombinationException::class);
        $product->addStandardVariation([2 => 9, 1 => 5], 'SKU-3'); // same combination, different key order
    }

    public function test_different_combinations_are_both_accepted(): void
    {
        $product = $this->colorAndSizeProduct();
        $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');  // Black / M
        $product->addStandardVariation([1 => 5, 2 => 10], 'SKU-3'); // Black / L

        $this->assertCount(2, $product->variations());
    }

    public function test_activate_moves_a_draft_variation_to_active(): void
    {
        $product = $this->colorOnlyProduct();
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');

        $variation->activate();

        $this->assertSame(VariationStatus::ACTIVE, $variation->status());
    }

    public function test_archived_variation_cannot_be_reactivated(): void
    {
        $product = $this->colorOnlyProduct();
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->activate();
        $variation->archive();

        $this->expectException(LogicException::class);
        $variation->activate();
    }

    public function test_archiving_forces_not_visible_and_not_purchasable(): void
    {
        $product = $this->colorOnlyProduct();
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->activate();
        $variation->setVisible(true);
        $variation->setPurchasable(true);

        $variation->archive();

        $this->assertFalse($variation->isVisible());
        $this->assertFalse($variation->isPurchasable());
        $this->assertFalse($variation->isEffectivelyPurchasable());
    }

    public function test_draft_variation_is_never_effectively_purchasable_even_if_flag_is_true(): void
    {
        $product = $this->colorOnlyProduct();
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->setPurchasable(true);

        $this->assertFalse($variation->isEffectivelyPurchasable(), 'DRAFT must never be sellable regardless of the flag');
    }

    public function test_duplicate_axis_assignment_is_structurally_impossible(): void
    {
        // A combination is a PHP map keyed by attribute_definition_id, so
        // "assign Color twice" cannot even be expressed — a literal with a
        // repeated key just keeps the last value; there is no way for two
        // different values to reach validation under the same axis. This
        // test documents that guarantee rather than exercising a runtime
        // check (there is nothing to check against).
        $product = $this->colorOnlyProduct();

        $combinationWithRepeatedKey = [1 => 5, 1 => 6];
        $variation = $product->addStandardVariation($combinationWithRepeatedKey, 'SKU-2');

        $this->assertSame([1 => 6], $variation->attributeAssignments(), 'PHP itself collapses the duplicate key before our code runs');
    }
}
