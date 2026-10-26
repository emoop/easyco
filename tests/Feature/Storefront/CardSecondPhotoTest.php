<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\CatalogReader;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ListingQuery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PC-1 (data): the card's SECOND photo is the second USABLE photo of the product, in the merchant's order, with the same
 * usable rule as the first (an IMAGE, READY, not missing on disk). A card with one photo has no `second_image` key. The
 * page query stays the same for any number of cards (no query per card), and the first photo is chosen by the same rule.
 */
class CardSecondPhotoTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
    }

    private function listing(array $request = []): ListingPage
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class)->listing(ListingQuery::fromRequestArray($request));
    }

    private function card(string $slug): object
    {
        return collect($this->listing(['sort' => 'name_asc'])->items)->keyBy('slug')[$slug];
    }

    private function missing(int $mediaId): void
    {
        DB::table('catalog_media')->where('id', $mediaId)->update(['missing_at' => now()]);
    }

    public function test_a_product_with_one_photo_has_no_second_image_and_no_second_image_key(): void
    {
        $this->makeProduct(1, ['slug' => 'one', 'name' => 'One', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);

        $card = $this->card('one');

        $this->assertNotNull($card->image);
        $this->assertNull($card->secondImage);
        $this->assertArrayNotHasKey('second_image', $card->toArray(), 'a one-photo card keeps the same data as before');
    }

    public function test_the_second_photo_is_the_second_one_in_the_merchants_order(): void
    {
        $this->makeProduct(1, ['slug' => 'three', 'name' => 'Three', 'price' => '1.00']);
        $this->makeImage(30, 1, 0);
        $this->makeImage(10, 1, 1);
        $this->makeImage(20, 1, 2);

        $card = $this->card('three');

        $this->assertStringContainsString('p/30-medium.webp', $card->image->src, 'the first is sort 0');
        $this->assertStringContainsString('p/10-medium.webp', $card->secondImage->src, 'the second is sort 1, not the lowest id');
        $this->assertArrayHasKey('second_image', $card->toArray());
        $this->assertSame(['src', 'alt', 'width', 'height', 'srcset', 'sizes_hint'], array_keys($card->toArray()['second_image']), 'the same shape as the first image');
    }

    public function test_a_photo_that_is_not_ready_is_skipped_and_the_next_usable_one_is_the_second(): void
    {
        $this->makeProduct(1, ['slug' => 'processing', 'name' => 'Processing', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);
        $this->makeImage(2, 1, 1, status: 'processing');
        $this->makeImage(3, 1, 2);

        $this->assertStringContainsString('p/3-medium.webp', $this->card('processing')->secondImage->src, 'the processing one in position 2 is skipped; the next usable one is the second');
    }

    public function test_a_video_in_position_two_is_skipped(): void
    {
        $this->makeProduct(1, ['slug' => 'video', 'name' => 'Video', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);
        $this->makeImage(2, 1, 1, type: 'video');
        $this->makeImage(3, 1, 2);

        $this->assertStringContainsString('p/3-medium.webp', $this->card('video')->secondImage->src);
    }

    public function test_a_product_with_no_photo_has_no_image_and_no_second_image(): void
    {
        $this->makeProduct(1, ['slug' => 'none', 'name' => 'None', 'price' => '1.00']);

        $card = $this->card('none');

        $this->assertNull($card->image);
        $this->assertNull($card->secondImage);
    }

    public function test_a_first_photo_that_is_missing_on_disk_is_skipped_and_the_next_usable_one_is_the_main_image(): void
    {
        $this->makeProduct(1, ['slug' => 'missing-first', 'name' => 'Missing first', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);
        $this->makeImage(2, 1, 1);
        $this->makeImage(3, 1, 2);
        $this->missing(1);

        $card = $this->card('missing-first');

        $this->assertStringContainsString('p/2-medium.webp', $card->image->src, 'the missing first photo is not the main image any more');
        $this->assertStringContainsString('p/3-medium.webp', $card->secondImage->src, 'and the second is the one after it');
    }

    public function test_a_second_photo_that_is_missing_on_disk_is_skipped(): void
    {
        $this->makeProduct(1, ['slug' => 'missing-second', 'name' => 'Missing second', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);
        $this->makeImage(2, 1, 1);
        $this->makeImage(3, 1, 2);
        $this->missing(2);

        $this->assertStringContainsString('p/3-medium.webp', $this->card('missing-second')->secondImage->src);
    }

    public function test_the_same_asset_cannot_be_attached_to_one_product_twice(): void
    {
        $this->makeProduct(1, ['slug' => 'dup', 'name' => 'Dup', 'price' => '1.00']);
        $this->makeImage(1, 1, 0);

        $this->expectException(QueryException::class);
        DB::table('catalog_product_media')->insert(['product_id' => 1, 'media_id' => 1, 'sort_order' => 1]);
    }

    public function test_the_listing_query_count_is_the_same_for_24_cards_with_one_photo_and_the_same_24_with_two(): void
    {
        foreach (range(1, 24) as $id) {
            $this->makeProduct($id, ['slug' => 'p'.$id, 'name' => 'P'.$id, 'price' => '1.00']);
            $this->makeImage(100 + $id, $id, 0);
        }

        $this->countQueries(fn () => $this->listing()); // warm-up
        $onePhoto = $this->countQueries(fn () => $this->listing());

        foreach (range(1, 24) as $id) {
            $this->makeImage(200 + $id, $id, 1); // the same products, now with a second photo
        }

        $this->countQueries(fn () => $this->listing()); // warm-up
        $twoPhotos = $this->countQueries(fn () => $this->listing());

        $this->assertSame($onePhoto, $twoPhotos, 'one query for the whole list, however many cards carry a second photo');
        $this->assertNotNull($this->card('p1')->secondImage, 'and the second photos are there');
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
