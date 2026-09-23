<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Product;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::declareVariationAxes()'s DIRECTIONAL compatibility
 * guard (catalog-domain-design.md §3.17), which replaced the old,
 * coarse "refuse ANY re-declaration once a STANDARD variation has ever
 * existed" rule this file's own tests used to encode. Purely in-memory
 * — no persistence layer involved; the save/reload half of this guard
 * is covered separately in
 * tests/Feature/EloquentProductRepositoryVariationAxisTest.php, which
 * needs a real repository and DB.
 *
 * TWO TESTS BELOW ARE REPLACEMENTS, NOT ADDITIONS — the old coarse
 * behaviour they encoded is a specified, deliberate behaviour change,
 * not a test being loosened to make it pass:
 *   - test_declare_variation_axes_is_rejected_once_a_standard_variation_exists
 *     -> test_redeclaring_the_identical_axis_set_is_allowed_once_a_standard_variation_exists
 *   - test_declare_variation_axes_is_rejected_even_if_the_standard_variation_was_later_archived
 *     -> test_redeclaring_a_different_axis_set_is_refused_while_a_live_standard_variation_exists
 */
final class ProductAxisRedeclarationGuardTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_redeclaring_the_identical_axis_set_is_allowed_once_a_standard_variation_exists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        // R1: same definition, same value set (fresh objects, same ids) —
        // a no-op, must not throw.
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->assertCount(1, $product->variationAxes());
        $this->assertCount(1, $product->variations());
    }

    /**
     * The identical-set no-op (R1) must hold even with only an ARCHIVED
     * standard variation on file — archived or live, an unchanged set
     * changes nothing either way.
     */
    public function test_redeclaring_the_identical_axis_set_is_allowed_with_only_an_archived_standard_variation(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->activate();
        $variation->archive();

        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->assertCount(1, $product->variationAxes());
    }

    public function test_redeclaring_a_different_axis_set_is_refused_while_a_live_standard_variation_exists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $this->expectException(UnsafeAxisRedeclarationException::class);
        // A genuinely different set: axis "1" removed entirely, axis "2"
        // introduced instead — trips R2 (and would trip R3 too, but R2
        // is checked first).
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

    /**
     * R5's own real-world motivating case: enabling a new value on an
     * existing axis (e.g. adding "XL" to "Size") is allowed even with a
     * LIVE variation on file, and that variation is left byte-for-byte
     * untouched — its own combination never depended on the axis
     * growing a new option. A subsequent addStandardVariation() for the
     * newly-enabled value must also succeed.
     */
    public function test_adding_a_value_to_an_existing_axis_is_allowed_with_a_live_variation_and_leaves_it_unchanged(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-BLACK');

        $assignmentsBefore = $variation->attributeAssignments();
        $signatureBefore = $variation->attributeSignature();
        $statusBefore = $variation->status();

        // "7" is a brand-new value added to the SAME axis "1"/"color".
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6', '7'])]);

        $this->assertSame($assignmentsBefore, $variation->attributeAssignments());
        $this->assertTrue($signatureBefore->equals($variation->attributeSignature()));
        $this->assertSame($statusBefore, $variation->status());

        $newVariation = $product->addStandardVariation([1 => 7], 'SKU-NEW-VALUE');
        $this->assertSame([1 => 7], $newVariation->attributeAssignments());
        $this->assertCount(2, $product->variations());
    }

    /**
     * R2: removing an axis entirely is refused while a live variation
     * depends on it — the exception message must list that live
     * variation's own id, an actionable detail, not merely a generic
     * refusal.
     */
    public function test_removing_an_axis_is_refused_with_a_live_standard_variation_and_lists_its_id(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-1');

        try {
            // Axis "2" ("size") is dropped entirely.
            $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
            $this->fail('Expected UnsafeAxisRedeclarationException was not thrown.');
        } catch (UnsafeAxisRedeclarationException $e) {
            $this->assertStringContainsString((string) $variation->id(), $e->getMessage());
        }
    }

    /** R2's own release valve: the same axis removal is allowed once that dependent variation is archived. */
    public function test_removing_an_axis_is_allowed_once_the_dependent_variation_is_archived(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
        $variation = $product->addStandardVariation([1 => 5, 2 => 9], 'SKU-1');
        $variation->activate();
        $variation->archive();

        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);

        $this->assertCount(1, $product->variationAxes());
    }

    /** R3: introducing a brand-new axis is refused while any live variation exists. */
    public function test_adding_a_new_axis_is_refused_with_a_live_standard_variation(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-1');

        $this->expectException(UnsafeAxisRedeclarationException::class);
        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);
    }

    /** R3's own release valve: adding a new axis is allowed once every standard variation is archived. */
    public function test_adding_a_new_axis_is_allowed_once_every_standard_variation_is_archived(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-1');
        $variation->activate();
        $variation->archive();

        $product->declareVariationAxes([
            $this->axis('1', 'color', ['5', '6']),
            $this->axis('2', 'size', ['9', '10']),
        ]);

        $this->assertCount(2, $product->variationAxes());
    }

    /** R4: removing a value a live variation actually uses is refused. */
    public function test_removing_a_value_a_live_variation_uses_is_refused(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-1');

        $this->expectException(UnsafeAxisRedeclarationException::class);
        // "5" (used by the live variation) is dropped; "6" stays.
        $product->declareVariationAxes([$this->axis('1', 'color', ['6'])]);
    }

    /** R4: removing a value NO variation uses at all is allowed. */
    public function test_removing_a_value_no_variation_uses_is_allowed(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $product->addStandardVariation([1 => 5], 'SKU-1');

        // "6" was never used by any variation — safe to drop.
        $product->declareVariationAxes([$this->axis('1', 'color', ['5'])]);

        $this->assertSame(['5'], $product->variationAxes()[0]->allowedValueIds());
    }

    /** R4: removing a value only an ARCHIVED variation uses is allowed — archived variations never count. */
    public function test_removing_a_value_only_an_archived_variation_uses_is_allowed(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $archived = $product->addStandardVariation([1 => 5], 'SKU-ARCHIVED');
        $archived->activate();
        $archived->archive();
        $product->addStandardVariation([1 => 6], 'SKU-LIVE');

        // "5" is used only by the now-archived variation.
        $product->declareVariationAxes([$this->axis('1', 'color', ['6'])]);

        $this->assertSame(['6'], $product->variationAxes()[0]->allowedValueIds());
    }
}
