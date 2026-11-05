<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeTilesRecompiler;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Home\TileGeometry;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Media\Events\MediaAssetReady;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HP-3: the «Плочки» section of the home page: its closed lists and the tile list (validated on save, publish and read), what it prints (a tile is ONE link with the picture and the caption below; a tile with no
 * usable picture is not printed), the compiled copy (no query per tile) and how it is kept fresh (a category, a season, a tag, a brand, a picture), the draft and the signed preview.
 */
class HomeTilesTest extends TestCase
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
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);

        $this->asset = $this->pickerAsset();
        DB::table('catalog_categories')->where('id', 1)->update(['picture_media_asset_id' => $this->asset->id]);
    }

    /** @return array<string, mixed> */
    private function tile(array $over = []): array
    {
        return $over + ['source' => 'category', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => null];
    }

    /** @param  list<array<string, mixed>>  $tiles  @param  array<string, mixed>  $settings */
    private function section(array $tiles, array $settings = []): array
    {
        return ['type' => 'tiles', 'settings' => ['tiles' => $tiles] + $settings];
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

    /** ★ P3: a tile of a season, a tag or a brand with no link of its own links to that page (the slug is compiled at publish: no query per tile); a link given by hand still wins */
    public function test_a_season_tag_and_brand_tile_links_to_its_public_page(): void
    {
        $season = $this->season('Winter', $this->asset->id);
        $brand = BrandModel::query()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_media_asset_id' => $this->asset->id]);
        $tag = DB::table('catalog_tags')->insertGetId(['name' => 'Sale', 'slug' => 'sale', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $this->publish([$this->section([
            $this->tile(['source' => 'season', 'entity' => $season->id]),
            $this->tile(['source' => 'tag', 'entity' => $tag, 'picture' => $this->asset->id]),
            $this->tile(['source' => 'brand', 'entity' => $brand->id]),
            $this->tile(['source' => 'brand', 'entity' => $brand->id, 'label' => 'By hand', 'link' => '/by-hand']),
        ])]);
        $page = $this->home();

        foreach (['href="/season/winter"', 'href="/product-tag/sale"', 'href="/brand/acme"', 'href="/by-hand"'] as $href) {
            $this->assertStringContainsString($href, $page);
        }

        $this->assertSame(4, substr_count($page, 'class="sf-hp-tile"'));

        // the same layout with every link given by hand costs the same queries: the page links cost none
        $with = $this->queries();
        $this->publish([$this->section([
            $this->tile(['source' => 'season', 'entity' => $season->id, 'link' => '/a']),
            $this->tile(['source' => 'tag', 'entity' => $tag, 'picture' => $this->asset->id, 'link' => '/b']),
            $this->tile(['source' => 'brand', 'entity' => $brand->id, 'link' => '/c']),
            $this->tile(['source' => 'brand', 'entity' => $brand->id, 'label' => 'By hand', 'link' => '/by-hand']),
        ])]);

        $this->assertSame($this->queries(), $with, 'a tile\'s page link costs no query');
        $this->get('/season/winter')->assertOk(); // the link lands on a real page (the season's own listing)
    }

    /** ★ P3: the slug follows: a renamed slug recompiles the tile's link */
    public function test_a_tile_link_follows_a_changed_slug(): void
    {
        $brand = BrandModel::query()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_media_asset_id' => $this->asset->id]);
        $this->publish([$this->section([$this->tile(['source' => 'brand', 'entity' => $brand->id])])]);
        $this->assertStringContainsString('href="/brand/acme"', $this->home());

        $brand->update(['slug' => 'acme-2']);
        app(\App\Storefront\Home\HomeTilesRecompiler::class)->flush();

        $this->assertStringContainsString('href="/brand/acme-2"', $this->home());
    }

    private function season(string $name, ?int $picture = null): SeasonModel
    {
        return SeasonModel::query()->create(['name' => $name, 'slug' => strtolower($name), 'picture_media_asset_id' => $picture]);
    }

    private function recompiler(): HomeTilesRecompiler
    {
        return app(HomeTilesRecompiler::class);
    }

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): array
    {
        $ok = ['source' => 'category', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => null];
        $tiles = static fn (array $tile): array => ['tiles' => [$tile]];

        return [
            'columns' => [['columns' => '7'] + $tiles($ok), 'columns'], 'columns array' => [['columns' => ['4']] + $tiles($ok), 'columns'],
            'phone columns' => [['columns_phone' => '3'] + $tiles($ok), 'columns_phone'],
            'gap 10' => [['gap' => '10'] + $tiles($ok), 'gap'], 'gap px' => [['gap' => '12px'] + $tiles($ok), 'gap'], 'gap int' => [['gap' => 16] + $tiles($ok), 'gap'], 'gap array' => [['gap' => [16]] + $tiles($ok), 'gap'],
            'shape' => [['shape' => 'round'] + $tiles($ok), 'shape'], 'radius' => [['radius' => '70'] + $tiles($ok), 'radius'], 'caption' => [['caption' => 'xl'] + $tiles($ok), 'caption'],
            'no tiles' => [['tiles' => []], 'tiles'],
            'thirteen tiles' => [['tiles' => array_fill(0, 13, $ok)], 'tiles'],
            'tiles not a list' => [['tiles' => ['a' => $ok]], 'tiles'],
            'unknown source' => [$tiles(['source' => 'page'] + $ok), 'tiles.0.source'],
            'no entity' => [$tiles(['entity' => null] + $ok), 'tiles.0.entity'], 'entity text' => [$tiles(['entity' => 'x'] + $ok), 'tiles.0.entity'],
            'label 61' => [$tiles(['label' => str_repeat('a', 61)] + $ok), 'tiles.0.label'], 'label html' => [$tiles(['label' => '<b>x</b>'] + $ok), 'tiles.0.label'],
            'javascript link' => [$tiles(['link' => 'javascript:alert(1)'] + $ok), 'tiles.0.link'], 'protocol relative link' => [$tiles(['link' => '//evil.test/x'] + $ok), 'tiles.0.link'],
            'data link' => [$tiles(['link' => 'data:text/html,x'] + $ok), 'tiles.0.link'], 'http link' => [$tiles(['link' => 'http://evil.test'] + $ok), 'tiles.0.link'],
            // P3: a season, a tag and a brand have a public page now: a tile of one without a link is valid (it links to that page); see test_a_season_tag_and_brand_tile_links_to_its_public_page
            'custom without link' => [$tiles(['source' => 'custom', 'entity' => null, 'label' => 'x', 'picture' => 5, 'link' => null] + $ok), 'tiles.0.link'],
            'custom without picture' => [$tiles(['source' => 'custom', 'entity' => null, 'label' => 'x', 'picture' => null, 'link' => '/x'] + $ok), 'tiles.0.picture'],
            'custom without label' => [$tiles(['source' => 'custom', 'entity' => null, 'label' => '', 'picture' => 5, 'link' => '/x'] + $ok), 'tiles.0.label'],
            'unknown key' => [$tiles($ok + ['style' => 'x']), 'tiles.0'],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_on_save_and_on_publish(array $settings, string $key): void
    {
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'tiles', 'settings' => $settings]]]);

        $this->assertArrayHasKey("sections.0.settings.{$key}", $errors, json_encode($errors));
        $this->expectException(InvalidArgumentException::class);
        app(HomeCompiler::class)->publish(['spacing' => '32', 'sections' => [['type' => 'tiles', 'settings' => $settings]]], app(SiteSettingsRepository::class));
    }

    public function test_the_defaults_and_a_free_tile_with_everything_it_needs(): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->section([$this->tile(['source' => 'custom', 'entity' => null, 'label' => 'Sale', 'picture' => $this->asset->id, 'link' => '/sale'])])]]);
        $s = $clean['sections'][0]['settings'];

        $this->assertSame([], $errors);
        $this->assertSame(['4', '2', '16', 'square', '0', 'm'], [$s['columns'], $s['columns_phone'], $s['gap'], $s['shape'], $s['radius'], $s['caption']]);
        $this->assertSame([['source' => 'custom', 'entity' => null, 'label' => 'Sale', 'picture' => $this->asset->id, 'link' => '/sale']], $s['tiles']);

        $this->assertSame([], HomeValidator::validate(['spacing' => '32', 'sections' => [$this->section(array_fill(0, 12, $this->tile()))]])[1], 'twelve tiles are fine');
    }

    public function test_on_read_a_bad_value_is_its_default_and_an_invalid_tile_is_dropped(): void
    {
        $this->publish([$this->section([$this->tile()])]);
        $settings = app(SiteSettingsRepository::class);
        $json = json_decode((string) $settings->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $entry = &$json['zones']['home']['global'];
        $entry['layout']['sections'][0]['settings']['columns'] = '9';
        $entry['layout']['sections'][0]['settings']['gap'] = '12px';
        $entry['layout']['sections'][0]['settings']['shape'] = 'landscape';
        $entry['layout']['sections'][0]['settings']['tiles'][] = ['source' => 'page', 'entity' => 5, 'label' => 'x', 'picture' => null, 'link' => null];
        $good = $entry['tiles']['0'][0];
        $entry['tiles']['0'] = [
            $good,
            ['label' => '', 'url' => '/x', 'src' => $good['src'], 'srcset' => [], 'width' => 1, 'height' => 1],
            ['label' => 'Evil', 'url' => 'javascript:alert(1)', 'src' => $good['src'], 'srcset' => [], 'width' => 1, 'height' => 1],
            ['label' => 'Outside', 'url' => '/x', 'src' => 'https://evil.test/a.png', 'srcset' => [], 'width' => 1, 'height' => 1],
            ['label' => str_repeat('a', 61), 'url' => '/x', 'src' => $good['src'], 'srcset' => [], 'width' => 1, 'height' => 1],
        ];
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($json));
        app(StorefrontAppearance::class)->refresh();

        $page = $this->home();

        $this->assertStringContainsString('sf-hp-tc-4', $page, 'a bad column count is the default');
        $this->assertStringContainsString('sf-hp-tg-16', $page, 'a bad gap is the default');
        $this->assertStringContainsString('sf-hp-sh-landscape', $page, 'a good value stays');
        $this->assertSame(1, substr_count($page, 'class="sf-hp-tile"'), 'only the tile that is fine is printed');
        $this->assertStringNotContainsString('Evil', $page);
        $this->assertStringNotContainsString('evil.test', $page);
        $this->assertStringNotContainsString('javascript:', $page);
    }

    // ---- ★ markup -------------------------------------------------------------------------------------------------------------------------

    public function test_a_tile_is_one_link_with_the_picture_and_the_caption_below_it(): void
    {
        $this->publish([$this->section([$this->tile()], ['columns' => '3', 'gap' => '24', 'shape' => 'portrait', 'radius' => '50', 'caption' => 'l'])]);
        $page = $this->home();

        $this->assertMatchesRegularExpression('#<ul class="sf-hp-tiles-row sf-hp-tc-3 sf-hp-tp-2 sf-hp-tg-24 sf-hp-sh-portrait sf-hp-tr-50 sf-hp-cap-l">#', $page);
        $this->assertMatchesRegularExpression('#<li class="sf-hp-tile">\s*<a class="sf-hp-tile-link" href="/product-category/clothing">\s*<img class="sf-hp-tile-img" src="/storage/[^"]+"[^>]*alt=""[^>]*>\s*<span class="sf-hp-tile-cap">Clothing</span>\s*</a>\s*</li>#', $page);
        $this->assertSame(1, substr_count($page, '<a class="sf-hp-tile-link"'));
        $this->assertStringContainsString('width="400" height="500"', $page, 'the shape in whole pixels: no layout shift');
        $this->assertStringContainsString('sizes="'.TileGeometry::sizes(3, 2, 24).'"', $page);
        $this->assertMatchesRegularExpression('#srcset="/storage/[^"]+ \d+w(, /storage/[^"]+ \d+w)*"#', $page);

        // the section carries the class of its TYPE (`sf-hp-tiles`): the row must not share it, or the row's grid rules would apply to the section too
        $this->assertStringContainsString('<section class="sf-hp-section sf-hp-tiles">', $page);
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        $this->assertDoesNotMatchRegularExpression('/\.sf-hp-tiles\s*\{/', substr($css, (int) strpos($css, 'HP-3 tiles')), 'no rule is written for the class of the section');
    }

    public function test_the_caption_is_the_entity_name_unless_set_and_is_escaped_and_no_heading_is_printed(): void
    {
        $season = $this->season('Winter', $this->asset->id);
        $this->publish([$this->section([
            $this->tile(['label' => 'Tom & "Q" co']),
            $this->tile(['source' => 'season', 'entity' => $season->id, 'link' => '/winter']),
        ])]);
        $page = $this->home();

        $this->assertStringContainsString('<span class="sf-hp-tile-cap">Winter</span>', $page, 'the entity\'s name when no caption is set');
        $this->assertStringContainsString('<span class="sf-hp-tile-cap">Tom &amp; &quot;Q&quot; co</span>', $page, 'text, escaped');
        $this->assertDoesNotMatchRegularExpression('#<h[1-6]#i', substr($page, (int) strpos($page, 'sf-hp-tiles'), 2000));
    }

    public function test_the_first_row_is_loaded_at_once_and_the_rest_lazily(): void
    {
        $tiles = array_map(fn (int $i): array => $this->tile(['label' => "T{$i}"]), range(1, 5));
        $this->publish([$this->section($tiles, ['columns' => '3'])]);
        $page = $this->home();

        $this->assertSame(2, substr_count(substr($page, (int) strpos($page, 'sf-hp-tiles')), 'loading="lazy"'), 'the 4th and the 5th are lazy, the first three (one row) are not');
    }

    public function test_the_picture_addresses_are_ours_only_and_there_is_no_style_or_inline_script(): void
    {
        $this->publish([$this->section([$this->tile(), $this->tile(['label' => 'Own picture', 'picture' => $this->pickerAsset()->id])])]);
        $page = $this->home();

        preg_match_all('#<img class="sf-hp-tile-img" src="([^"]+)"#', $page, $m);
        $this->assertCount(2, $m[1]);

        foreach ($m[1] as $src) {
            $this->assertStringStartsWith('/storage/', $src);
        }

        $this->assertStringNotContainsString(' style=', $page);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $page);

        $this->flushSession();
        $second = $this->withHeader('Cookie', 'x=1')->get('/')->assertOk()->getContent();
        $this->assertSame(preg_replace('/csrf-token" content="[^"]*"/', '', $page), preg_replace('/csrf-token" content="[^"]*"/', '', $second), 'the same page for every visitor');
    }

    // ---- ★ what is not printed, and the links -----------------------------------------------------------------------------------------------

    public function test_a_tile_without_a_usable_picture_is_not_printed(): void
    {
        $pending = $this->pickerAsset(['processing_status' => 'pending', 'variants' => []]);
        $season = $this->season('NoPic');
        $tag = TagModel::query()->create(['name' => 'Sale', 'slug' => 'sale']);

        $this->publish([$this->section([
            $this->tile(['label' => 'Shown']),
            $this->tile(['source' => 'season', 'entity' => $season->id, 'link' => '/s']),
            $this->tile(['source' => 'tag', 'entity' => $tag->id, 'link' => '/t']),
            $this->tile(['source' => 'custom', 'entity' => null, 'label' => 'Pending', 'picture' => $pending->id, 'link' => '/p']),
        ])]);
        $page = $this->home();

        $this->assertSame(1, substr_count($page, 'class="sf-hp-tile"'));
        $this->assertStringContainsString('Shown', $page);
        foreach (['NoPic', 'Sale', 'Pending'] as $name) {
            $this->assertStringNotContainsString('>'.$name.'<', $page, $name);
        }
    }

    public function test_the_links_a_category_its_page_the_others_their_own_link_and_a_hidden_category_is_dropped(): void
    {
        $season = $this->season('Winter', $this->asset->id);
        $brand = BrandModel::query()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_media_asset_id' => $this->asset->id]);
        $this->makeCategory(2, 'Empty', 'empty');
        DB::table('catalog_categories')->where('id', 2)->update(['picture_media_asset_id' => $this->asset->id]);

        $this->publish([$this->section([
            $this->tile(),
            $this->tile(['source' => 'season', 'entity' => $season->id, 'link' => '/winter-sale']),
            $this->tile(['source' => 'brand', 'entity' => $brand->id, 'link' => 'https://example.com/acme']),
            $this->tile(['source' => 'custom', 'entity' => null, 'label' => 'Free', 'picture' => $this->asset->id, 'link' => '/free']),
            $this->tile(['entity' => 2]),
        ])]);
        $page = $this->home();

        foreach (['href="/product-category/clothing"', 'href="/winter-sale"', 'href="https://example.com/acme"', 'href="/free"'] as $href) {
            $this->assertStringContainsString($href, $page);
        }

        $this->assertSame(4, substr_count($page, 'class="sf-hp-tile"'), 'a category the shop does not show (no visible product) has no tile: its page would be a 404');
        $this->assertStringNotContainsString('>Empty<', $page);
    }

    // ---- ★ freshness ----------------------------------------------------------------------------------------------------------------------

    public function test_renaming_a_category_recompiles_the_published_layout_and_the_page_follows(): void
    {
        $this->publish([$this->section([$this->tile()])]);
        $this->assertStringContainsString('>Clothing<', $this->home());

        CategoryModel::query()->find(1)->update(['name' => 'Garments']);
        $this->recompiler()->flush();

        $this->assertSame(1, $this->recompiler()->runs);
        $this->assertStringContainsString('>Garments<', $this->home());
    }

    public function test_a_new_picture_of_the_category_a_deleted_season_a_renamed_tag_and_a_brand_logo_recompile(): void
    {
        $other = $this->pickerAsset();
        $season = $this->season('Winter', $this->asset->id);
        $tag = TagModel::query()->create(['name' => 'Sale', 'slug' => 'sale']);
        $brand = BrandModel::query()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_media_asset_id' => $this->asset->id]);
        $this->publish([$this->section([
            $this->tile(),
            $this->tile(['source' => 'season', 'entity' => $season->id, 'label' => 'Winter tile', 'picture' => $this->asset->id, 'link' => '/w']),
            $this->tile(['source' => 'tag', 'entity' => $tag->id, 'picture' => $this->asset->id, 'link' => '/t']),
            $this->tile(['source' => 'brand', 'entity' => $brand->id, 'link' => '/b']),
        ])]);
        $this->assertSame(4, substr_count($this->home(), 'class="sf-hp-tile"'));

        $steps = [
            fn () => CategoryModel::query()->find(1)->update(['picture_media_asset_id' => $other->id]),
            fn () => $season->delete(),
            fn () => $tag->update(['name' => 'Offers']),
            fn () => $brand->update(['logo_media_asset_id' => $other->id]),
        ];

        foreach ($steps as $i => $step) {
            $step();
            $this->recompiler()->flush();
            $this->assertSame($i + 1, $this->recompiler()->runs, 'step '.($i + 1).' recompiled');
        }

        $page = $this->home();
        $this->assertSame(3, substr_count($page, 'class="sf-hp-tile"'), 'the deleted season is dropped from the compiled copy');
        $this->assertStringNotContainsString('>Winter tile<', $page, 'even with its own caption, picture and link: its item is gone');
        $this->assertStringContainsString('>Offers<', $page);
    }

    public function test_a_picture_that_becomes_ready_recompiles_and_the_tile_appears(): void
    {
        $pending = $this->pickerAsset(['processing_status' => 'pending', 'variants' => []]);
        $this->publish([$this->section([$this->tile(['source' => 'custom', 'entity' => null, 'label' => 'Soon', 'picture' => $pending->id, 'link' => '/soon'])])]);
        $this->assertStringNotContainsString('>Soon<', $this->home());

        $ready = $this->pickerAsset();
        $pending->update(['processing_status' => 'ready', 'variants' => $ready->variants]);
        event(new MediaAssetReady((string) $pending->id));
        $this->recompiler()->flush();

        $this->assertSame(1, $this->recompiler()->runs);
        $this->assertStringContainsString('>Soon<', $this->home());
    }

    public function test_an_entity_that_is_not_in_the_layout_causes_no_recompile(): void
    {
        $this->recompiler()->entity('season', 1);
        $this->recompiler()->flush();
        $this->assertSame(0, $this->recompiler()->runs, 'no layout published at all');

        $inLayout = $this->season('Used', $this->asset->id);
        $notInLayout = $this->season('Unused', $this->asset->id);
        $this->makeCategory(3, 'Other', 'other');
        $this->publish([$this->section([$this->tile(['source' => 'season', 'entity' => $inLayout->id, 'link' => '/u'])])]);

        $notInLayout->update(['name' => 'Renamed']);
        CategoryModel::query()->find(3)->update(['name' => 'Other 2']);
        TagModel::query()->create(['name' => 'T', 'slug' => 't']);
        MediaAssetModel::query()->find($this->pickerAsset()->id)->update(['alt_text' => 'x']);
        event(new MediaAssetReady((string) $this->pickerAsset()->id));
        $this->recompiler()->flush();

        $this->assertSame(0, $this->recompiler()->runs, 'nothing a tile shows changed');

        $inLayout->update(['name' => 'Used 2']);
        $this->recompiler()->flush();
        $this->assertSame(1, $this->recompiler()->runs);
    }

    public function test_several_changes_in_one_request_recompile_once(): void
    {
        $season = $this->season('Winter', $this->asset->id);
        $this->publish([$this->section([$this->tile(), $this->tile(['source' => 'season', 'entity' => $season->id, 'link' => '/w'])])]);

        CategoryModel::query()->find(1)->update(['name' => 'A']);
        $season->update(['name' => 'B']);
        $season->update(['name' => 'C']);
        $this->recompiler()->flush();

        $this->assertSame(1, $this->recompiler()->runs);
    }

    // ---- ★ queries, draft, preview ----------------------------------------------------------------------------------------------------------

    public function test_twelve_tiles_cost_the_pinned_queries_of_the_home_layout(): void
    {
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
        $pin = $this->queries();

        $this->publish([$this->section(array_map(fn (int $i): array => $this->tile(['label' => "T{$i}"]), range(1, 12)))]);

        $this->assertSame($pin, $this->queries(), 'the tiles are in the compiled copy: no query per tile');
        $this->assertSame(2, $pin, 'the HP-1 / HP-2 pin of a home layout');
    }

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        [$draft] = HomeValidator::validate(['spacing' => '16', 'sections' => [$this->section([$this->tile(['label' => 'Only in the draft'])], ['columns' => '5'])]]);
        HomeDrafts::saveDraft($draft);

        $this->assertStringNotContainsString('Only in the draft', $this->home());

        $this->app->forgetScopedInstances();
        $preview = $this->get(DesignPreviewController::previewUrl(HomeCatalog::ZONE))->assertOk();

        $this->assertStringContainsString('Only in the draft', $preview->getContent());
        $this->assertStringContainsString('sf-hp-tc-5', $preview->getContent());
        $preview->assertHeader('X-Robots-Tag', 'noindex');
    }
}
