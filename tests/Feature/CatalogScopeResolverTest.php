<?php

namespace Tests\Feature;

use App\Services\CatalogScopeResolver;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Tag;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\CatalogScopeResolver — assembles the productId/
 * matchingScopeReferenceIds shape EasyCo\Pricing\Contracts\PriceContext
 * needs for scope matching.
 */
class CatalogScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function suffix(): string
    {
        self::$counter++;

        return (string) self::$counter;
    }

    private function simpleVariationId(): string
    {
        $suffix = $this->suffix();
        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    public function test_a_variation_with_no_brand_categories_or_tags_returns_empty_matching_scope_reference_ids(): void
    {
        $variationId = $this->simpleVariationId();

        $result = app(CatalogScopeResolver::class)->forVariation($variationId);

        $this->assertNotNull($result['productId']);
        $this->assertSame([], $result['matchingScopeReferenceIds']);
    }

    public function test_a_variation_whose_product_has_a_brand_categories_and_tags_returns_all_three_populated(): void
    {
        $suffix = $this->suffix();
        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $brand = new Brand(id: null, name: 'Nike', slug: "nike-{$suffix}");
        app(BrandRepository::class)->save($brand);
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        $categoryOne = new Category(id: null, parentId: null, name: 'Shoes', slug: "shoes-{$suffix}");
        $categoryTwo = new Category(id: null, parentId: null, name: 'Running', slug: "running-{$suffix}");
        app(CategoryRepository::class)->save($categoryOne);
        app(CategoryRepository::class)->save($categoryTwo);
        app(ProductCategoryRepository::class)->save(new ProductCategory(null, $product->id(), $categoryOne->id()));
        app(ProductCategoryRepository::class)->save(new ProductCategory(null, $product->id(), $categoryTwo->id()));

        $tagOne = new Tag(id: null, name: 'Summer', slug: "summer-{$suffix}");
        app(TagRepository::class)->save($tagOne);
        app(ProductTagRepository::class)->save(new ProductTag(null, $product->id(), $tagOne->id()));

        $result = app(CatalogScopeResolver::class)->forVariation($variationId);

        $this->assertSame($product->id(), $result['productId']);
        $this->assertSame([$brand->id()], $result['matchingScopeReferenceIds']['brand']);

        $categoryIds = $result['matchingScopeReferenceIds']['category'];
        sort($categoryIds);
        $expectedCategoryIds = [$categoryOne->id(), $categoryTwo->id()];
        sort($expectedCategoryIds);
        $this->assertSame($expectedCategoryIds, $categoryIds);

        $this->assertSame([$tagOne->id()], $result['matchingScopeReferenceIds']['tag']);
        $this->assertArrayNotHasKey('attribute_value', $result['matchingScopeReferenceIds']);
    }

    public function test_attribute_value_correctly_reflects_the_variations_real_attribute_assignments(): void
    {
        $suffix = $this->suffix();

        $colorDefinition = new AttributeDefinition(id: null, code: "color-{$suffix}", name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($colorDefinition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $colorDefinition->id(), value: 'Black');
        $white = new AttributeValue(id: null, attributeDefinitionId: $colorDefinition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($black);
        app(AttributeValueRepository::class)->save($white);

        $product = Product::createVariable("Variable {$suffix}", "SKU-VAR-{$suffix}", "variable-slug-{$suffix}");
        $product->declareVariationAxes([new VariationAxis($colorDefinition, [$black, $white])]);
        $variation = $product->addStandardVariation([$colorDefinition->id() => $black->id()], "SKU-VAR-{$suffix}-BLK");
        app(ProductRepository::class)->save($product);

        $result = app(CatalogScopeResolver::class)->forVariation($variation->id());

        $this->assertSame([$black->id()], $result['matchingScopeReferenceIds']['attribute_value']);
    }

    public function test_a_nonexistent_variation_id_returns_the_null_empty_shape(): void
    {
        $result = app(CatalogScopeResolver::class)->forVariation('999999');

        $this->assertSame(['productId' => null, 'matchingScopeReferenceIds' => []], $result);
    }
}
