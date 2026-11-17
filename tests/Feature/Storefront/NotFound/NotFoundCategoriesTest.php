<?php

namespace Tests\Feature\Storefront\NotFound;

use App\Storefront\NotFound\NotFoundSet;
use App\Storefront\NotFound\NotFoundStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/** NF-1.1: the merchant's chosen categories for the 404 tiles: the choice is shown (a sub-category too), a category without a picture is skipped, the columns follow the tiles shown, the read is validated. */
class NotFoundCategoriesTest extends TestCase
{
    use BuildsCacheShop;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
        app()->setLocale('en');
    }

    /** a top-level category `$id` with a picture and one product; a child of `$parent` when given (also with a picture and a product) */
    private function category(int $id, string $name, ?int $parent = null, bool $picture = true): void
    {
        $this->makeCategory($id, $name, 'cat-'.$id, $parent);
        $asset = $this->pickerAsset();
        $picture && DB::table('catalog_categories')->where('id', $id)->update(['picture_media_asset_id' => $asset->id]);
        $this->makeProduct(500 + $id, ['name' => "Item {$id}", 'slug' => "item-{$id}", 'categories' => [$id], 'price' => '9.00', 'stock' => 3]);
    }

    /** @param  array<string, mixed>  $over */
    private function publish(array $over): void
    {
        [$clean, $errors] = NotFoundSet::validate($over + NotFoundSet::defaults());
        $this->assertSame([], $errors, json_encode($errors));
        NotFoundStore::publish($clean);
        $this->fresh();
        Cache::flush();
    }

    private function page(): string
    {
        $this->fresh();

        return $this->get('/abvgd')->assertNotFound()->getContent();
    }

    /** @return list<string> the names of the tiles, in the order printed */
    private function names(string $html): array
    {
        preg_match_all('~<span class="sf-hp-tile-cap">([^<]*)</span>~', $html, $m);

        return $m[1];
    }

    /** ★ the chosen categories (a sub-category among them) are the tiles, in the order chosen; a category without a picture is skipped; nothing else is shown */
    public function test_the_chosen_categories_are_the_tiles(): void
    {
        $this->category(20, 'Alpha');
        $this->category(21, 'Beta');
        $this->category(22, 'Gamma', null, false); // no picture: skipped
        $this->category(23, 'Child of Beta', 21); // a sub-category, with its own picture

        $this->publish(['bottom' => 'tiles', 'categories' => [23, 22, 20]]);

        $this->assertSame(['Child of Beta', 'Alpha'], $this->names($this->page()), 'the chosen ones, the picture-less one skipped, the rest of the shop left out');
    }

    /** ★ with a choice the panel's «Брой плочки» is not used: the chosen list is shown in full (up to 12) */
    public function test_the_count_does_not_cut_a_chosen_list(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->category(30 + $i, "Cat {$i}");
        }

        $this->publish(['bottom' => 'tiles', 'tiles' => '4', 'categories' => [31, 32, 33, 34, 35]]);

        $this->assertCount(5, $this->names($this->page()));
    }

    /** an empty list is the top-level categories with the panel's count, exactly as before */
    public function test_an_empty_list_is_the_top_level_categories_with_the_count(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->category(40 + $i, "Top {$i}");
        }

        $this->publish(['bottom' => 'tiles', 'tiles' => '4', 'categories' => []]);
        $this->assertCount(4, $this->names($this->page()));
    }

    /** the columns follow the tiles shown: 2 → 2 columns (centred), 9 → 4 columns; the phone keeps 2 */
    public function test_the_columns_follow_the_tiles_shown(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->category(60 + $i, "Col {$i}");
        }

        $this->publish(['bottom' => 'tiles', 'categories' => [61, 62]]);
        $two = $this->page();
        $this->assertStringContainsString('sf-hp-tc-2', $two);
        $this->assertStringContainsString('sf-nf-tiles', $two);

        $this->publish(['bottom' => 'tiles', 'categories' => range(61, 69)]);
        $nine = $this->page();
        $this->assertStringContainsString('sf-hp-tc-4', $nine);
        $this->assertStringContainsString('sf-hp-tp-2', $nine, 'two a row on the phone');
    }

    /** read validation: a bad list is empty, bad ids and duplicates are dropped, more than 12 keep the first 12 */
    public function test_a_stored_list_is_cleaned_on_read(): void
    {
        $this->assertSame([1, 2], NotFoundSet::read(['categories' => [1, 1, 2, -4, 'x', 2.5, null]])['categories']);
        $this->assertSame([], NotFoundSet::read(['categories' => 'many'])['categories']);
        $this->assertSame([], NotFoundSet::read(['categories' => ['a' => 1]])['categories'], 'a map is not a list');
        $this->assertCount(NotFoundSet::CATEGORIES_MAX, NotFoundSet::read(['categories' => range(1, 20)])['categories']);

        [, $errors] = NotFoundSet::validate(['categories' => [1, 1]]);
        $this->assertArrayHasKey('categories', $errors, 'save refuses a duplicate');
        [, $errors] = NotFoundSet::validate(['categories' => range(1, 13)]);
        $this->assertArrayHasKey('categories', $errors, 'save refuses more than 12');
    }
}
