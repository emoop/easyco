<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Exceptions\VariationNotRestorableException;
use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::restoreArchivedVariation() — catalog-domain-design.md
 * §3.17's explicit, merchant-facing "bring this archived variation
 * back" operation, distinct from addStandardVariation()'s own implicit
 * revival-by-signature branch (already covered by
 * ProductBaseSkuAndVariationRevivalTest). Purely in-memory — no
 * persistence layer involved; the save/reload round trip (proving a
 * restored variation persists as 'draft') is covered separately in
 * tests/Feature/EloquentProductRepositoryVariationAxisTest.php.
 */
final class ProductVariationRestoreTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_restoring_an_archived_variation_returns_it_to_draft_with_everything_else_unchanged(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-BLACK');
        $variation->activate();
        $variation->archive();

        $this->assertSame(VariationStatus::ARCHIVED, $variation->status());
        $this->assertFalse($variation->isVisible());
        $this->assertFalse($variation->isPurchasable());

        $idBefore = $variation->id();
        $skuBefore = $variation->sku();
        $barcodeBefore = $variation->barcode();
        $assignmentsBefore = $variation->attributeAssignments();
        $signatureBefore = $variation->attributeSignature();

        $product->restoreArchivedVariation($variation);

        $this->assertSame(VariationStatus::DRAFT, $variation->status());
        $this->assertSame($idBefore, $variation->id());
        $this->assertSame($skuBefore, $variation->sku());
        $this->assertSame($barcodeBefore, $variation->barcode());
        $this->assertSame($assignmentsBefore, $variation->attributeAssignments());
        $this->assertTrue($signatureBefore->equals($variation->attributeSignature()));
        // Revival deliberately does NOT restore these — archive() forced
        // them false, and the merchant re-enables them explicitly.
        $this->assertFalse($variation->isVisible());
        $this->assertFalse($variation->isPurchasable());

        // A following activate() must work normally.
        $variation->activate();
        $this->assertSame(VariationStatus::ACTIVE, $variation->status());
    }

    public function test_restoring_a_variation_belonging_to_another_product_throws(): void
    {
        $productA = Product::createVariable('T-Shirt A', 'SKU-A', 't-shirt-a');
        $productA->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variationOfA = $productA->addStandardVariation([1 => 5], 'SKU-A-BLACK');
        $variationOfA->activate();
        $variationOfA->archive();

        $productB = Product::createVariable('T-Shirt B', 'SKU-B', 't-shirt-b');
        $productB->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This Variation does not belong to this Product.');
        $productB->restoreArchivedVariation($variationOfA);
    }

    public function test_restoring_a_universal_variation_throws(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $universal = $product->universalVariation();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only a STANDARD variation can be restored from the archive.');
        $product->restoreArchivedVariation($universal);
    }

    public function test_restoring_a_draft_variation_throws(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-BLACK');

        $this->assertSame(VariationStatus::DRAFT, $variation->status());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('restoreArchivedVariation() only applies to an ARCHIVED variation.');
        $product->restoreArchivedVariation($variation);
    }

    public function test_restoring_an_active_variation_throws(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-BLACK');
        $variation->activate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('restoreArchivedVariation() only applies to an ARCHIVED variation.');
        $product->restoreArchivedVariation($variation);
    }

    /**
     * Drift path (i): the axis the archived variation depended on was
     * removed entirely while only ARCHIVED variations existed (R2 of
     * assertAxisChangeIsSafe() allows this, since no LIVE variation
     * depended on it at the time) — restoring it afterward must fail
     * loud, not silently succeed into an invalid state.
     */
    public function test_restoring_fails_when_its_own_axis_was_removed_while_only_archived_variations_existed(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-1');
        $variation->activate();
        $variation->archive();

        // Safe per R2: zero LIVE variations exist at this point.
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->expectException(VariationNotRestorableException::class);
        $product->restoreArchivedVariation($variation);
    }

    /**
     * Drift path (ii): a brand-new axis was added while only ARCHIVED
     * variations existed (R3 allows this with zero LIVE variations) —
     * the archived variation has no value for that new axis at all, so
     * restoring it must fail loud.
     */
    public function test_restoring_fails_when_a_new_axis_was_added_while_only_archived_variations_existed(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-1');
        $variation->activate();
        $variation->archive();

        // Safe per R3: zero LIVE variations exist at this point.
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        $this->expectException(VariationNotRestorableException::class);
        $product->restoreArchivedVariation($variation);
    }

    /**
     * A pure value-ADDITION to an existing axis (R5, always safe) must
     * never make an archived variation un-restorable — its own
     * assigned value is still allowed, only a new option was added
     * alongside it.
     */
    public function test_a_value_only_extension_of_an_existing_axis_does_not_make_an_archived_variation_unrestorable(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-1');
        $variation->activate();
        $variation->archive();

        // "7" added alongside "5"/"6" — "5" (this variation's own value) stays.
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6', '7'])]);

        $product->restoreArchivedVariation($variation);

        $this->assertSame(VariationStatus::DRAFT, $variation->status());
    }
}
