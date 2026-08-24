<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Product;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the domain-rule changes that introduced Product::baseSku(),
 * required Variation skus, and archived-variation revival in
 * Product::addStandardVariation() — see Variation::reviveFromArchive() and
 * catalog-domain-design.md for the surrounding model.
 */
final class ProductBaseSkuAndVariationRevivalTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_creating_a_simple_product_requires_a_non_empty_base_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Product::createSimple('Nike Air Max', '', 'nike-air-max');
    }

    public function test_creating_a_variable_product_requires_a_non_empty_base_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Product::createVariable('T-Shirt', '', 't-shirt');
    }

    public function test_universal_variations_sku_is_exactly_the_base_sku_with_no_suffix(): void
    {
        $product = Product::createSimple('Nike Air Max', 'NIKE-AIRMAX', 'nike-air-max');

        $this->assertSame('NIKE-AIRMAX', $product->universalVariation()->sku());
    }

    public function test_a_fresh_universal_variation_created_by_attempt_convert_to_simple_also_uses_the_base_sku(): void
    {
        $product = Product::createVariable('T-Shirt', 'TSHIRT-BASE', 't-shirt');

        $product->attemptConvertToSimple();

        $this->assertSame('TSHIRT-BASE', $product->universalVariation()->sku());
    }

    public function test_adding_a_standard_variation_requires_a_non_empty_sku(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->expectException(InvalidArgumentException::class);
        $product->addStandardVariation([1 => 5], '');
    }

    public function test_reviving_a_directly_archived_variation_via_add_standard_variation_reuses_its_identity(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $original = $product->addStandardVariation([1 => 5], 'ORIGINAL-SKU');
        $original->activate();
        $original->archive();

        $this->assertSame(VariationStatus::ARCHIVED, $original->status());

        // Re-adding the exact same combination must revive $original rather
        // than create a second Variation — and the freshly-supplied sku
        // must be ignored in favor of the one $original already had.
        $revived = $product->addStandardVariation([1 => 5], 'A-DIFFERENT-SKU');

        $this->assertSame($original, $revived, 'must be the exact same object, not a new Variation');
        $this->assertSame(VariationStatus::DRAFT, $revived->status());
        $this->assertSame('ORIGINAL-SKU', $revived->sku(), 'the original sku must be kept, not the newly-supplied one');
        $this->assertCount(1, $product->variations(), 'reviving must not create a second row/object for the same slot');
    }

    public function test_reviving_does_not_apply_to_a_non_archived_variation_with_the_same_combination(): void
    {
        // A DRAFT/ACTIVE variation occupying this combination must still be
        // treated as a genuine in-memory duplicate, not something to
        // "revive" — revival is reserved for ARCHIVED variations only.
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $this->expectException(\EasyCo\Catalog\Exceptions\DuplicateVariationCombinationException::class);
        $product->addStandardVariation([1 => 5], 'SKU-3');
    }

    public function test_revive_from_archive_directly_transitions_archived_to_draft(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->archive();

        $variation->reviveFromArchive();

        $this->assertSame(VariationStatus::DRAFT, $variation->status());
    }

    public function test_revive_from_archive_refuses_a_variation_that_is_not_archived(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2'); // DRAFT, never archived

        $this->expectException(LogicException::class);
        $variation->reviveFromArchive();
    }

    public function test_activate_still_refuses_a_directly_archived_variation_revive_did_not_loosen_it(): void
    {
        // reviveFromArchive() is a distinct, separate operation from
        // activate() — activate() must keep refusing ARCHIVED -> ACTIVE
        // directly, exactly as before this change.
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->activate();
        $variation->archive();

        $this->expectException(LogicException::class);
        $variation->activate();
    }
}
