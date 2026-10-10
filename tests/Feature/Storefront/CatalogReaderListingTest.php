<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Exceptions\ListingOptionNotAvailable;
use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogReaderListingTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    private function listing(array $request = []): \App\Storefront\ReadModels\ListingPage
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class)->listing(ListingQuery::fromRequestArray($request));
    }

    /** @return list<string> */
    private function slugs(\App\Storefront\ReadModels\ListingPage $page): array
    {
        return array_map(fn ($card) => $card->slug, $page->items);
    }

    public function test_the_listing_shows_only_visible_products_newest_first_with_card_facts(): void
    {
        $brand = $this->makeBrand(1, 'Acme', 'acme');
        $this->makeProduct(1, ['slug' => 'old', 'timeline' => '2026-01-01 10:00:00', 'price' => '10.00', 'stock' => 1, 'brand' => $brand]);
        $this->makeProduct(2, ['slug' => 'new', 'timeline' => '2026-02-01 10:00:00', 'price' => '20.00', 'stock' => 0]);
        $this->makeProduct(3, ['slug' => 'hidden', 'visibility' => 'hidden', 'timeline' => '2026-03-01 10:00:00']);
        $this->makeProduct(4, ['slug' => 'draft', 'status' => 'draft', 'timeline' => '2026-03-01 10:00:00']);
        $this->makeProduct(5, ['slug' => 'gone', 'deleted' => true, 'timeline' => '2026-03-01 10:00:00']);

        $page = $this->listing();

        $this->assertSame(['new', 'old'], $this->slugs($page));
        $this->assertSame(2, $page->total);
        $this->assertSame([], $page->facets);
        $this->assertSame(['name' => 'Acme', 'slug' => 'acme'], $page->items[1]->brand);
        $this->assertNull($page->items[0]->brand);
        $this->assertSame([false, true], array_map(fn ($c) => $c->inStock, $page->items));
        $this->assertSame([2000, 1000], array_map(fn ($c) => $c->price->fromMinor, $page->items));
        $this->assertSame([[], []], array_map(fn ($c) => $c->badges, $page->items), 'no badge resolver in S1');
    }

    public function test_sort_by_name_and_pagination(): void
    {
        foreach ([1 => 'Charlie', 2 => 'alpha', 3 => 'Bravo'] as $id => $name) {
            $this->makeProduct($id, ['name' => $name, 'slug' => strtolower($name), 'price' => '1.00']);
        }

        $this->assertSame(['alpha', 'bravo', 'charlie'], $this->slugs($this->listing(['sort' => 'name_asc'])));

        $first = $this->listing(['sort' => 'name_asc', 'per_page' => '12']);
        $this->assertSame([1, 12, 3], [$first->page, $first->perPage, $first->total]);

        $beyond = $this->listing(['page' => '5']);
        $this->assertSame([], $beyond->items, 'a page past the end is empty, not an error');
        $this->assertSame(3, $beyond->total);
    }

    public function test_a_category_listing_includes_descendants_and_a_brand_or_tag_listing_filters(): void
    {
        $root = $this->makeCategory(1, 'Clothing', 'clothing');
        $child = $this->makeCategory(2, 'Dresses', 'dresses', $root);
        $other = $this->makeCategory(3, 'Shoes', 'shoes');
        $brand = $this->makeBrand(1, 'Acme', 'acme');
        $tag = $this->makeTag(1, 'Summer', 'summer');

        $this->makeProduct(1, ['slug' => 'in-root', 'categories' => [$root], 'price' => '1.00']);
        $this->makeProduct(2, ['slug' => 'in-child', 'categories' => [$child], 'brand' => $brand, 'tags' => [$tag], 'price' => '1.00']);
        $this->makeProduct(3, ['slug' => 'in-other', 'categories' => [$other], 'price' => '1.00']);
        $this->makeProduct(4, ['slug' => 'child-hidden', 'categories' => [$child], 'visibility' => 'hidden']);

        $this->assertEqualsCanonicalizing(['in-root', 'in-child'], $this->slugs($this->listing(['category' => (string) $root])));
        $this->assertSame(['in-child'], $this->slugs($this->listing(['category' => (string) $child])));
        $this->assertSame(['in-child'], $this->slugs($this->listing(['brand' => (string) $brand])));
        $this->assertSame(['in-child'], $this->slugs($this->listing(['tag' => (string) $tag])));
        $this->assertSame([], $this->slugs($this->listing(['category' => '999'])), 'an unknown category lists nothing');
    }

    public function test_a_category_with_only_hidden_products_lists_nothing(): void
    {
        $category = $this->makeCategory(1, 'Hidden stuff', 'hidden-stuff');
        $this->makeProduct(1, ['categories' => [$category], 'visibility' => 'hidden']);

        $this->assertSame(0, $this->listing(['category' => (string) $category])->total);
    }

    public function test_the_card_image_is_the_first_ready_image_and_a_processing_one_is_ignored(): void
    {
        $this->makeProduct(1, ['slug' => 'ready-image', 'name' => 'Ready', 'price' => '1.00']);
        $this->makeImage(1, 1, 0, status: 'processing');
        $this->makeImage(2, 1, 1, alt: 'Alt text');
        $this->makeImage(3, 1, 2);

        $this->makeProduct(2, ['slug' => 'only-processing', 'price' => '1.00']);
        $this->makeImage(4, 2, 0, status: 'processing');

        $this->makeProduct(3, ['slug' => 'no-image', 'price' => '1.00']);

        $this->makeProduct(4, ['slug' => 'empty-variants', 'price' => '1.00']);
        $this->makeImage(5, 4, 0, variants: []);

        $cards = collect($this->listing(['sort' => 'name_asc'])->items)->keyBy('slug');

        $this->assertSame('Alt text', $cards['ready-image']->image->alt);
        $this->assertStringContainsString('p/2-medium.webp', $cards['ready-image']->image->src);
        $this->assertNull($cards['only-processing']->image);
        $this->assertNull($cards['no-image']->image);
        $this->assertNull($cards['empty-variants']->image, 'a ready asset with no usable variant has no image');
    }

    public function test_a_discounted_card_carries_the_regular_price_and_a_ranged_card_has_no_upper_bound(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(1, ['slug' => 'sale', 'price' => '100.00', 'sale' => '75.00', 'stock' => 1]);
        $this->makeProduct(2, ['type' => 'variable', 'slug' => 'ranged', 'variations' => [
            ['attrs' => [1 => 'S'], 'price' => '10.00', 'stock' => 1],
            ['attrs' => [1 => 'M'], 'price' => '20.00', 'stock' => 1],
        ]]);

        $cards = collect($this->listing()->items)->keyBy('slug');

        $this->assertSame(['from_minor' => 7500, 'to_minor' => 7500, 'currency' => 'EUR', 'regular_from_minor' => 10000], $cards['sale']->price->toArray());
        $this->assertSame(1000, $cards['ranged']->price->fromMinor);
        $this->assertNull($cards['ranged']->price->toMinor, 'PriceRange exposes no maximum: a ranged card says "from"');
        $this->assertNull($cards['ranged']->price->regularFromMinor);
    }

    public function test_price_filters_and_price_sorts_are_refused_until_s7(): void
    {
        $this->makeProduct(1, ['price' => '1.00']);

        foreach ([['sort' => 'price_asc'], ['sort' => 'price_desc'], ['price_min' => '100'], ['price_max' => '900']] as $request) {
            try {
                $this->listing($request);
                $this->fail('Expected the S1 reader to refuse '.json_encode($request));
            } catch (ListingOptionNotAvailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
