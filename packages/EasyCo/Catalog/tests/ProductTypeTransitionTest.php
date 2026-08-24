<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\UnsafeProductTypeTransitionException;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductTypeTransitionTest extends TestCase
{
    use BuildsVariationAxes;

    private function colorAxis(): VariationAxis
    {
        return $this->axis('1', 'color', ['5', '6']);
    }

    public function test_changing_simple_to_variable_archives_but_does_not_delete_the_universal_variation(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $universalId = $product->universalVariation(); // keep reference before it's "gone" from the helper

        $product->changeToVariable();

        $this->assertSame(ProductType::VARIABLE, $product->type());
        // Still present in the collection — never deleted.
        $this->assertCount(1, $product->variations());
        $this->assertSame(VariationStatus::ARCHIVED, $product->variations()[0]->status());
        $this->assertSame($universalId, $product->variations()[0], 'must be the same object instance, not a new one');
    }

    public function test_archived_universal_variation_is_not_visible_or_purchasable(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->changeToVariable();

        $archivedUniversal = $product->variations()[0];
        $this->assertFalse($archivedUniversal->isVisible());
        $this->assertFalse($archivedUniversal->isPurchasable());
    }

    public function test_changing_simple_to_variable_is_idempotent(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');
        $product->changeToVariable();
        $product->changeToVariable(); // should not throw or double-archive weirdly

        $this->assertSame(ProductType::VARIABLE, $product->type());
        $this->assertCount(1, $product->variations());
    }

    public function test_attempt_convert_to_simple_succeeds_when_no_standard_variation_was_ever_created(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');

        $product->attemptConvertToSimple();

        $this->assertSame(ProductType::SIMPLE, $product->type());
        $this->assertNotNull($product->universalVariation());
        $this->assertSame(VariationStatus::ACTIVE, $product->universalVariation()->status());
    }

    public function test_attempt_convert_to_simple_is_refused_once_a_standard_variation_exists(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $this->expectException(UnsafeProductTypeTransitionException::class);
        $product->attemptConvertToSimple();
    }

    public function test_attempt_convert_to_simple_is_refused_even_if_the_standard_variation_was_later_archived(): void
    {
        // Archiving doesn't erase the fact that the id may already be
        // referenced by Orders/POS/Inventory — the refusal must not be
        // bypassable just by archiving first.
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);
        $variation = $product->addStandardVariation([1 => 5], 'SKU-2');
        $variation->archive();

        $this->expectException(UnsafeProductTypeTransitionException::class);
        $product->attemptConvertToSimple();
    }

    public function test_force_convert_to_simple_requires_explicit_true_confirmation(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([1 => 5], 'SKU-2');

        $this->expectException(InvalidArgumentException::class);
        $product->forceConvertToSimple(false);
    }

    public function test_force_convert_to_simple_archives_all_standard_variations_and_creates_a_fresh_universal(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->colorAxis()]);
        $product->addStandardVariation([1 => 5], 'SKU-2');
        $product->addStandardVariation([1 => 6], 'SKU-3');

        $product->forceConvertToSimple(true);

        $this->assertSame(ProductType::SIMPLE, $product->type());

        $standardVariations = array_filter(
            $product->variations(),
            static fn ($v) => $v->type() === VariationType::STANDARD
        );
        foreach ($standardVariations as $v) {
            $this->assertSame(VariationStatus::ARCHIVED, $v->status());
        }
        $this->assertCount(2, $standardVariations, 'original standard variations must still exist, just archived');

        $this->assertNotNull($product->universalVariation());
        $this->assertSame(VariationStatus::ACTIVE, $product->universalVariation()->status());
    }

    public function test_attempt_convert_to_simple_is_idempotent_when_already_simple(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->attemptConvertToSimple();

        $this->assertSame(ProductType::SIMPLE, $product->type());
        $this->assertCount(1, $product->variations());
    }
}
