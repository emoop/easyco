<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\CarouselGeometry;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeTilesRecompiler;
use App\Storefront\Home\HomeValidator;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Media\Events\MediaAssetReady;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HP-5: the «Карусел» section of the home page: its closed lists and per-kind rules (validated on save, publish and read), the SHARED markup (a region with an accessible name, a focusable track, `ul > li`, the
 * arrow buttons printed `hidden`, closed-list classes, no style, no script), the four kinds (products = the catalog's card, blocks, images, brands), freshness (a renamed or deleted brand, a changed logo, a ready
 * picture recompile; an unrelated change does not) and the pinned query counts.
 */
class HomeCarouselTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use MakesPickerAssets;
    use RefreshDatabase;

    private MediaAssetModel $asset;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->asset = $this->pickerAsset();
    }

    /** @param  array<string, mixed>  $settings */
    private function car(string $kind, array $settings = []): array
    {
        return ['type' => 'carousel', 'settings' => ['kind' => $kind] + $settings];
    }

    private function block(string $text = 'Cell'): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $text, 'body' => "<p>{$text}</p>"]);
    }

    private function brand(string $name, ?int $logo): BrandModel
    {
        return BrandModel::query()->create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'logo_media_asset_id' => $logo]);
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    private function queries(): int
    {
        $this->home();
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->home();

        return $n;
    }

    private function recompiler(): HomeTilesRecompiler
    {
        return app(HomeTilesRecompiler::class);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $sections): array
    {
        return HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
    }

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string}> */
    public static function refused(): array
    {
        $c = static fn (string $kind, array $settings = []): array => ['type' => 'carousel', 'settings' => ['kind' => $kind] + $settings];
        $img = static fn (array $over = []): array => $over + ['picture' => 1, 'link' => null, 'alt' => ''];
        $max = HomeCatalog::CAROUSEL_BLOCKS_MAX + 1;

        return [
            'unknown kind' => [[$c('slider')], 'sections.0.settings.kind'],
            'kind array' => [[$c('x', [])], 'sections.0.settings.kind'],
            'height 7' => [[$c('brands', ['height' => '7'])], 'sections.0.settings.height'],
            'height text px' => [[$c('brands', ['height' => '12px'])], 'sections.0.settings.height'],
            'height int' => [[$c('brands', ['height' => 120])], 'sections.0.settings.height'],
            'height array' => [[$c('brands', ['height' => ['120']])], 'sections.0.settings.height'],
            'height above the maximum step' => [[$c('brands', ['height' => '540'])], 'sections.0.settings.height'],
            'height 500' => [[$c('brands', ['height' => '500'])], 'sections.0.settings.height'],
            'height phone above' => [[$c('brands', ['height_phone' => '600'])], 'sections.0.settings.height_phone'],
            'ratio' => [[$c('brands', ['ratio' => '5:1'])], 'sections.0.settings.ratio'],
            'ratio array' => [[$c('brands', ['ratio' => ['3:2']])], 'sections.0.settings.ratio'],
            'gap 7' => [[$c('brands', ['gap' => '7'])], 'sections.0.settings.gap'],
            'gap px' => [[$c('brands', ['gap' => '16px'])], 'sections.0.settings.gap'],
            'radius 7' => [[$c('brands', ['radius' => '7'])], 'sections.0.settings.radius'],
            'radius array' => [[$c('brands', ['radius' => ['8']])], 'sections.0.settings.radius'],
            'arrows text' => [[$c('brands', ['arrows' => 'yes'])], 'sections.0.settings.arrows'],
            'label too long' => [[$c('brands', ['label' => str_repeat('x', 61)])], 'sections.0.settings.label'],
            'label with a tag' => [[$c('brands', ['label' => 'a <b>b</b>'])], 'sections.0.settings.label'],
            'count 4' => [[$c('products', ['count' => '4'])], 'sections.0.settings.count'],
            'count 28' => [[$c('products', ['count' => '28'])], 'sections.0.settings.count'],
            'source' => [[$c('products', ['source' => 'sale'])], 'sections.0.settings.source'],
            'category without entity' => [[$c('products', ['source' => 'category'])], 'sections.0.settings.entity'],
            'sort' => [[$c('products', ['sort' => 'price_asc'])], 'sections.0.settings.sort'],
            'no blocks' => [[$c('blocks', ['blocks' => []])], 'sections.0.settings.blocks'],
            'blocks missing' => [[$c('blocks')], 'sections.0.settings.blocks'],
            '13 blocks' => [[$c('blocks', ['blocks' => range(1, $max)])], 'sections.0.settings.blocks'],
            'block id text' => [[$c('blocks', ['blocks' => ['x']])], 'sections.0.settings.blocks.0'],
            'blocks not a list' => [[$c('blocks', ['blocks' => ['a' => 1]])], 'sections.0.settings.blocks'],
            'no images' => [[$c('images', ['images' => []])], 'sections.0.settings.images'],
            '25 images' => [[$c('images', ['images' => array_fill(0, 25, $img())])], 'sections.0.settings.images'],
            'a link and no alt' => [[$c('images', ['images' => [$img(['link' => '/sale'])]])], 'sections.0.settings.images.0.alt'],
            'a bad link' => [[$c('images', ['images' => [$img(['link' => 'javascript:alert(1)', 'alt' => 'x'])]])], 'sections.0.settings.images.0.link'],
            'a protocol-relative link' => [[$c('images', ['images' => [$img(['link' => '//evil.test', 'alt' => 'x'])]])], 'sections.0.settings.images.0.link'],
            'no picture' => [[$c('images', ['images' => [$img(['picture' => null])]])], 'sections.0.settings.images.0.picture'],
            'alt too long' => [[$c('images', ['images' => [$img(['alt' => str_repeat('a', 121)])]])], 'sections.0.settings.images.0.alt'],
            'alt with a tag' => [[$c('images', ['images' => [$img(['alt' => '<i>x</i>'])]])], 'sections.0.settings.images.0.alt'],
            'unknown image key' => [[$c('images', ['images' => [$img(['x' => 1])]])], 'sections.0.settings.images.0'],
            'a third carousel' => [[$c('brands'), $c('brands'), $c('brands')], 'sections.2'],
        ];
    }

    /** @param  list<array<string, mixed>>  $sections */
    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_on_save_and_on_publish(array $sections, string $key): void
    {
        [, $errors] = $this->validate($sections);
        $this->assertArrayHasKey($key, $errors, json_encode($errors));

        $this->expectException(\InvalidArgumentException::class);
        app(HomeCompiler::class)->publish(['spacing' => '32', 'sections' => $sections], app(SiteSettingsRepository::class));
    }

    public function test_the_defaults_and_a_valid_carousel_of_each_kind(): void
    {
        $this->publish([$this->car('brands'), $this->car('blocks', ['blocks' => [$this->block()->id]])]);
        [$clean] = $this->validate([['type' => 'carousel', 'settings' => []]]);
        $s = $clean['sections'][0]['settings'];

        $this->assertSame(['products', 'newest', null, '12', 'newest', '160', '120', '3:2', '16', '0', true, ''], [$s['kind'], $s['source'], $s['entity'], $s['count'], $s['sort'], $s['height'], $s['height_phone'], $s['ratio'], $s['gap'], $s['radius'], $s['arrows'], $s['label']]);
        $this->assertSame([], $this->validate([['type' => 'carousel', 'settings' => ['kind' => 'images', 'images' => [['picture' => 5, 'link' => '/sale', 'alt' => 'Sale']]]]])[1]);
    }

    public function test_the_settings_a_kind_does_not_use_are_ignored_not_refused(): void
    {
        // a product carousel: height / ratio / radius / gap (and lists) sent are ignored: their defaults
        [$clean, $errors] = $this->validate([$this->car('products', ['height' => '7', 'height_phone' => 'x', 'ratio' => '9:9', 'radius' => '99', 'gap' => 'z', 'blocks' => 'x', 'images' => 'x'])]);
        $s = $clean['sections'][0]['settings'];

        $this->assertSame([], $errors);
        $this->assertSame(['160', '120', '3:2', '0', '16', [], []], [$s['height'], $s['height_phone'], $s['ratio'], $s['radius'], $s['gap'], $s['blocks'], $s['images']]);

        // a brands carousel: the product source and the lists are ignored
        [$clean, $errors] = $this->validate([$this->car('brands', ['source' => 'sale', 'count' => '7', 'blocks' => [], 'images' => []])]);
        $this->assertSame([], $errors);
        $this->assertSame(['newest', '12', null], [$clean['sections'][0]['settings']['source'], $clean['sections'][0]['settings']['count'], $clean['sections'][0]['settings']['entity']]);
    }

    public function test_on_read_a_bad_value_is_its_default_and_the_unknown_or_unusable_is_dropped(): void
    {
        $block = $this->block();
        $layout = ['spacing' => '32', 'sections' => [
            $this->car('brands', ['height' => '9999', 'ratio' => 'x', 'gap' => '3', 'radius' => '5']),
            $this->car('wormhole'),
            $this->car('images', ['images' => [['picture' => 5, 'link' => '/a', 'alt' => ''], ['picture' => 6, 'link' => null, 'alt' => 'ok']]]),
            $this->car('brands'),
        ]];

        [$clean, $errors] = HomeValidator::validateForRead($layout);

        $this->assertSame([], $errors, 'nothing makes the layout unusable on read');
        $this->assertSame(['brands', 'images'], array_map(fn (array $s): string => $s['settings']['kind'], $clean['sections']), 'the unknown kind and the third carousel are dropped');
        $first = $clean['sections'][0]['settings'];
        $this->assertSame(['160', '3:2', '16', '0'], [$first['height'], $first['ratio'], $first['gap'], $first['radius']]);
        $this->assertSame([['picture' => 6, 'link' => null, 'alt' => 'ok']], $clean['sections'][1]['settings']['images'], 'an image with a link and no alt is dropped');

        [$clean] = HomeValidator::validateForRead(['spacing' => '32', 'sections' => [$this->car('blocks', ['blocks' => [$block->id, 'x', 0]])]]);
        $this->assertSame([$block->id], $clean['sections'][0]['settings']['blocks'], 'a block that is not an id is dropped');
    }

    // ---- ★ markup -------------------------------------------------------------------------------------------------------------------------

    public function test_the_shared_partial_prints_a_region_a_focusable_track_a_list_and_hidden_arrows(): void
    {
        $html = view('storefront.partials.carousel', ['class' => 'sf-car sf-car-box', 'label' => 'Our <brands>', 'arrows' => true, 'items' => [['html' => '<b>one</b>', 'class' => 'x'], ['html' => '<i>two</i>']]])->render();

        $this->assertMatchesRegularExpression('/<section class="sf-car sf-car-box" role="region" aria-label="Our &lt;brands&gt;" data-carousel>/', $html);
        $this->assertStringContainsString('<ul class="sf-car-track" tabindex="0">', $html);
        $this->assertSame(2, substr_count($html, '<li class="sf-car-item '));
        $this->assertMatchesRegularExpression('/<button type="button" class="sf-car-arrow sf-car-prev" data-car-step="-1" aria-label="Previous" hidden><\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="sf-car-arrow sf-car-next" data-car-step="1" aria-label="Next" hidden><\/button>/', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('<script', $html);

        $this->assertStringNotContainsString('sf-car-arrow', view('storefront.partials.carousel', ['class' => 'sf-car', 'label' => 'x', 'arrows' => false, 'items' => [['html' => 'a']]])->render(), 'arrows off: no buttons');
    }

    public function test_the_classes_match_the_settings_and_the_page_is_identical_for_every_visitor(): void
    {
        $this->brand('Acme', $this->asset->id);
        $this->publish([$this->car('brands', ['height' => '240', 'height_phone' => '100', 'ratio' => '16:9', 'gap' => '8', 'radius' => '12', 'label' => 'All "our" & brands'])]);

        $page = $this->home();

        $this->assertStringContainsString('class="sf-car sf-car-k-brands sf-car-box sf-car-h-240 sf-car-hp-100 sf-car-r-16-9 sf-car-g-8 sf-car-cr-12"', $page);
        $this->assertStringContainsString('aria-label="All &quot;our&quot; &amp; brands"', $page, 'the label is escaped');
        $this->assertDoesNotMatchRegularExpression('/<section class="sf-car[^>]*style=/', $page);
        $this->assertSame($page, $this->home(), 'the same HTML for every visitor');

        $this->publish([$this->car('brands')]);
        $this->assertStringContainsString('aria-label="Carousel"', $this->home(), 'no label: the language text');
    }

    // ---- ★ the kinds ----------------------------------------------------------------------------------------------------------------------

    public function test_products_are_the_catalogs_card_and_the_sources_of_the_product_grid(): void
    {
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'Alpha', 'slug' => 'alpha', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => '2026-01-02 10:00:00']);
        $this->makeProduct(2, ['name' => 'Beta', 'slug' => 'beta', 'price' => '12.00', 'stock' => 3, 'timeline' => '2026-01-03 10:00:00']);
        $this->makeImage(10, 1, 0);

        $this->publish([$this->car('products')]);
        $page = $this->home();

        preg_match('/<article class="sf-card" data-product-id="1">.*?<\/article>/s', $page, $home);
        preg_match('/<article class="sf-card" data-product-id="1">.*?<\/article>/s', $this->get('/product-category/clothing')->assertOk()->getContent(), $category);
        $this->assertNotEmpty($home);
        $this->assertSame($category[0], $home[0], 'the very same card markup as the catalog');
        $this->assertStringContainsString('class="sf-car sf-car-k-products"', $page);
        $this->assertStringNotContainsString('sf-car-h-', $page, 'a product carousel has no height class');
        $this->assertSame(['Beta', 'Alpha'], $this->names($page), 'newest first');

        $this->publish([$this->car('products', ['source' => 'category', 'entity' => 1, 'count' => '8'])]);
        $this->assertSame(['Alpha'], $this->names($this->home()));

        $this->publish([$this->car('products', ['sort' => 'name_asc'])]);
        $this->assertSame(['Alpha', 'Beta'], $this->names($this->home()));

        $this->publish([$this->car('products', ['source' => 'category', 'entity' => 999])]);
        $this->assertStringNotContainsString('data-carousel', $this->home(), 'an empty source prints nothing');
    }

    public function test_a_featured_product_carousel_shows_only_flagged_products_and_costs_what_a_newest_one_costs(): void
    {
        $this->makeProduct(1, ['name' => 'Alpha', 'slug' => 'alpha', 'featured' => true, 'price' => '10.00', 'stock' => 3, 'timeline' => '2026-01-02 10:00:00']);
        $this->makeProduct(2, ['name' => 'Beta', 'slug' => 'beta', 'price' => '12.00', 'stock' => 3, 'timeline' => '2026-01-03 10:00:00']);
        $this->makeProduct(3, ['name' => 'Gamma', 'slug' => 'gamma', 'featured' => true, 'price' => '12.00', 'stock' => 3, 'timeline' => '2026-01-04 10:00:00']);

        $this->publish([$this->car('products', ['source' => 'newest'])]);
        $newest = $this->queries();
        $this->publish([$this->car('products', ['source' => 'featured', 'entity' => 7])]);

        $this->assertSame(['Gamma', 'Alpha'], $this->names($this->home()));
        $this->assertSame($newest, $this->queries());
    }

    /** @return list<string> */
    private function names(string $html): array
    {
        preg_match_all('/<h3 class="sf-card-name">([^<]*)<\/h3>/', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    public function test_blocks_print_the_sanitised_blocks_and_a_used_block_cannot_be_deleted(): void
    {
        $a = $this->block('First block');
        $b = HtmlBlockModel::query()->create(['title' => 'Second', 'body' => '<p>ok</p><script>alert(2)</script>']);
        $free = $this->block('Free');
        $this->publish([$this->car('blocks', ['blocks' => [$a->id, $b->id]])]);

        $page = $this->home();

        $this->assertSame(2, substr_count($page, 'class="sf-car-item sf-car-block"'));
        $this->assertStringContainsString('First block', $page);
        $this->assertStringContainsString('<p>ok</p>', $page);
        $this->assertStringNotContainsString('alert(2)', $page, 'the block is sanitised');
        $this->assertTrue(HtmlBlockUsage::home($a->id));
        $this->assertTrue(HtmlBlockUsage::isUsed($b->id));
        $this->assertFalse(HtmlBlockUsage::isUsed($free->id));
        $this->assertNotNull(HtmlBlockUsage::refusal($a));
        $this->assertNull(HtmlBlockUsage::refusal($free));

        // a carousel of ANOTHER kind does not hold the blocks of its (ignored) list
        $this->publish([$this->car('brands')]);
        $this->assertFalse(HtmlBlockUsage::home($a->id));
    }

    public function test_a_draft_with_a_blocks_carousel_also_holds_its_blocks(): void
    {
        $a = $this->block('Draft block');
        [$draft] = $this->validate([$this->car('blocks', ['blocks' => [$a->id]])]);
        HomeDrafts::saveDraft($draft);

        $this->assertTrue(HtmlBlockUsage::home($a->id), 'the draft counts');
    }

    public function test_images_print_img_with_alt_and_the_link_only_when_set(): void
    {
        $other = $this->pickerAsset();
        $this->publish([$this->car('images', ['images' => [
            ['picture' => $this->asset->id, 'link' => null, 'alt' => 'Plain "one"'],
            ['picture' => $other->id, 'link' => '/sale', 'alt' => 'Linked'],
        ]])]);

        $page = $this->home();

        $this->assertSame(2, substr_count($page, 'class="sf-car-img"'));
        $this->assertStringContainsString('alt="Plain &quot;one&quot;"', $page);
        $this->assertSame(1, substr_count($page, 'class="sf-car-link"'), 'one link only');
        $this->assertStringContainsString('<a class="sf-car-link" href="/sale"><img class="sf-car-img"', $page);
        $this->assertStringContainsString('loading="lazy"', $page);
        $this->assertStringContainsString('sizes="(min-width: 48rem) 100px, 100px"', $page, 'a picture of 100px shown whole in a 240 × 160 box is displayed at its own 100px (never enlarged)');
        $this->assertStringNotContainsString('style=', substr($page, (int) strpos($page, 'sf-car-track')));

        foreach ($this->srcs($page) as $src) {
            $this->assertStringStartsWith('/storage/', $src, 'our own addresses only');
        }
    }

    /** @return list<string> */
    private function srcs(string $page): array
    {
        preg_match_all('/class="sf-car-img" src="([^"]*)"/', $page, $m);

        return $m[1];
    }

    public function test_a_picture_that_cannot_be_shown_is_dropped_and_an_empty_carousel_prints_nothing(): void
    {
        $pending = $this->pickerAsset(['processing_status' => 'pending', 'variants' => []]);
        $this->publish([$this->car('images', ['images' => [['picture' => $pending->id, 'link' => null, 'alt' => 'Soon']]])]);

        $this->assertStringNotContainsString('data-carousel', $this->home(), 'no usable item: nothing is printed');

        $this->publish([$this->car('images', ['images' => [['picture' => $pending->id, 'link' => null, 'alt' => 'Soon'], ['picture' => $this->asset->id, 'link' => null, 'alt' => 'Now']]])]);
        $page = $this->home();
        $this->assertSame(1, substr_count($page, 'class="sf-car-img"'));
        $this->assertStringNotContainsString('alt="Soon"', $page);
    }

    public function test_the_compiled_pictures_are_checked_again_on_read(): void
    {
        $this->publish([$this->car('images', ['images' => [['picture' => $this->asset->id, 'link' => null, 'alt' => 'Ok']]])]);
        $settings = app(SiteSettingsRepository::class);
        $doc = json_decode((string) $settings->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $list = &$doc['zones']['home']['global']['carousels']['0'];
        $good = $list[0];
        $list = [
            ['src' => 'https://evil.test/x.png'] + $good,
            ['url' => 'javascript:alert(1)'] + $good,
            ['alt' => '<script>x</script>'] + $good,
            ['alt' => ''] + ['url' => '/x'] + $good,
            $good,
        ];
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($doc));
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();

        $page = $this->home();

        $this->assertSame(1, substr_count($page, 'class="sf-car-img"'), 'only the one good item');
        $this->assertStringNotContainsString('evil.test', $page);
        $this->assertStringNotContainsString('javascript:', $page);
    }

    public function test_brands_print_every_brand_with_a_logo_by_name_and_no_link(): void
    {
        $this->brand('Zeta', $this->asset->id);
        $this->brand('alpha', $this->pickerAsset()->id);
        $this->brand('No Logo', null);
        $this->brand('Pending', $this->pickerAsset(['processing_status' => 'pending', 'variants' => []])->id);
        $this->publish([$this->car('brands')]);

        $page = $this->home();

        preg_match_all('/class="sf-car-img" src="[^"]*"[^>]*alt="([^"]*)"/', $page, $m);
        $this->assertSame(['alpha', 'Zeta'], $m[1], 'by name; the brand without a logo and the one whose logo is not ready are dropped');
        $this->assertStringNotContainsString('sf-car-link', $page, 'the shop has no public brand page: no link');
        $brandRoutes = array_filter(array_map(fn ($route): string => $route->uri(), $this->app['router']->getRoutes()->getRoutes()), fn (string $uri): bool => str_starts_with($uri, 'brand'));
        $this->assertSame([], array_values($brandRoutes), 'step 0: there is no public brand page route yet; when one exists the carousel must link to it');
    }

    public function test_a_brands_carousel_holds_at_most_24_brands(): void
    {
        // brands with no logo sort first by name: they must not use up the 24 places
        foreach (range(1, 5) as $i) {
            $this->brand("0 No logo {$i}", null);
        }

        foreach (range(1, 26) as $i) {
            $this->brand(sprintf('Brand %02d', $i), $this->asset->id);
        }

        $this->publish([$this->car('brands')]);

        $this->assertSame(24, substr_count($this->home(), 'class="sf-car-img"'));
    }

    // ---- ★ freshness ----------------------------------------------------------------------------------------------------------------------

    public function test_a_renamed_or_deleted_brand_and_a_changed_logo_recompile_a_brands_carousel(): void
    {
        $other = $this->pickerAsset();
        $acme = $this->brand('Acme', $this->asset->id);
        $beta = $this->brand('Beta', $this->asset->id);
        $this->publish([$this->car('brands')]);
        $this->assertSame(2, substr_count($this->home(), 'class="sf-car-img"'));

        $steps = [
            fn () => $acme->update(['name' => 'Acme Two']),
            fn () => $beta->update(['logo_media_asset_id' => $other->id]),
            fn () => $this->brand('Gamma', $this->asset->id),
            fn () => $beta->delete(),
        ];

        foreach ($steps as $i => $step) {
            $step();
            $this->recompiler()->flush();
            $this->assertSame($i + 1, $this->recompiler()->runs, 'step '.($i + 1).' recompiled');
        }

        $page = $this->home();
        $this->assertStringContainsString('alt="Acme Two"', $page);
        $this->assertStringContainsString('alt="Gamma"', $page);
        $this->assertStringNotContainsString('alt="Beta"', $page);
        $this->assertStringNotContainsString('alt="Acme"', $page);
    }

    public function test_a_picture_that_becomes_ready_recompiles_an_images_carousel(): void
    {
        $pending = $this->pickerAsset(['processing_status' => 'pending', 'variants' => []]);
        $this->publish([$this->car('images', ['images' => [['picture' => $pending->id, 'link' => null, 'alt' => 'Soon']]])]);
        $this->assertStringNotContainsString('alt="Soon"', $this->home());

        $ready = $this->pickerAsset();
        $pending->update(['processing_status' => 'ready', 'variants' => $ready->variants]);
        event(new MediaAssetReady((string) $pending->id));
        $this->recompiler()->flush();

        $this->assertSame(1, $this->recompiler()->runs);
        $this->assertStringContainsString('alt="Soon"', $this->home());
    }

    public function test_an_unrelated_change_does_not_recompile(): void
    {
        $this->brand('Acme', $this->asset->id);
        $unrelated = $this->pickerAsset();
        $this->publish([$this->car('images', ['images' => [['picture' => $this->asset->id, 'link' => null, 'alt' => 'x']]])]);
        $this->home();

        // no brands carousel in the layout: a brand change asks nothing; a picture the layout does not use asks nothing
        BrandModel::query()->where('name', 'Acme')->update(['name' => 'Acme 2']);
        $this->brand('New', $this->asset->id)->update(['name' => 'New 2']);
        MediaAssetModel::query()->find($unrelated->id)->update(['alt_text' => 'x']);
        event(new MediaAssetReady((string) $unrelated->id));
        $this->recompiler()->flush();

        $this->assertSame(0, $this->recompiler()->runs);
    }

    public function test_a_layout_without_any_carousel_stays_the_compiled_document_it_was(): void
    {
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
        $doc = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);

        $this->assertSame(['layout', 'blocks'], array_keys($doc['zones']['home']['global']), 'no carousels / assets key');
    }

    // ---- ★ query counts -------------------------------------------------------------------------------------------------------------------

    private const WITHOUT_CAROUSEL = 2;

    private const WITH_ONE_PRODUCT_CAROUSEL = 20;

    public function test_blocks_images_and_brands_carousels_cost_no_query_and_a_product_carousel_costs_one_product_grid(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeProduct($i, ['name' => "N{$i}", 'slug' => "n{$i}", 'price' => '10.00', 'stock' => 3, 'timeline' => '2026-01-01 10:00:'.sprintf('%02d', $i)]);
        }

        $this->makeImage(10, 1, 0);
        $block = $this->block();

        foreach (range(1, 5) as $i) {
            $this->brand("Brand {$i}", $this->asset->id);
        }

        $heading = ['type' => 'heading', 'settings' => ['text' => 'Hi']];
        $this->publish([$heading]);
        $base = $this->queries();
        $this->assertSame(self::WITHOUT_CAROUSEL, $base);

        $kinds = [
            'blocks' => fn (): array => $this->car('blocks', ['blocks' => [$block->id, $block->id]]),
            'images' => fn (): array => $this->car('images', ['images' => [['picture' => $this->asset->id, 'link' => '/x', 'alt' => 'x'], ['picture' => $this->asset->id, 'link' => null, 'alt' => '']]]),
            'brands' => fn (): array => $this->car('brands'),
        ];

        foreach ($kinds as $kind => $make) {
            $this->publish([$heading, $make()]);
            $this->assertSame($base, $this->queries(), "one {$kind} carousel: no extra query");
            $this->publish([$make(), $heading, $make()]);
            $this->assertSame($base, $this->queries(), "two {$kind} carousels: no extra query");
        }

        $this->publish([$heading, $this->car('products', ['count' => '24'])]);
        $one = $this->queries();
        $this->publish([$heading, ['type' => 'product_grid', 'settings' => ['count' => '24']]]);
        $this->assertSame($one, $this->queries(), 'a product carousel costs what one product grid costs');
        $this->assertSame(self::WITH_ONE_PRODUCT_CAROUSEL, $one);
    }

    // ---- the draft and the preview --------------------------------------------------------------------------------------------------------

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $this->brand('Only in the draft', $this->asset->id);
        [$draft] = $this->validate([$this->car('brands')]);
        HomeDrafts::saveDraft($draft);

        $this->assertStringNotContainsString('data-carousel', $this->home(), 'the shop still prints the old home page');
        $this->assertStringContainsString('alt="Only in the draft"', $this->get(DesignPreviewController::previewUrl('home'))->assertOk()->getContent());
        $this->assertSame(['products', 'blocks', 'images', 'brands'], CarouselGeometry::KINDS);
    }
}
