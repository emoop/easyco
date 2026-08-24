<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use PHPUnit\Framework\TestCase;

final class VariationCombinationGeneratorTest extends TestCase
{
    use BuildsVariationAxes;

    /**
     * Deterministic test sku strategy for generate()'s required
     * $skuForCombination callable — not the real sku-generation feature
     * (still deferred), just enough to keep these tests working now that
     * addStandardVariation() requires a sku.
     */
    private function skuForCombination(): callable
    {
        return static fn (array $combination): string => 'SKU-'.implode('-', $combination);
    }

    public function test_generates_cartesian_product_of_two_axes(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $generator = new VariationCombinationGenerator();

        // Color: Black(5), White(6) x Size: M(9), L(10) => 4 combinations
        $created = $generator->generate($product, [
            1 => [5, 6],
            2 => [9, 10],
        ], $this->skuForCombination());

        $this->assertCount(4, $created);
        $this->assertCount(4, $product->variations());
    }

    public function test_generates_cartesian_product_of_three_axes(): void
    {
        $product = Product::createVariable('Phone Case', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'material', ['11', '12', '13']),
            $this->axis('3', 'size', ['9', '10']),
        ]);
        $generator = new VariationCombinationGenerator();

        // 2 colors x 3 materials x 2 sizes = 12
        $created = $generator->generate($product, [
            1 => [5, 6],
            2 => [11, 12, 13],
            3 => [9, 10],
        ], $this->skuForCombination());

        $this->assertCount(12, $created);
    }

    public function test_single_axis_generates_one_variation_per_value(): void
    {
        $product = Product::createVariable('Mug', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6', '7'])]);
        $generator = new VariationCombinationGenerator();

        $created = $generator->generate($product, [1 => [5, 6, 7]], $this->skuForCombination());

        $this->assertCount(3, $created);
    }

    public function test_running_generation_twice_skips_already_existing_combinations(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $generator = new VariationCombinationGenerator();

        $generator->generate($product, [1 => [5, 6], 2 => [9]], $this->skuForCombination()); // 2 combos
        $this->assertCount(2, $product->variations());

        // Merchant adds one more size value (10) and re-runs generation.
        $newlyCreated = $generator->generate($product, [1 => [5, 6], 2 => [9, 10]], $this->skuForCombination());

        // Only the 2 NEW combinations (Black/L, White/L) should come back...
        $this->assertCount(2, $newlyCreated);
        // ...and the total must be 4, not 6 — no duplicates were created.
        $this->assertCount(4, $product->variations());
    }

    public function test_empty_axis_map_generates_nothing(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $generator = new VariationCombinationGenerator();

        $created = $generator->generate($product, [], $this->skuForCombination());

        $this->assertCount(0, $created);
        $this->assertCount(0, $product->variations());
    }

    public function test_undeclared_axis_is_rejected(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]); // no "material" axis declared
        $generator = new VariationCombinationGenerator();

        $this->expectException(InvalidVariationAxisException::class);
        $generator->generate($product, [
            1 => [5, 6],
            99 => [11], // "material" — never declared as an axis of this product
        ], $this->skuForCombination());
    }

    public function test_value_not_enabled_for_the_axis_is_rejected(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        // Merchant only enabled Black(5) and White(6) for this product,
        // even though "Red" might exist globally under the Color definition.
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $generator = new VariationCombinationGenerator();

        $this->expectException(InvalidVariationAxisException::class);
        $generator->generate($product, [1 => [5, 999]], $this->skuForCombination()); // 999 was never enabled
    }

    public function test_an_axis_with_an_empty_value_list_is_rejected(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $generator = new VariationCombinationGenerator();

        $this->expectException(InvalidVariationAxisException::class);
        $generator->generate($product, [1 => [5, 6], 2 => []], $this->skuForCombination()); // Size supplied but with no values at all
    }

    public function test_duplicate_values_within_one_axis_are_deduplicated_deterministically(): void
    {
        $product = Product::createVariable('Mug', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $generator = new VariationCombinationGenerator();

        // {Black, Black, White} must behave exactly like {Black, White}.
        $created = $generator->generate($product, [1 => [5, 5, 6]], $this->skuForCombination());

        $this->assertCount(2, $created);
    }

    public function test_an_invalid_value_deep_in_the_list_does_not_leave_earlier_valid_combinations_behind(): void
    {
        $product = Product::createVariable('Mug', 'SKU-1');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $generator = new VariationCombinationGenerator();

        try {
            // 5 and 6 are valid; 999 is not. Without an upfront check, the
            // cartesian product would create Color:5 and Color:6 (both
            // valid) before ever reaching 999 and failing.
            $generator->generate($product, [1 => [5, 6, 999]], $this->skuForCombination());
            $this->fail('Expected InvalidVariationAxisException was not thrown.');
        } catch (InvalidVariationAxisException) {
            // expected
        }

        $this->assertCount(0, $product->variations(), 'generate() must be all-or-nothing');
    }

    public function test_partial_generation_does_not_leave_invalid_axis_variations_behind(): void
    {
        // If Product has Color+Size declared and the generator call
        // includes an undeclared axis, the whole request is rejected — no
        // variations from the valid part of the input should be created
        // as a side effect of the rejected part.
        $product = Product::createVariable('T-Shirt', 'SKU-1');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $generator = new VariationCombinationGenerator();

        try {
            $generator->generate($product, [
                1 => [5, 6],
                2 => [9, 10],
                99 => [11], // undeclared — rejected before any addStandardVariation() call happens
            ], $this->skuForCombination());
            $this->fail('Expected InvalidVariationAxisException was not thrown.');
        } catch (InvalidVariationAxisException) {
            // expected
        }

        $this->assertCount(0, $product->variations(), 'no partial combinations should have been created');
    }
}
