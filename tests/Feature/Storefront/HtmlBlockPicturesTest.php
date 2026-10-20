<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockPictures;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Menu\CompiledMenus;
use App\Storefront\Menu\MenuRecompiler;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MENU-5, «Снимки с връзки»: a picture of an HTML block is a Media asset with its own optional address. The ONE method HtmlBlockModel::renderedHtml() prints the pictures first (stacked,
 * `<figure><a><img></a></figure>`, no `<a>` without an address), then the body, all of it through HtmlBlockSanitizer::render(); the mega menu and the footer use it, so both follow a changed
 * address and an asset that becomes READY.
 */
class HtmlBlockPicturesTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        CompiledMenus::forgetWarnings();
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    /** @param  list<array<string, mixed>>  $pictures */
    private function block(array $pictures, string $body = '<p>Body</p>'): HtmlBlockModel
    {
        return HtmlBlockModel::create(['title' => 'B', 'body' => $body, 'pictures' => $pictures]);
    }

    private function mega(HtmlBlockModel $block): void
    {
        $this->saveMenu('main', [$this->megaItem('Boys', '/boys', 1, [$this->columnItem([$this->menuItem('link', ['label' => 'Jackets', 'url' => '/jackets'])])], ['html_block_id' => $block->id])]);
    }

    private function megaHtml(): string
    {
        return (string) json_decode((string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY), true)['menus']['main'][0]['html'];
    }

    // ---- the markup ----------------------------------------------------------------------------------------------------------------

    public function test_a_picture_with_an_address_is_a_link_around_the_image_and_one_without_is_a_bare_image(): void
    {
        $linked = $this->makeMenuImage(11);
        $plain = $this->makeMenuImage(12);
        $html = $this->block([['media_asset_id' => $linked, 'alt' => 'Boys sale', 'url' => '/product-category/boys'], ['media_asset_id' => $plain, 'alt' => '', 'url' => '']], '')->renderedHtml();

        $this->assertStringContainsString('<figure><a href="/product-category/boys" rel="noopener nofollow"><img loading="lazy" decoding="async" src="/storage/m/11-medium.webp" alt="Boys sale" width="900" height="675"></a></figure>', $html);
        $this->assertStringContainsString('<figure><img loading="lazy" decoding="async" src="/storage/m/12-medium.webp" alt="" width="900" height="675"></figure>', $html);
        $this->assertSame(1, substr_count($html, '<a '), 'no <a> without an address');
    }

    public function test_the_pictures_come_first_in_order_and_then_the_body_and_a_missing_asset_is_skipped(): void
    {
        $a = $this->makeMenuImage(21);
        $b = $this->makeMenuImage(22);
        $html = $this->block([['media_asset_id' => $b, 'alt' => 'second-in-asset-order', 'url' => ''], ['media_asset_id' => 999, 'alt' => 'gone', 'url' => '/x'], ['media_asset_id' => $a, 'alt' => 'last', 'url' => '']], '<p>The body</p>')->renderedHtml();

        $this->assertLessThan(strpos($html, 'last'), strpos($html, 'second-in-asset-order'), 'the order of the list');
        $this->assertLessThan(strpos($html, 'The body'), strpos($html, 'last'), 'pictures first, the body after');
        $this->assertStringNotContainsString('gone', $html);
        $this->assertStringNotContainsString('/x', $html);
    }

    public function test_an_asset_that_is_not_ready_prints_the_original_file_until_it_is(): void
    {
        $id = $this->makeMenuImage(31, 'pending');
        $html = $this->block([['media_asset_id' => $id, 'alt' => 'Waiting', 'url' => '']], '')->renderedHtml();

        $this->assertStringContainsString('src="/storage/m/31.jpg"', $html);
        $this->assertStringNotContainsString('width=', $html, 'no size is known before the variants exist');
    }

    public function test_the_alt_of_the_asset_is_the_fallback_and_everything_is_escaped(): void
    {
        $id = $this->makeMenuImage(41);
        DB::table('catalog_media')->where('id', $id)->update(['alt_text' => 'From the asset "x"']);
        $html = $this->block([['media_asset_id' => $id, 'alt' => '', 'url' => '/a?x=1&y=2']], '')->renderedHtml();

        $this->assertStringContainsString("alt='From the asset \"x\"'", $html, 'the quotes cannot break out of the attribute');
        $this->assertStringContainsString('href="/a?x=1&amp;y=2"', $html);
    }

    // ---- the address ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'https' => ['https://example.com/sale', true],
            'a path of the shop' => ['/product-category/boys', true],
            'javascript:' => ['javascript:alert(1)', false],
            'JaVaScRiPt: with a tab' => ["java\tscript:alert(1)", false],
            'data:' => ['data:text/html;base64,PHNjcmlwdD4=', false],
            'http:' => ['http://example.com/', false],
            'protocol-relative' => ['//evil.example/x', false],
            'mailto:' => ['mailto:a@b.test', false],
            'a backslash trick' => ['/\\evil.example', false],
            'a relative word' => ['sale', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_https_and_shop_paths_are_acceptable_addresses(string $url, bool $ok): void
    {
        $this->assertSame($ok, HtmlBlockPictures::safeUrl($url) !== null);
    }

    public function test_the_model_keeps_at_most_four_pictures_and_drops_an_unsafe_address_it_is_handed(): void
    {
        $ids = array_map(fn (int $n): int => $this->makeMenuImage(50 + $n), range(1, 5));
        $pictures = array_map(fn (int $id): array => ['media_asset_id' => $id, 'alt' => 'p', 'url' => 'javascript:alert(1)'], $ids);
        $block = $this->block($pictures);

        $this->assertCount(4, $block->fresh()->pictures);
        $this->assertSame(['', '', '', ''], array_column($block->fresh()->pictures, 'url'));
    }

    public function test_the_checks_refuse_a_fifth_picture_an_unknown_asset_and_a_bad_address(): void
    {
        $id = $this->makeMenuImage(61);
        $good = ['media_asset_id' => $id, 'alt' => 'ok', 'url' => ''];

        $this->assertSame([], HtmlBlockPictures::errors([$good]));
        $this->assertArrayHasKey('pictures', HtmlBlockPictures::errors(array_fill(0, 5, $good)));
        $this->assertArrayHasKey('0.image', HtmlBlockPictures::errors([['media_asset_id' => 424242, 'alt' => '', 'url' => '']]));
        $this->assertArrayHasKey('0.url', HtmlBlockPictures::errors([['media_asset_id' => $id, 'alt' => '', 'url' => 'http://example.com']]));
        $this->assertArrayHasKey('0.alt', HtmlBlockPictures::errors([['media_asset_id' => $id, 'alt' => str_repeat('a', 201), 'url' => '']]));
    }

    // ---- one method, one pass through the filter --------------------------------------------------------------------------------------

    public function test_a_block_of_pictures_only_has_html_and_the_whole_result_goes_through_the_sanitizer(): void
    {
        $id = $this->makeMenuImage(71);
        // a row written behind the model's back (a script, a hand-edited database): the printed result is cleaned anyway
        $raw = DB::table('html_blocks')->insertGetId(['title' => 'Raw', 'body' => '<p>Hi</p><script>alert(1)</script><img src="javascript:x">', 'pictures' => json_encode([['media_asset_id' => $id, 'alt' => 'A', 'url' => 'javascript:alert(1)']]), 'created_at' => now(), 'updated_at' => now()]);
        $html = HtmlBlockModel::renderMany([$raw])[$raw];

        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('<figure><img', $html);
        $this->assertStringContainsString('Hi', $html);
        $this->assertNotSame('', $this->block([['media_asset_id' => $id, 'alt' => '', 'url' => '']], '')->renderedHtml(), 'pictures only, no body');
    }

    public function test_rendering_twice_keeps_the_picture_and_its_link(): void
    {
        $id = $this->makeMenuImage(81);
        $once = $this->block([['media_asset_id' => $id, 'alt' => 'A', 'url' => '/sale']])->renderedHtml();

        $this->assertSame($once, HtmlBlockSanitizer::render($once), 'idempotent');
        $this->assertStringContainsString('href="/sale"', HtmlBlockSanitizer::render(HtmlBlockSanitizer::render($once)));
    }

    public function test_several_blocks_cost_one_query_for_the_blocks_and_one_for_the_assets(): void
    {
        $blocks = [];

        foreach ([91, 92, 93] as $n) {
            $blocks[] = $this->block([['media_asset_id' => $this->makeMenuImage($n), 'alt' => 'a', 'url' => '/x']])->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = HtmlBlockModel::renderMany($blocks);
        $queries = array_map(fn (array $q): string => $q['query'], DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(3, $out);
        $this->assertCount(2, $queries, implode("\n", $queries));
    }

    // ---- the places that print a block ----------------------------------------------------------------------------------------------

    public function test_the_compiled_mega_menu_has_the_linked_picture_and_follows_a_changed_address(): void
    {
        $id = $this->makeMenuImage(101);
        $block = $this->block([['media_asset_id' => $id, 'alt' => 'Boys', 'url' => '/product-category/boys']], '');
        $this->mega($block);

        $this->assertStringContainsString('<a href="/product-category/boys" rel="noopener nofollow"><img', $this->megaHtml());

        $block->pictures = [['media_asset_id' => $id, 'alt' => 'Boys', 'url' => 'https://example.com/new']];
        $block->save();
        $this->fresh();
        $this->get('/')->assertOk();

        $this->assertStringContainsString('href="https://example.com/new"', $this->megaHtml());
        $this->assertStringNotContainsString('/product-category/boys', $this->megaHtml());
    }

    public function test_the_footer_html_block_shows_the_linked_picture_compiled_and_after_the_second_pass(): void
    {
        $id = $this->makeMenuImage(111);
        $block = $this->block([['media_asset_id' => $id, 'alt' => 'Sale', 'url' => '/sale']], '');
        $layout = FooterLayout::default();
        $layout['upper']['rows'][0]['columns'][0]['blocks'] = [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]];
        app(FooterCompiler::class)->publish($layout, $this->settings());
        $this->fresh();

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), true);
        $this->assertStringContainsString('<a href="/sale" rel="noopener nofollow"><img', $compiled['blocks'][(string) $block->id]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<a href="/sale" rel="noopener nofollow"><img loading="lazy"', substr($html, (int) strpos($html, '<footer')), 'FooterView renders again: the link survives');

        // saving the block changes the footer too
        $block->pictures = [['media_asset_id' => $id, 'alt' => 'Sale', 'url' => '/outlet']];
        $block->save();
        $this->fresh();
        $this->assertStringContainsString('href="/outlet"', substr($this->get('/')->assertOk()->getContent(), (int) strpos($this->get('/')->getContent(), '<footer')));
    }

    public function test_when_an_asset_becomes_ready_the_menu_and_the_footer_print_its_variant(): void
    {
        $id = $this->makeMenuImage(121, 'pending');
        $block = $this->block([['media_asset_id' => $id, 'alt' => 'Late', 'url' => '/x']], '');
        $this->mega($block);
        $layout = FooterLayout::default();
        $layout['upper']['rows'][0]['columns'][0]['blocks'] = [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]];
        app(FooterCompiler::class)->publish($layout, $this->settings());

        $this->assertStringContainsString('src="/storage/m/121.jpg"', $this->megaHtml());

        // the job finishes: the asset is saved through Eloquent with its variants (as EloquentMediaAssetRepository does)
        $asset = MediaAssetModel::query()->findOrFail($id);
        $asset->processing_status = 'ready';
        $asset->variants = [['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 82, 'path' => 'm/121-medium.webp']];
        $asset->save();
        app(MenuRecompiler::class)->flush();

        $this->assertStringContainsString('src="/storage/m/121-medium.webp"', $this->megaHtml());
        $this->assertStringContainsString('/storage/m/121-medium.webp', (string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY));
    }

    public function test_the_phone_drawer_still_shows_no_picture(): void
    {
        $id = $this->makeMenuImage(131);
        $this->mega($this->block([['media_asset_id' => $id, 'alt' => 'Boys', 'url' => '/x']]));
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'class="sf-drawer '));

        $this->assertStringNotContainsString('<img', substr($drawer, 0, (int) strpos($drawer, '</header>')), 'MENU-3: no picture on the phone');
        $this->assertStringContainsString('<img', $html, 'but the mega panel of the bar has it');
    }
}
