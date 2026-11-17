<?php

namespace Tests\Feature\Storefront\NotFound;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\NotFound\NotFoundSet;
use App\Storefront\NotFound\NotFoundStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\Cache\BuildsCacheShop;
use Tests\TestCase;

/**
 * NF-1: «Дизайн» → «Страница 404»: what the storefront 404 shows (every path that answers it), each «Съдържание отдолу» value, the search switch, validation on read, the draft and the signed preview, the markup rules and
 * the cost (a warm 404 costs no catalog query; the fragment follows the `all` and `sitemap` versions).
 */
class NotFoundPageTest extends TestCase
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

    /** `$count` more top-level categories with one product each and a picture (the shop already has `Clothing`): the tiles need both */
    private function categories(int $count): void
    {
        $asset = $this->pickerAsset();
        DB::table('catalog_categories')->where('id', 1)->update(['picture_media_asset_id' => $asset->id]);

        for ($i = 1; $i <= $count; $i++) {
            $id = 100 + $i;
            $this->makeCategory($id, 'Cat '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'cat-'.$i);
            DB::table('catalog_categories')->where('id', $id)->update(['picture_media_asset_id' => $asset->id]);
            $this->makeProduct(100 + $i, ['name' => 'Prod '.$i, 'slug' => 'prod-'.$i, 'categories' => [$id], 'price' => '9.00', 'stock' => 3]);
        }

        $this->fresh();
        Cache::flush();
    }

    /** @param  array<string, mixed>  $over */
    private function publish(array $over): void
    {
        [$clean, $errors] = NotFoundSet::validate($over + NotFoundSet::defaults());
        $this->assertSame([], $errors, json_encode($errors));
        NotFoundStore::publish($clean);
        $this->flushPurge();
    }

    /** a test is not a request that terminates: the collected keys are flushed by hand (the versions move) */
    private function flushPurge(): void
    {
        app(CachePurgeCollector::class)->flush();
        $this->fresh();
    }

    private function page(string $url = '/abvgd'): string
    {
        $this->fresh();

        return $this->get($url)->assertNotFound()->getContent();
    }

    private function tiles(string $html): int
    {
        return substr_count($html, 'class="sf-hp-tile"');
    }

    // ---- 1. the defaults --------------------------------------------------------------------------------------------------------------

    /** ★ nothing published: the default title, text, search form, the category tiles (8 of the 14), the home button; status 404 on the same URL */
    public function test_the_default_404_page(): void
    {
        $this->categories(14);
        $this->fresh();
        $response = $this->get('/abvgd');

        $response->assertNotFound();
        $this->assertFalse($response->isRedirection());
        $html = $response->getContent();

        $this->assertStringContainsString('<h1 class="sf-nf-title">Page not found</h1>', $html);
        $this->assertStringContainsString(e((string) __('design.notfound.default_text')), $html);
        $this->assertStringContainsString('class="sf-nf-search', $html);
        $this->assertSame(8, $this->tiles($html), 'the default is 8 category tiles');
        $this->assertStringContainsString('Go to the home page', $html);
        $this->assertStringContainsString('<title>Page not found', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    /** every path that answers the storefront 404 shows the same content */
    public function test_every_404_path_shows_the_same_content(): void
    {
        $this->publish(['title' => 'Nothing here', 'bottom' => 'none']);

        foreach (['/abvgd', '/some/deep/unknown', '/product/does-not-exist', '/product-category/nope', '/info/nope', '/brand/nope', '/order/received/bad-link'] as $url) {
            $this->assertStringContainsString('<h1 class="sf-nf-title">Nothing here</h1>', $this->page($url), $url);
        }
    }

    // ---- 2. each «Съдържание отдолу» -------------------------------------------------------------------------------------------------

    public function test_the_bottom_none_prints_nothing_below(): void
    {
        $this->categories(3);
        $this->publish(['bottom' => 'none']);

        $this->assertStringNotContainsString('sf-nf-bottom', $this->page());
    }

    public function test_the_tiles_are_4_6_8_or_12_of_the_top_level_categories(): void
    {
        $this->categories(14);

        foreach (['4' => 4, '6' => 6, '8' => 8, '12' => 12] as $count => $expected) {
            $this->publish(['bottom' => 'tiles', 'tiles' => $count]);

            $html = $this->page();
            $this->assertSame($expected, $this->tiles($html), "{$count} tiles");
            $this->assertStringContainsString('sf-hp-tp-2', $html, 'two a row on the phone');
        }
    }

    public function test_the_product_grid_uses_the_home_grid_settings(): void
    {
        $this->categories(6);
        $this->publish(['bottom' => 'products', 'products' => ['source' => 'newest', 'count' => '4', 'sort' => 'name_asc'] + NotFoundSet::productDefaults()]);

        $html = $this->page();

        $this->assertStringContainsString('sf-hp-products', $html);
        $this->assertSame(4, substr_count($html, '<article class="sf-card"'), 'four cards');
        $this->assertStringNotContainsString('sf-hp-tile', $html);
    }

    /** ★ an HTML block is printed through the sanitiser; a block that is gone prints nothing */
    public function test_an_html_block_is_sanitised_and_a_missing_block_prints_nothing(): void
    {
        // inserted RAW (the model cleans on save): a hand-edited database must not bring markup in
        $id = (int) DB::table('html_blocks')->insertGetId(['title' => 'Promo', 'body' => '<p>Hello promo</p><script>alert(1)</script><img src="/storage/x.jpg" onerror="alert(2)" alt="x">', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $this->publish(['bottom' => 'block', 'block' => $id]);

        $html = $this->page();

        $this->assertStringContainsString('Hello promo', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('onerror', $html);

        DB::table('html_blocks')->where('id', $id)->delete();
        $this->publish(['bottom' => 'block', 'block' => $id]);
        Cache::flush();

        $html = $this->page();
        $this->assertStringNotContainsString('sf-nf-bottom', $html);
        $this->assertStringNotContainsString('Hello promo', $html);
    }

    // ---- 3. the search switch ---------------------------------------------------------------------------------------------------------

    public function test_the_search_form_needs_the_switch_and_the_shop_search(): void
    {
        $this->publish(['search' => true]);
        config(['storefront.search.enabled' => false]);
        $this->assertStringNotContainsString('class="sf-nf-search', $this->page(), 'switch on, search off');

        config(['storefront.search.enabled' => true]);
        $this->assertStringContainsString('class="sf-nf-search', $this->page(), 'switch on, search on');

        $this->publish(['search' => false]);
        $this->assertStringNotContainsString('class="sf-nf-search', $this->page(), 'switch off');
    }

    // ---- 4. validation on save and on read --------------------------------------------------------------------------------------------

    public function test_save_refuses_bad_values_with_messages_by_path(): void
    {
        [, $errors] = NotFoundSet::validate(['title' => str_repeat('a', 81), 'text' => str_repeat('b', 301), 'bottom' => 'zzz', 'tiles' => '5', 'search' => 'yes', 'products' => ['source' => 'nope'], 'block' => 0, 'button' => 1, 'extra' => 1]);

        foreach (['title', 'text', 'bottom', 'tiles', 'search', 'button', 'block', 'products.source'] as $path) {
            $this->assertArrayHasKey($path, $errors, $path);
        }

        [, $errors] = NotFoundSet::validate(['title' => '', 'bottom' => 'block']);
        $this->assertArrayHasKey('title', $errors);
        $this->assertArrayHasKey('block', $errors, 'the HTML block must be chosen');

        [, $errors] = NotFoundSet::validate(['bottom' => 'products', 'products' => ['source' => 'category'] + NotFoundSet::productDefaults()]);
        $this->assertArrayHasKey('products.entity', $errors, 'a source that needs an item');
    }

    /** a stored value is written straight into the compiled copy, as a hand-edited database would */
    private function store(mixed $stored): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode(['hash' => 'x', 'zones' => ['notfound' => $stored]]));
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->fresh();
    }

    /** ★ junk in every field reads as the defaults; the text is cut to 300; HTML in the text is printed escaped */
    public function test_a_stored_set_is_validated_again_on_read(): void
    {
        $this->categories(3);
        $this->store(['title' => ['x'], 'text' => 7, 'search' => 'yes', 'bottom' => 'zzz', 'tiles' => '5', 'products' => 'x', 'block' => -3, 'button' => 5, 'unknown' => 1]);

        $html = $this->page();
        $this->assertStringContainsString('<h1 class="sf-nf-title">Page not found</h1>', $html);
        $this->assertStringContainsString('class="sf-nf-search', $html);
        $this->assertSame(4, $this->tiles($html), 'the default bottom: the tiles (the 3 new categories and Clothing)');
        $this->assertStringContainsString('Go to the home page', $html);

        $this->store(['title' => str_repeat('T', 200), 'text' => '<b>bold</b> & '.str_repeat('x', 400), 'bottom' => 'none']);
        $html = $this->page();

        $this->assertStringContainsString('<h1 class="sf-nf-title">'.str_repeat('T', 80).'</h1>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; ', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        preg_match('~<p class="sf-nf-text">(.*?)</p>~s', $html, $m);
        $this->assertSame(300, mb_strlen(html_entity_decode(strip_tags($m[1]))), 'the text is cut to 300');
    }

    public function test_line_breaks_in_the_text_are_kept(): void
    {
        $this->publish(['text' => "line one\nline two", 'bottom' => 'none']);

        $this->assertStringContainsString("line one<br />\nline two", $this->page());
    }

    // ---- 5. the draft, the preview, the publish ---------------------------------------------------------------------------------------

    public function test_a_draft_is_not_public_the_signed_preview_shows_it_and_publish_makes_it_public(): void
    {
        [$clean] = NotFoundSet::validate(['title' => 'Draft title', 'bottom' => 'none'] + NotFoundSet::defaults());
        NotFoundStore::saveDraft($clean);
        $this->flushPurge();

        $this->assertStringNotContainsString('Draft title', $this->page());

        $this->fresh();
        $preview = $this->get(DesignPreviewController::previewUrl('notfound'));
        $this->assertStringContainsString('Draft title', $preview->getContent());
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertSame('noindex', $preview->headers->get('X-Robots-Tag'));

        $this->fresh();
        $this->get('/design-preview?zone=notfound')->assertNotFound(); // no signature: the plain 404, which does not show the draft
        $this->assertStringNotContainsString('Draft title', $this->page());

        NotFoundStore::publish($clean);
        $this->flushPurge();
        $this->assertStringContainsString('Draft title', $this->page());
        $this->assertNull(NotFoundStore::draft(), 'the draft is forgotten on publish');
    }

    // ---- 6. the markup and the headers ------------------------------------------------------------------------------------------------

    public function test_no_style_attribute_no_inline_script_no_cookie_no_xkey_and_the_security_headers(): void
    {
        $this->categories(4);
        $this->fresh();
        $response = $this->get('/abvgd')->assertNotFound();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/<[^>]+\sstyle=/i', $html);
        $this->assertSame([], array_filter($this->scriptBlocks($html), static fn (string $tag): bool => ! str_contains($tag, 'application/ld+json')));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('xkey'));
        $this->assertStringNotContainsString('s-maxage', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /** @return list<string> */
    private function scriptBlocks(string $html): array
    {
        preg_match_all('~<script\b[^>]*>~i', $html, $m);

        return $m[0];
    }

    // ---- 7. the cost ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function kinds(): array
    {
        return [
            'none' => [['bottom' => 'none']],
            'tiles' => [['bottom' => 'tiles']],
            'products' => [['bottom' => 'products', 'products' => ['count' => '4'] + NotFoundSet::productDefaults()]],
            'block' => [['bottom' => 'block', 'block' => -1]],
        ];
    }

    private function queries(string $url): int
    {
        $this->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertNotFound();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @param  array<string, mixed>  $set */
    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_a_warm_404_keeps_the_pins_with_every_content_kind(array $set): void
    {
        $this->categories(6);

        if (($set['block'] ?? null) === -1) {
            $set['block'] = HtmlBlockModel::create(['title' => 'B', 'body' => '<p>block</p>'])->id;
        }

        $this->publish($set);
        $this->page('/abvgd');
        $this->page('/product/never-existed'); // cold: the fragment is built once

        $fallback = $this->queries('/abvgd');
        $missing = $this->queries('/product/never-existed');
        fwrite(STDERR, "\n[query-count] NF-1 warm 404 ({$set['bottom']}): fallback {$fallback} (pin 2), missing product {$missing} (pin 3)\n");

        $this->assertLessThanOrEqual(2, $fallback, 'the fallback pin');
        $this->assertLessThanOrEqual(3, $missing, 'the missing-product pin');
    }

    /** ★ a product save moves the `sitemap` version: the next 404 shows the new product in a grid (a key without that version would serve the stale fragment) */
    public function test_a_new_product_shows_up_in_the_cached_grid(): void
    {
        $this->categories(2);
        $this->publish(['bottom' => 'products', 'products' => ['count' => '8'] + NotFoundSet::productDefaults()]);

        $this->assertStringNotContainsString('Brand New Thing', $this->page());
        $this->assertStringNotContainsString('Brand New Thing', $this->page(), 'warm');

        $this->makeProduct(200, ['name' => 'Brand New Thing', 'slug' => 'brand-new-thing', 'categories' => [1], 'price' => '5.00', 'stock' => 2]);
        $this->flushPurge();

        $this->assertStringContainsString('Brand New Thing', $this->page());
    }

    /** a publish of the zone shows at once (the `all` version moves and the fragment's own hash changes) */
    public function test_a_publish_changes_the_next_404(): void
    {
        $this->categories(3);
        $this->assertSame(4, $this->tiles($this->page()));

        $this->publish(['bottom' => 'tiles', 'tiles' => '4', 'title' => 'First']);
        $this->assertStringContainsString('First', $this->page());

        $this->publish(['bottom' => 'none', 'title' => 'Second']);
        $html = $this->page();
        $this->assertStringContainsString('Second', $html);
        $this->assertSame(0, $this->tiles($html));
    }
}
