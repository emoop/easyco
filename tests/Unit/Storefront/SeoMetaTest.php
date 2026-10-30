<?php

namespace Tests\Unit\Storefront;

use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ProductPage;
use App\Storefront\Support\PaginationLinks;
use App\Storefront\Support\SeoMeta;
use Tests\TestCase;

class SeoMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://shop.test/', 'app.name' => 'Raf Shop']);
    }

    private function product(?string $short, ?string $description = null, string $name = 'Summer dress'): ProductPage
    {
        return new ProductPage('1', 'summer-dress', '/product/summer-dress', $name, 'simple', $short, $description, null, [], [], null, true, [], [], []);
    }

    private function listing(int $page = 1, int $last = 3, int $perPage = 24, string $sort = 'newest'): ListingPage
    {
        return new ListingPage(['sort' => $sort], [], $page, $perPage, 50, $last, []);
    }

    public function test_the_product_title_description_and_absolute_canonical(): void
    {
        $seo = (new SeoMeta())->forProduct($this->product('<p>Light &amp; airy</p>'));

        $this->assertSame('Summer dress — Raf Shop', $seo['title']);
        $this->assertSame('Light & airy', $seo['description'], 'tags stripped, entities decoded (the view escapes)');
        $this->assertSame('https://shop.test/product/summer-dress', $seo['canonical']);
    }

    public function test_the_description_is_plain_text_of_at_most_160_characters(): void
    {
        $long = (new SeoMeta())->plain('<p>'.str_repeat('word ', 100).'</p><script>alert(1)</script>');

        $this->assertLessThanOrEqual(160, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringNotContainsString('<', $long);
        $this->assertSame('Рокля с дълъг ръкав', (new SeoMeta())->plain("  Рокля\n\t с  дълъг\u{00A0}ръкав "));
        $this->assertSame(160, mb_strlen((new SeoMeta())->plain(str_repeat('я', 160))));
        $this->assertSame(160, mb_strlen((new SeoMeta())->plain(str_repeat('я', 161))));
    }

    public function test_the_description_falls_back_to_the_description_then_to_nothing(): void
    {
        $this->assertSame('From the long text', (new SeoMeta())->forProduct($this->product(null, '<p>From the long text</p>'))['description']);
        $this->assertSame('', (new SeoMeta())->forProduct($this->product(null, null))['description']);
    }

    public function test_the_category_canonical_is_its_own_page(): void
    {
        $category = new CategoryPage('2', 'Dresses', 'dresses', 'clothing/dresses', '/product-category/clothing/dresses', 5, [], []);

        $this->assertSame('https://shop.test/product-category/clothing/dresses', (new SeoMeta())->forCategory($category, $this->listing(1))['canonical']);
        $this->assertSame('https://shop.test/product-category/clothing/dresses?page=3', (new SeoMeta())->forCategory($category, $this->listing(3))['canonical']);
    }

    public function test_the_404_has_no_canonical(): void
    {
        $seo = (new SeoMeta())->forNotFound('Gone');

        $this->assertNull($seo['canonical']);
        $this->assertSame('Gone — Raf Shop', $seo['title']);
    }

    public function test_pagination_links_carry_only_non_default_whitelisted_keys(): void
    {
        $links = (new PaginationLinks())->build('/product-category/x', $this->listing(2, 3, 12, 'name_asc'));

        $this->assertSame('/product-category/x?per_page=12&sort=name_asc', $links['prev']);
        $this->assertSame('/product-category/x?page=3&per_page=12&sort=name_asc', $links['next']);
        $this->assertSame([1, 2, 3], array_column($links['pages'], 'number'));
        $this->assertSame([false, true, false], array_column($links['pages'], 'current'));

        $plain = (new PaginationLinks())->build('/product-category/x', $this->listing(1, 2));
        $this->assertNull($plain['prev']);
        $this->assertSame('/product-category/x?page=2', $plain['next']);
        $this->assertSame('/product-category/x', $plain['pages'][0]['url']);
    }

    /** CD-1a: up to seven pages all numbers; more: the first, the last and the current ± 2 with an ellipsis for a gap (was: a sliding window of seven numbers). */
    public function test_the_numbered_row_shows_the_first_the_last_and_the_current_plus_minus_two(): void
    {
        $row = fn (int $current, int $last): array => array_map(
            static fn (array $page): string => isset($page['ellipsis']) ? '…' : (string) $page['number'],
            (new PaginationLinks())->build('/x', $this->listing($current, $last))['pages'],
        );

        $this->assertSame(['1', '…', '8', '9', '10', '11', '12', '…', '20'], $row(10, 20));
        $this->assertSame(['1', '…', '18', '19', '20'], $row(20, 20));
        $this->assertSame(['1', '2', '3', '…', '20'], $row(1, 20));
    }
}
