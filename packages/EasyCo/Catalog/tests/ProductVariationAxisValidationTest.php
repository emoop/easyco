<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

/**
 * Covers the "every attribute used by a Variation must be a declared axis
 * of that Product, with a value the merchant actually enabled for it"
 * invariant — the main gap this hardening pass closes. See
 * catalog-domain-design.md §"Variation attribute validation".
 */
final class ProductVariationAxisValidationTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_valid_axis_value_assignment_is_accepted(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-2');

        $this->assertSame([1 => 5, 2 => 9], $variation->attributeAssignments());
    }

    public function test_attribute_not_declared_as_an_axis_is_rejected(): void
    {
        // Product declares Color + Size as its axes. "Material" (id 3) was
        // never declared as an axis at all — this is the exact example
        // from the architect's brief: Material=Cotton, Color=Black is
        // invalid because Material isn't a declared axis.
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        $this->expectException(InvalidVariationAxisException::class);
        $product->addStandardVariation([1 => 5, 3 => 20], 'SKU-2'); // 3 = Material, undeclared
    }

    public function test_value_belonging_to_the_wrong_definition_or_never_enabled_is_rejected(): void
    {
        // Color axis was declared with only Black(5)/White(6) enabled.
        // Value 20 might be a perfectly real AttributeValue somewhere
        // (e.g. under Material), but it was never enabled for this
        // product's Color axis, so it must be rejected exactly the same
        // way a genuinely cross-definition value would be.
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->expectException(InvalidVariationAxisException::class);
        $product->addStandardVariation([1 => 20], 'SKU-2');
    }

    public function test_combination_missing_a_declared_axis_is_rejected(): void
    {
        // Product declares Color + Size; supplying only Color is an
        // incomplete/invalid combination.
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        $this->expectException(InvalidVariationAxisException::class);
        $product->addStandardVariation([1 => 5], 'SKU-2'); // Size missing entirely
    }

    public function test_combination_with_no_axes_declared_yet_is_rejected(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        // declareVariationAxes() was never called.

        $this->expectException(InvalidVariationAxisException::class);
        $product->addStandardVariation([1 => 5], 'SKU-2');
    }

    public function test_redeclaring_axes_replaces_the_previous_declaration(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        // Merchant changes their mind: Size instead of Color.
        $product->declareVariationAxes([$this->axis('2', 'size', ['9', '10'])]);

        $this->expectException(InvalidVariationAxisException::class);
        $product->addStandardVariation([1 => 5], 'SKU-2'); // Color is no longer declared
    }

    public function test_declaring_the_same_axis_twice_in_one_call_is_rejected(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');

        $this->expectException(\LogicException::class);
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('1', 'color', ['7', '8']), // same attribute_definition_id again
        ]);
    }

    public function test_only_a_variable_product_can_declare_variation_axes(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1');

        $this->expectException(\LogicException::class);
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
    }
}
