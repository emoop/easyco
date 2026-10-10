<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogReaderCategoryTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPricing();
    }

    private function reader(): CatalogReader
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class);
    }

    private function nestedWorld(): void
    {
        $clothing = $this->makeCategory(1, 'Clothing', 'clothing');
        $dresses = $this->makeCategory(2, 'Dresses', 'dresses', $clothing);
        $maxi = $this->makeCategory(3, 'Maxi', 'maxi', $dresses);
        $empty = $this->makeCategory(4, 'Empty', 'empty', $clothing);
        $onlyHidden = $this->makeCategory(5, 'Only hidden', 'only-hidden');
        $hiddenChild = $this->makeCategory(6, 'Hidden child', 'hidden-child', $onlyHidden);
        $shoes = $this->makeCategory(7, 'Shoes', 'shoes');

        $this->makeProduct(1, ['categories' => [$maxi], 'price' => '1.00']);
        $this->makeProduct(2, ['categories' => [$dresses, $maxi], 'price' => '1.00']);   // also in the child: counted once
        $this->makeProduct(3, ['categories' => [$clothing], 'price' => '1.00']);
        $this->makeProduct(4, ['categories' => [$onlyHidden, $hiddenChild], 'visibility' => 'hidden']);
        $this->makeProduct(5, ['categories' => [$shoes], 'status' => 'draft']);
        $this->makeProduct(6, ['categories' => [$shoes], 'deleted' => true]);
    }

    public function test_the_tree_shows_only_categories_with_a_visible_product_in_themselves_or_a_descendant(): void
    {
        $this->nestedWorld();

        $tree = $this->reader()->categoryTree();

        $this->assertSame(['Clothing'], array_map(fn ($n) => $n->name, $tree), 'Only hidden, Shoes (draft, deleted) and Empty are not shown');
        $clothing = $tree[0];
        $this->assertSame(['Dresses'], array_map(fn ($n) => $n->name, $clothing->children));
        $this->assertSame(['Maxi'], array_map(fn ($n) => $n->name, $clothing->children[0]->children));
        $this->assertSame('clothing/dresses/maxi', $clothing->children[0]->children[0]->path);
        $this->assertSame('/product-category/clothing/dresses/maxi', $clothing->children[0]->children[0]->url);
    }

    public function test_counts_are_distinct_visible_products_of_the_whole_subtree(): void
    {
        $this->nestedWorld();

        $tree = $this->reader()->categoryTree();

        $this->assertSame(3, $tree[0]->productCount, 'products 1, 2 and 3; product 2 is in two categories of the subtree and counts once');
        $this->assertSame(2, $tree[0]->children[0]->productCount);
        $this->assertSame(2, $tree[0]->children[0]->children[0]->productCount);
    }

    public function test_category_resolves_by_the_last_segment_and_returns_the_canonical_path(): void
    {
        $this->nestedWorld();

        $page = $this->reader()->category('wrong-parent/dresses');

        $this->assertNotNull($page);
        $this->assertSame('clothing/dresses', $page->path);
        $this->assertSame('/product-category/clothing/dresses', $page->url);
        $this->assertSame(2, $page->productCount);
        $this->assertSame(['Clothing', 'Dresses'], array_map(fn ($b) => $b->label, $page->breadcrumbs));
        $this->assertSame(['Maxi'], array_map(fn ($c) => $c->name, $page->children));
    }

    public function test_a_category_that_is_not_shown_or_unknown_is_null(): void
    {
        $this->nestedWorld();

        foreach (['empty', 'only-hidden', 'hidden-child', 'shoes', 'clothing/empty', 'nope'] as $path) {
            $this->assertNull($this->reader()->category($path), $path);
        }
    }

    public function test_a_hidden_product_is_invisible_to_the_category_counts_and_the_tree(): void
    {
        $category = $this->makeCategory(1, 'Things', 'things');
        $product = $this->makeProduct(1, ['categories' => [$category], 'price' => '1.00']);

        $this->assertSame(1, $this->reader()->categoryTree()[0]->productCount);

        DB::table('catalog_products')->where('id', $product)->update(['catalog_visibility' => 'hidden']);
        $this->assertSame([], $this->reader()->categoryTree());

        DB::table('catalog_products')->where('id', $product)->update(['catalog_visibility' => 'visible', 'status' => 'archived']);
        $this->assertSame([], $this->reader()->categoryTree());

        DB::table('catalog_products')->where('id', $product)->update(['status' => 'active', 'deleted_at' => '2026-01-01 00:00:00']);
        $this->assertSame([], $this->reader()->categoryTree());
        $this->assertNull($this->reader()->category('things'));
    }

    public function test_a_parent_cycle_in_a_hand_edited_database_cannot_loop(): void
    {
        $a = $this->makeCategory(1, 'A', 'a');
        $b = $this->makeCategory(2, 'B', 'b', $a);
        DB::table('catalog_categories')->where('id', $a)->update(['parent_id' => $b]);
        $this->makeProduct(1, ['categories' => [$a], 'price' => '1.00']);

        $reader = $this->reader();

        $this->assertIsArray($reader->categoryTree());
        $reader->category('a');
        $this->addToAssertionCount(1);
    }
}
