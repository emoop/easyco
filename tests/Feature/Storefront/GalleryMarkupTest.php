<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * GL-1: the product page gallery. ONE markup for every mode (the container's data-gal-* attributes and width class), the usable-media rule shared with
 * the product card, the videos among the images, the safety of the markup and the query count of the page.
 */
class GalleryMarkupTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;

        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '10.00', 'stock' => 1]);
    }

    private function setting(string $key, string $value): void
    {
        app(SiteSettingsRepository::class)->set($key, $value);
    }

    private function video(int $mediaId, int $sort, string $mime = 'video/mp4'): void
    {
        $this->makeImage($mediaId, 1, $sort, 'ready', null, [], 'video');
        DB::table('catalog_media')->where('id', $mediaId)->update(['path' => "p/{$mediaId}.mp4", 'mime' => $mime]);
    }

    private function images(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeImage($i, 1, $i);
        }
    }

    private function page(string $slug = 'summer-dress'): string
    {
        $this->app->forgetScopedInstances();
        $response = $this->get("/product/{$slug}");
        $response->assertOk();
        $this->assertFalse($response->headers->has('Set-Cookie'));

        return $response->getContent();
    }

    /** The gallery section only; the <noscript> copies of the images are left out unless asked for (they are the same photos again). */
    private function gallery(string $html, bool $withNoscript = false): string
    {
        $this->assertSame(1, preg_match('/<section class="sf-gallery.*?<\/section>/s', $html, $m), 'the page has a gallery section');

        return $withNoscript ? $m[0] : preg_replace('/<noscript>.*?<\/noscript>/s', '', $m[0]);
    }

    private function figures(string $gallery): int
    {
        return substr_count($gallery, '<figure ');
    }

    public function test_every_desktop_and_mobile_mode_is_printed_as_data_attributes_with_the_width_class(): void
    {
        $this->images(2);

        foreach (GallerySettings::DESKTOP_MODES as $desktop) {
            foreach (GallerySettings::MOBILE_MODES as $mobile) {
                $this->setting(GallerySettings::DESKTOP_MODE_KEY, $desktop);
                $this->setting(GallerySettings::MOBILE_MODE_KEY, $mobile);
                $this->setting(GallerySettings::WIDTH_PERCENT_KEY, '70');
                $this->setting(GallerySettings::ODD_LAST_KEY, 'full');
                $this->setting(GallerySettings::HOVER_ZOOM_KEY, 'medium');

                $gallery = $this->gallery($this->page());

                $this->assertStringContainsString("data-gal-desktop=\"{$desktop}\"", $gallery);
                $this->assertStringContainsString("data-gal-mobile=\"{$mobile}\"", $gallery);
                $this->assertStringContainsString('data-gal-odd="full"', $gallery);
                $this->assertStringContainsString('data-gal-zoom="medium"', $gallery);
                $this->assertMatchesRegularExpression('/<section class="sf-gallery sf-gal-w-70"/', $gallery);
            }
        }
    }

    public function test_the_defaults_and_the_fallback_of_unknown_stored_values(): void
    {
        $this->images(2);

        $defaults = $this->gallery($this->page());
        $this->assertStringContainsString('class="sf-gallery sf-gal-w-65"', $defaults);
        $this->assertStringContainsString('data-gal-desktop="grid" data-gal-mobile="carousel" data-gal-odd="same" data-gal-zoom="light"', preg_replace('/\s+/', ' ', $defaults));

        foreach ([GallerySettings::DESKTOP_MODE_KEY, GallerySettings::MOBILE_MODE_KEY, GallerySettings::WIDTH_PERCENT_KEY, GallerySettings::ODD_LAST_KEY, GallerySettings::HOVER_ZOOM_KEY] as $key) {
            $this->setting($key, '"><b>nope');
        }

        $this->assertSame($defaults, $this->gallery($this->page()), 'unknown values: the default markup, no error');
    }

    public function test_one_figure_per_usable_image_the_first_active_and_eager_the_others_lazy_and_one_thumbnail_each(): void
    {
        $this->images(5);

        $gallery = $this->gallery($this->page());

        $this->assertSame(5, $this->figures($gallery));
        $this->assertSame(1, substr_count($gallery, 'sf-gal-item is-active'));
        $this->assertMatchesRegularExpression('/<figure class="sf-gal-item is-active" data-gal-kind="image">/', $gallery);
        $this->assertSame(5, substr_count($gallery, 'class="sf-gal-img"'));
        $this->assertSame(1, substr_count($gallery, 'loading="eager" fetchpriority="high"'));
        $this->assertSame(4 + 5, substr_count($gallery, 'loading="lazy"'), '4 lazy big images + 5 lazy thumbnails');
        $this->assertSame(5, preg_match_all('/<button type="button" class="sf-gal-thumb[^"]*"[^>]*aria-label="[^"]+"/s', $gallery), 'one thumbnail button per image');
        $this->assertStringContainsString('href="https://cdn.test/storage/p/1-large.webp" data-lightbox="0"', $gallery, 'the link opens the LARGEST rendition, never the upload');
        $this->assertStringNotContainsString('p/1.jpg', $gallery);
        $this->assertMatchesRegularExpression('/<img class="sf-gal-img"[^>]*width="900"[^>]*height="675"/s', $gallery);
    }

    public function test_the_photos_after_the_first_wait_in_data_src_with_a_noscript_copy(): void
    {
        $this->images(3);

        $gallery = $this->gallery($this->page(), true);

        preg_match_all('/<img class="sf-gal-img"[^>]*>/s', $gallery, $tags);
        $this->assertCount(5, $tags[0], '3 big images + 2 noscript copies');
        $this->assertMatchesRegularExpression('/\ssrc="[^"]+1-medium\.webp"/', $tags[0][0]);
        $this->assertStringNotContainsString('data-src', $tags[0][0], 'the first image is fetched at once');

        foreach ([1, 3] as $i) {
            $this->assertStringContainsString('data-src="https://cdn.test/storage/p/'.($i === 1 ? 2 : 3).'-medium.webp"', $tags[0][$i]);
            $this->assertStringContainsString('data-srcset="', $tags[0][$i]);
            $this->assertDoesNotMatchRegularExpression('/\s(src|srcset)=/', $tags[0][$i], 'a hidden figure must not make the browser fetch it');
        }

        $this->assertSame(2, preg_match_all('/<noscript>\s*<img class="sf-gal-img"\s+src="[^"]+"\s+srcset="[^"]+"/s', $gallery), 'without JavaScript the image is the noscript copy');
    }

    public function test_the_same_markup_for_one_two_and_three_images(): void
    {
        foreach ([1, 2, 3] as $count) {
            DB::table('catalog_product_media')->delete();
            DB::table('catalog_media')->delete();
            $this->images($count);

            foreach (['same', 'full'] as $odd) {
                $this->setting(GallerySettings::ODD_LAST_KEY, $odd);
                $gallery = $this->gallery($this->page());

                $this->assertSame($count, $this->figures($gallery));
                $this->assertSame($count, preg_match_all('/<button type="button" class="sf-gal-thumb/', $gallery), 'the thumbnail row is always in the markup');
                $this->assertStringContainsString("data-gal-odd=\"{$odd}\"", $gallery);
            }
        }
    }

    public function test_an_image_whose_file_is_missing_or_that_is_not_ready_is_not_shown(): void
    {
        $this->images(3);
        DB::table('catalog_media')->where('id', 2)->update(['missing_at' => now()]);
        $this->makeImage(10, 1, 9, 'pending');

        $gallery = $this->gallery($this->page());

        $this->assertSame(2, $this->figures($gallery));
        $this->assertStringContainsString('p/1-medium.webp', $gallery);
        $this->assertStringNotContainsString('p/2-medium.webp', $gallery, 'the missing file is not shown');
        $this->assertStringContainsString('p/3-medium.webp', $gallery);
        $this->assertStringNotContainsString('p/10-', $gallery);
        $this->assertSame(2, preg_match_all('/<button type="button" class="sf-gal-thumb/', $gallery));
    }

    public function test_a_product_without_a_usable_image_keeps_the_placeholder(): void
    {
        $this->images(1);
        DB::table('catalog_media')->update(['missing_at' => now()]);

        $gallery = $this->gallery($this->page());

        $this->assertStringContainsString('sf-gal-placeholder sf-image-placeholder', $gallery);
        $this->assertSame(0, $this->figures($gallery));
        $this->assertStringNotContainsString('<dialog', $gallery);
        $this->assertStringNotContainsString('sf-gal-thumb', $gallery);
    }

    public function test_the_alt_is_the_assets_own_text_else_the_product_name_with_the_photo_number(): void
    {
        $this->makeImage(1, 1, 1, 'ready', 'Front "view"');
        $this->makeImage(2, 1, 2);

        $gallery = $this->gallery($this->page());

        $this->assertStringContainsString('alt="Front &quot;view&quot;"', $gallery);
        $this->assertStringContainsString('alt="Summer dress — '.__('storefront.gallery_photo', ['n' => 2]).'"', $gallery);
    }

    // ------------------------------------------------------------------ videos

    public function test_two_images_and_a_video_are_three_items_in_the_media_order(): void
    {
        $this->makeImage(1, 1, 1);
        $this->video(7, 2);
        $this->makeImage(2, 1, 3);

        $gallery = $this->gallery($this->page());

        $this->assertSame(3, $this->figures($gallery));
        preg_match_all('/<figure class="sf-gal-item[^"]*" data-gal-kind="(image|video)">/', $gallery, $kinds);
        $this->assertSame(['image', 'video', 'image'], $kinds[1]);
        $this->assertSame(3, preg_match_all('/<button type="button" class="sf-gal-thumb/', $gallery), 'a thumbnail for the video too');
        $this->assertMatchesRegularExpression('/data-gal-kind="video" data-gal-index="1"/', $gallery);
    }

    public function test_the_video_is_a_native_player_that_never_plays_by_itself_and_is_not_in_the_lightbox(): void
    {
        $this->makeImage(1, 1, 1);
        $this->video(7, 2);
        $this->makeImage(2, 1, 3);

        $gallery = $this->gallery($this->page());

        preg_match('/<figure class="sf-gal-item[^"]*" data-gal-kind="video">.*?<\/figure>/s', $gallery, $figure);
        $this->assertNotEmpty($figure);
        $this->assertStringContainsString('<video class="sf-gal-video" controls playsinline preload="metadata">', $figure[0], 'no poster exists for a video: the first frame, with a metadata preload');
        $this->assertStringContainsString('<source src="https://cdn.test/storage/p/7.mp4#t=0.1" type="video/mp4">', $figure[0]);
        $this->assertDoesNotMatchRegularExpression('/autoplay|loop|muted/', $figure[0]);
        $this->assertStringNotContainsString('data-lightbox', $figure[0]);
        $this->assertSame(2, substr_count($gallery, 'data-lightbox='), 'only the two images are lightbox links');
        $this->assertStringContainsString(__('storefront.gallery_counter', ['n' => 1, 'total' => 2]), $gallery, 'the lightbox counter counts images only');
    }

    public function test_a_video_whose_file_is_missing_is_not_shown(): void
    {
        $this->makeImage(1, 1, 1);
        $this->video(7, 2);
        DB::table('catalog_media')->where('id', 7)->update(['missing_at' => now()]);

        $gallery = $this->gallery($this->page());

        $this->assertSame(1, $this->figures($gallery));
        $this->assertStringNotContainsString('<video', $gallery);
        $this->assertStringNotContainsString('p/7.mp4', $gallery);
    }

    public function test_the_videos_type_is_escaped(): void
    {
        $this->makeImage(1, 1, 1);
        $this->video(7, 2, 'video/mp4"><b>x</b>');

        $gallery = $this->gallery($this->page());

        $this->assertStringNotContainsString('<b>', $gallery);
        $this->assertStringContainsString('type="video/mp4&quot;&gt;&lt;b&gt;x&lt;/b&gt;"', $gallery);
    }

    // ------------------------------------------------------------------ safety

    public function test_the_gallery_markup_is_inert(): void
    {
        DB::table('catalog_products')->where('id', 1)->update(['name' => 'Evil "><b>x</b>']);
        $this->makeImage(1, 1, 1);
        $this->makeImage(2, 1, 2);
        $this->video(7, 3);

        $html = $this->page();
        $gallery = $this->gallery($html, true);

        $this->assertStringNotContainsString('style=', $gallery);
        $this->assertStringNotContainsString('<script', $gallery);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $gallery);
        $this->assertStringNotContainsString('<b>x</b>', $gallery);
        $this->assertStringContainsString('alt="Evil &quot;&gt;&lt;b&gt;x&lt;/b&gt; — '.__('storefront.gallery_photo', ['n' => 2]).'"', $gallery);
        $this->assertSame(1, substr_count($html, '<dialog class="sf-lightbox"'), 'exactly one lightbox on the page');
        $this->assertNoActiveContent($html, 'product page');
    }

    // ------------------------------------------------------------------ queries

    public function test_the_gallery_costs_the_page_no_query_per_image_and_no_query_of_its_own_for_the_settings(): void
    {
        $this->images(1);
        $this->page();

        $count = function (): array {
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql.' '.json_encode($query->bindings);
            });
            $this->page();

            return $queries;
        };

        $one = $count();

        for ($i = 2; $i <= 8; $i++) {
            $this->makeImage($i, 1, $i);
        }
        $this->video(20, 9);

        $many = $count();

        $this->assertSame(count($one), count($many), 'the product page query count must not grow with the number of images');
        $priceSetting = array_filter($many, fn (string $sql): bool => str_contains($sql, 'site_settings') && str_contains($sql, 'site.currency_symbol_position'));
        $this->assertCount(1, $priceSetting, 'the gallery settings are fetched in the SAME query as the price display setting, which is then memoised: no query of its own');
    }
}
