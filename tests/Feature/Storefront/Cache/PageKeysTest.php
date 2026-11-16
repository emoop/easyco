<?php

namespace Tests\Feature\Storefront\Cache;

use App\Storefront\Cache\SurrogateKeys;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P8b: the keys a page carries (storefront-cache-contract.md §2): its subjects and exactly the products of the cards it renders, with no new query. */
class PageKeysTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
    }

    /** @param  list<string>  $expected */
    private function assertKeys(string $url, array $expected): void
    {
        $actual = $this->xkeys($url);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, $url);
    }

    public function test_a_category_listing_carries_its_keys_and_exactly_the_products_of_its_cards(): void
    {
        // dresses (c2, under c1) lists products 1 and 2, never product 3 (which is in clothing itself)
        $this->assertKeys('/product-category/clothing/dresses', ['all', 'c1', 'c2', 'p1', 'p2']);
        // clothing shows its descendants' products too
        $this->assertKeys('/product-category/clothing', ['all', 'c1', 'p1', 'p2', 'p3']);
    }

    public function test_a_subject_page_carries_its_key_and_its_cards(): void
    {
        $this->assertKeys('/brand/acme', ['all', 'b1', 'p1']);
        $this->assertKeys('/product-tag/new', ['all', 't1', 'p1']);
    }

    public function test_the_home_page_without_a_layout_carries_home_and_its_cards(): void
    {
        $this->assertKeys('/', ['all', 'home', 'p1', 'p2', 'p3']);
    }

    /** ★ every grid of the layout puts the products of ITS cards on the page, and no other */
    public function test_the_home_page_carries_the_products_of_every_grid(): void
    {
        $this->makeTag(2, 'Sale', 'sale');
        $this->makeProduct(4, ['name' => 'Delta', 'slug' => 'delta', 'tags' => [2], 'price' => '9.00', 'stock' => 5]);
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [
            ['type' => 'product_grid', 'settings' => ['source' => 'category', 'entity' => 2]],
            ['type' => 'product_grid', 'settings' => ['source' => 'tag', 'entity' => 2]],
        ]]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
        $this->fresh();

        // grid 1 = the dresses (1, 2), grid 2 = the tag «Sale» (4); product 3 is in no grid
        $this->assertKeys('/', ['all', 'home', 'p1', 'p2', 'p4']);
    }

    public function test_a_product_page_carries_its_subjects_and_the_cards_of_its_similar_products(): void
    {
        $this->assertKeys('/product/alpha', ['all', 'p1', 'b1', 's1', 'c1', 'c2']);

        // «Подобни продукти» (the slot under the gallery) reads through the same reader method
        $this->fresh();
        app(CatalogReader::class)->homeProducts('similar', null, 'newest', 4, '100vw', null, 1);

        $this->assertContains('p2', app(SurrogateKeys::class)->all(), 'the similar product is a card of this page');
        $this->assertNotContains('p3', app(SurrogateKeys::class)->all());
    }

    public function test_an_unknown_key_is_ignored(): void
    {
        $keys = new SurrogateKeys;
        $keys->add('p12', 'evil key', 'P1', 'c', 'p12; drop', 'col3');

        $this->assertSame(['p12', 'col3'], $keys->all());
    }
}
