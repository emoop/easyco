<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontVisibilityTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private StorefrontVisibility $visibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricing();
        $this->visibility = new StorefrontVisibility();
    }

    /** status x visibility x deleted: 3 x 2 x 2 = 12 products, ids 1..12; returns [id => expectedVisible]. */
    private function productMatrix(): array
    {
        $expected = [];
        $id = 0;

        foreach (['draft', 'active', 'archived'] as $status) {
            foreach (['visible', 'hidden'] as $visibility) {
                foreach ([false, true] as $deleted) {
                    $id++;
                    $this->makeProduct($id, ['status' => $status, 'visibility' => $visibility, 'deleted' => $deleted, 'price' => '10.00', 'stock' => 1]);
                    $expected[$id] = $status === 'active' && $visibility === 'visible' && ! $deleted;
                }
            }
        }

        return $expected;
    }

    public function test_every_status_visibility_deleted_combination_only_active_visible_undeleted_is_shown(): void
    {
        $expected = $this->productMatrix();

        $byScope = $this->visibility->products(ProductModel::query())->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $byPredicate = ProductModel::withTrashed()->get()->filter(fn (ProductModel $p) => $this->visibility->isProductVisible($p))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $byRawScope = $this->visibility->products(DB::table('catalog_products'))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $wanted = array_keys(array_filter($expected));

        $this->assertSame([5], $wanted, 'exactly one combination is visible (active, visible, not deleted)');
        $this->assertSame($wanted, $byScope);
        $this->assertSame($wanted, $byPredicate, 'the predicate and the scope are the same set');
        $this->assertSame($wanted, $byRawScope, 'the raw-query scope agrees with the Eloquent one');
    }

    public function test_the_two_variation_branches_scope_and_predicate_agree_on_a_mixed_fixture(): void
    {
        // VARIABLE: standard variations in every status/visibility/deleted combination, plus a stray universal one.
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(50, ['type' => 'variable', 'variations' => [
            ['status' => 'active', 'visible' => true, 'attrs' => [1 => 'S']],          // shown
            ['status' => 'active', 'visible' => false, 'attrs' => [1 => 'M']],         // is_visible = 0
            ['status' => 'draft', 'visible' => true],                                  // draft
            ['status' => 'archived', 'visible' => true],                               // archived
            ['status' => 'active', 'visible' => true, 'deleted' => true],              // soft-deleted
            ['type' => 'universal', 'status' => 'active', 'visible' => false],         // universal on a VARIABLE product
        ]]);
        // SIMPLE: the universal variation shown on status alone; a draft one; and a stray standard one.
        $this->makeProduct(60, ['type' => 'simple']);
        $this->makeProduct(61, ['type' => 'simple', 'variations' => [['type' => 'universal', 'status' => 'draft']]]);
        $this->makeProduct(62, ['type' => 'simple', 'variations' => [
            ['type' => 'universal', 'status' => 'active'],
            ['type' => 'standard', 'status' => 'active', 'visible' => true],           // standard on a SIMPLE product
        ]]);

        $types = DB::table('catalog_products')->pluck('type', 'id');

        $byScope = $this->visibility->variations(VariationModel::query())->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $byPredicate = VariationModel::withTrashed()->get()
            ->filter(fn (VariationModel $v) => $this->visibility->isVariationShown($v, $types[$v->product_id]))
            ->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $byRawScope = $this->visibility->variations(DB::table('catalog_variations'))->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        // 5001 (shown standard), 6001 (simple universal), 6201 (simple universal active); nothing else.
        $this->assertSame([5001, 6001, 6201], $byScope);
        $this->assertSame($byScope, $byPredicate);
        $this->assertSame($byScope, $byRawScope);
    }

    public function test_purchasable_mirrors_the_domain_method_on_the_same_rows(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M', 'L']);
        $this->makeProduct(70, ['type' => 'variable', 'slug' => 'parity', 'variations' => [
            ['attrs' => [1 => 'S'], 'purchasable' => true, 'price' => '10.00'],
            ['attrs' => [1 => 'M'], 'purchasable' => false, 'price' => '10.00'],
        ]]);

        $page = app(CatalogReader::class)->product('parity');
        $domain = app(VariationRepository::class)->findByIds(['7001', '7002']);

        foreach ($page->variations as $view) {
            $this->assertSame($domain[$view->id]->isEffectivelyPurchasable(), $view->purchasable, "variation {$view->id}");
        }

        $this->assertSame([true, false], array_map(fn ($v) => $v->purchasable, $page->variations));
    }

    public function test_a_brand_and_a_tag_are_shown_only_with_a_visible_product(): void
    {
        $shown = $this->makeBrand(1, 'Shown', 'shown');
        $hiddenOnly = $this->makeBrand(2, 'Hidden only', 'hidden-only');
        $deletedOnly = $this->makeBrand(3, 'Deleted only', 'deleted-only');
        $none = $this->makeBrand(4, 'No products', 'none');
        $tagShown = $this->makeTag(1, 'Shown', 'shown');
        $tagHidden = $this->makeTag(2, 'Hidden', 'hidden');

        $this->makeProduct(1, ['brand' => $shown, 'tags' => [$tagShown]]);
        $this->makeProduct(2, ['brand' => $hiddenOnly, 'tags' => [$tagHidden], 'visibility' => 'hidden']);
        $this->makeProduct(3, ['brand' => $deletedOnly, 'deleted' => true]);
        $this->makeProduct(4, ['brand' => $shown, 'status' => 'draft']);

        $this->assertSame([$shown], $this->visibility->brands(BrandModel::query())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$tagShown], $this->visibility->tags(TagModel::query())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertNotContains($none, $this->visibility->brands(DB::table('catalog_brands'))->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_out_of_stock_is_a_fact_not_part_of_visibility(): void
    {
        $this->makeProduct(1, ['slug' => 'sold-out', 'price' => '10.00', 'stock' => 0]);

        $visible = $this->visibility->products(ProductModel::query())->pluck('id')->all();
        $page = app(CatalogReader::class)->product('sold-out');

        $this->assertCount(1, $visible);
        $this->assertNotNull($page);
        $this->assertFalse($page->inStock);
    }

    public function test_the_domain_creates_a_simple_product_the_rule_shows(): void
    {
        // A product made by the real aggregate (not raw rows): proves the rule matches what the domain writes.
        $product = Product::createSimple('Real one', 'SKU-REAL', 'real-one');
        $product->setCatalogVisibility(\EasyCo\Catalog\Enums\CatalogVisibility::VISIBLE);
        $product->publish();
        app(ProductRepository::class)->save($product);

        $id = (string) $product->id();
        $row = ProductModel::findOrFail($id);
        $variation = VariationModel::where('product_id', $id)->firstOrFail();

        $this->assertTrue($this->visibility->isProductVisible($row));
        $this->assertTrue($this->visibility->isVariationShown($variation, $row->type), 'the universal variation of a simple product');
        $this->assertContains((int) $variation->id, $this->visibility->variations(VariationModel::query())->pluck('id')->map(fn ($i) => (int) $i)->all());
    }
}
