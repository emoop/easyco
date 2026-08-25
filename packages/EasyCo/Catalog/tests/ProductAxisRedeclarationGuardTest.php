<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::declareVariationAxes()'s new guard: refusing to change
 * axes once the Product has any STANDARD variation (any status, not just
 * ACTIVE) — same reasoning as attemptConvertToSimple()'s type-transition
 * guard, but a distinct invariant/exception (UnsafeAxisRedeclarationException,
 * not UnsafeProductTypeTransitionException). Purely in-memory — no
 * persistence layer involved; the save/reload half of this guard is
 * covered separately in
 * tests/Feature/EloquentProductRepositoryVariationAxisTest.php, which
 * needs a real repository and DB.
 */
final class ProductAxisRedeclarationGuardTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_declare_variation_axes_is_rejected_once_a_standard_variation_exists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $this->expectException(UnsafeAxisRedeclarationException::class);
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
    }

    public function test_declare_variation_axes_is_rejected_even_if_the_standard_variation_was_later_archived(): void
    {
        // Same reasoning as attemptConvertToSimple()'s guard: archiving
        // doesn't erase the fact that the variation's combination
        // depended on the current axes — checked by type, not status.
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->activate();
        $variation->archive();

        $this->expectException(UnsafeAxisRedeclarationException::class);
        $product->declareVariationAxes([$this->axis('2', 'size', ['9', '10'])]);
    }

    public function test_declare_variation_axes_still_works_normally_with_zero_variations(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');

        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->assertCount(1, $product->variationAxes());
        $this->assertCount(0, $product->variations());
    }

    public function test_declare_variation_axes_still_works_normally_when_only_the_universal_variation_exists(): void
    {
        // A SIMPLE product's Universal variation is never STANDARD, so it
        // must never trip this guard — though declareVariationAxes()
        // itself still refuses a non-VARIABLE product for the pre-existing,
        // unrelated reason (LogicException), so this exercises the guard
        // via changeToVariable() instead, which archives the Universal
        // variation but never creates a STANDARD one.
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->changeToVariable();

        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->assertCount(1, $product->variationAxes());
    }
}
