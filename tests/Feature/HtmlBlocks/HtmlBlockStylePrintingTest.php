<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HB-1: what a styled HTML block prints and how the shop follows an edit. The wrapper `div.sf-hb.sf-hb-{id}` is made in ONE place (HtmlBlockModel::renderMany) and reaches the footer, the right column and
 * the mega menu; a block with no style prints exactly as before; the rule of a styled block is in the token stylesheet and follows a style-only edit at once, with no other action.
 */
class HtmlBlockStylePrintingTest extends TestCase
{
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const STYLE = ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'radius' => 4, 'align' => 'center'];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '100.00', 'stock' => 4]);
        $this->app->forgetScopedInstances();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function wrapper(int $id, string $inner = '<p>Promo</p>'): string
    {
        return '<div class="sf-hb sf-hb-'.$id.'">'.$inner.'</div>';
    }

    /** The block in the footer, in the right column and in the mega menu. */
    private function showEverywhere(HtmlBlockModel $block): void
    {
        $layout = FooterLayout::default();
        $layout['upper']['rows'] = [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]]]];
        app(FooterCompiler::class)->publish($layout, $this->settings());

        app(ProductSummaryCompiler::class)->publish(['components' => [
            ['type' => 'title', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => $block->id]], ['type' => 'purchase', 'settings' => []],
        ]], $this->settings());

        $this->saveMenu('main', [$this->megaItem('Dresses', '/dresses', 1, [$this->columnItem([$this->menuItem('link', ['label' => 'Maxi', 'url' => '/maxi'])])], ['html_block_id' => $block->id])]);
        $this->app->forgetScopedInstances();
    }

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    private function product(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/summer-dress')->assertOk()->getContent();
    }

    /** The token stylesheet of the shop as the storefront links it, or '' when none is linked. */
    private function stylesheet(): string
    {
        // a settings repository made before the edit remembers what it read then: read with a fresh one
        $this->app->forgetScopedInstances();
        $json = $this->settings()->get(AppearanceSettings::COMPILED_KEY);
        $hash = is_string($json) ? (json_decode($json, true)['hash'] ?? null) : null;

        return $hash === null ? '' : (string) Storage::disk('public')->get('appearance/tokens-'.$hash.'.css');
    }

    // ---- printing -----------------------------------------------------------------------------------------------------------------------

    public function test_a_styled_block_is_wrapped_in_the_footer_the_right_column_and_the_mega_menu(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => self::STYLE]);
        $this->showEverywhere($block);

        $home = $this->home();
        $product = $this->product();
        $wrapper = $this->wrapper($block->id);

        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html">'.$wrapper.'</div>', $home, 'the mega menu');
        $this->assertStringContainsString($wrapper, substr($home, (int) strpos($home, '<footer')), 'the footer');
        $this->assertStringContainsString('<div class="sf-summary-html">'.$wrapper.'</div>', $product, 'the right column');

        foreach ([$home, $product] as $page) {
            $this->assertStringNotContainsString(' style=', $page, 'no inline style on the shop');
            $this->assertStringNotContainsString('<script>', $page);
        }
    }

    public function test_the_wrapper_is_made_in_one_place_and_the_compiled_copies_carry_it(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => self::STYLE]);
        $this->showEverywhere($block);

        $one = HtmlBlockModel::renderMany([$block->id])[$block->id];
        $this->assertSame($this->wrapper($block->id), $one);

        $footer = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), true);
        $summary = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $menus = (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY);

        $this->assertSame($one, $footer['blocks'][(string) $block->id], 'the footer\'s compiled copy');
        $this->assertSame($one, $summary['zones']['product_summary']['global']['blocks'][(string) $block->id], 'the right column\'s compiled copy');
        $this->assertStringContainsString(json_encode($one, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $menus, 'the compiled menus');
    }

    public function test_a_second_cleaning_keeps_the_wrapper_and_cleans_what_is_inside_it(): void
    {
        $wrapped = $this->wrapper(7, '<p>ok</p>');

        $this->assertSame($wrapped, HtmlBlockSanitizer::render($wrapped), 'idempotent');
        $this->assertSame($this->wrapper(7, '<p>hi</p>'), HtmlBlockSanitizer::render($this->wrapper(7, '<p onclick="x()">hi</p><script>alert(1)</script>')));
        $this->assertSame('<p>x</p>', HtmlBlockSanitizer::render('<div class="other"><p>x</p></div>'), 'any other div is dropped as ever');
        $this->assertSame('<p>x</p>', HtmlBlockSanitizer::render('<div class="sf-hb sf-hb-7 evil"><p>x</p></div>'), 'only the exact wrapper is recognised');
        $this->assertSame('', HtmlBlockStyle::wrap(7, ''), 'an empty block has nothing to wrap');
    }

    public function test_a_block_with_no_style_prints_exactly_as_before_and_the_shop_has_no_stylesheet(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>']);
        $this->showEverywhere($block);

        $this->assertSame('<p>Promo</p>', HtmlBlockModel::renderMany([$block->id])[$block->id], 'no wrapper');
        $this->assertStringContainsString('<div class="sf-summary-html"><p>Promo</p></div>', $this->product());
        $this->assertStringNotContainsString('sf-hb', $this->home());
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'no styled block: no token stylesheet is compiled');
        $this->assertNull($block->fresh()->style);
    }

    public function test_a_style_of_defaults_is_stored_as_null_and_prints_no_wrapper(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => ['bg' => null, 'pad_v' => 0, 'width_mode' => 'auto', 'align' => 'left']]);

        $this->assertNull(DB::table('html_blocks')->where('id', $block->id)->value('style'));
        $this->assertSame('<p>Promo</p>', HtmlBlockModel::renderMany([$block->id])[$block->id]);
    }

    public function test_the_style_is_stored_normalised(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => ['bg' => '#2E7D32', 'pad_v' => '6', 'align' => 'center', 'size' => 'normal']]);

        // the database's JSON type keeps its own key order: the content is compared, not the order
        $this->assertEquals(['bg' => '#2e7d32', 'pad_v' => 6, 'align' => 'center'], json_decode((string) DB::table('html_blocks')->where('id', $block->id)->value('style'), true));
    }

    public function test_a_style_that_does_not_validate_is_never_stored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => ['bg' => 'red']]);
    }

    public function test_a_hand_edited_style_in_the_database_prints_no_wrapper_and_no_rule(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>']);
        DB::table('html_blocks')->where('id', $block->id)->update(['style' => json_encode(['bg' => 'url(x);}body{display:none'])]);

        $this->assertSame('<p>Promo</p>', HtmlBlockModel::renderMany([$block->id])[$block->id]);
        $this->assertSame([], HtmlBlockStyle::storedRules());
    }

    // ---- the token stylesheet follows -------------------------------------------------------------------------------------------------

    public function test_a_style_only_edit_changes_the_stylesheet_and_the_compiled_copies_at_once(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>']);
        $this->showEverywhere($block);
        $this->assertStringNotContainsString('sf-hb', $this->home());
        $this->assertStringNotContainsString('sf-hb', $this->product());

        $block->update(['style' => self::STYLE]);

        $rule = '.sf-hb-'.$block->id.'{background:#2e7d32;color:#ffffff;padding:6px 10px;border-radius:4px;width:fit-content;max-width:100%;text-align:center}';
        $this->assertStringContainsString($rule, $this->stylesheet(), 'the token stylesheet carries the rule');
        $home = $this->home();
        $this->assertStringContainsString($this->wrapper($block->id), substr($home, (int) strpos($home, '<footer')), 'the footer follows');
        $this->assertStringContainsString($this->wrapper($block->id), $this->product(), 'the right column follows');
        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html">'.$this->wrapper($block->id), $home, 'the mega menu follows');
        $this->assertStringContainsString('tokens-', $home, 'the page links the stylesheet');

        // another style-only edit: the rule changes
        $block->update(['style' => ['bg' => '#112233'] + self::STYLE]);
        $this->assertStringContainsString('.sf-hb-'.$block->id.'{background:#112233;', $this->stylesheet());
        $this->assertStringNotContainsString('background:#2e7d32', $this->stylesheet());
    }

    public function test_restyling_back_to_the_defaults_removes_the_rule_and_the_wrapper(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => self::STYLE]);
        $this->showEverywhere($block);
        $this->assertStringContainsString('sf-hb-'.$block->id, $this->stylesheet());

        $block->update(['style' => null]);

        $this->assertSame('', $this->stylesheet());
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'nothing is styled: the shop has no token stylesheet again');
        $this->assertStringNotContainsString('sf-hb', $this->product());
        $this->assertStringNotContainsString('sf-hb', $this->home());
    }

    public function test_deleting_a_styled_block_removes_its_rule(): void
    {
        $keep = HtmlBlockModel::create(['title' => 'Keep', 'body' => '<p>k</p>', 'style' => ['bg' => '#112233']]);
        $gone = HtmlBlockModel::create(['title' => 'Gone', 'body' => '<p>g</p>', 'style' => ['bg' => '#445566']]);
        $this->assertStringContainsString('sf-hb-'.$gone->id.'{', $this->stylesheet());

        $this->assertTrue($gone->delete());

        $css = $this->stylesheet();
        $this->assertStringNotContainsString('sf-hb-'.$gone->id.'{', $css);
        $this->assertStringContainsString('sf-hb-'.$keep->id.'{', $css);
    }

    public function test_a_change_of_the_body_only_does_not_touch_the_stylesheet(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => self::STYLE]);
        $before = $this->settings()->get(AppearanceSettings::COMPILED_KEY);

        $block->update(['body' => '<p>New text</p>']);

        $this->assertSame($before, $this->settings()->get(AppearanceSettings::COMPILED_KEY));
    }

    // ---- the cap ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_stylesheet_carries_at_most_a_hundred_rules_in_order_of_id(): void
    {
        $rows = [];

        foreach (range(1, 101) as $n) {
            $rows[] = ['id' => $n, 'title' => 'B'.$n, 'body' => '<p>x</p>', 'style' => json_encode(['bg' => '#112233']), 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('html_blocks')->insert($rows);
        $rules = HtmlBlockStyle::storedRules();

        $this->assertCount(100, $rules);
        $this->assertSame('.sf-hb-1{background:#112233;width:fit-content;max-width:100%}', $rules[0]);
        $this->assertSame('.sf-hb-100{background:#112233;width:fit-content;max-width:100%}', $rules[99]);

        // exactly a hundred styled blocks: a new one is over the cap, one of the hundred may still be edited, and unstyling one makes room
        DB::table('html_blocks')->where('id', 101)->delete();
        $this->assertTrue(HtmlBlockStyle::capReached(null), 'a new styled block would be the 101st');
        $this->assertFalse(HtmlBlockStyle::capReached(5), 'an already styled block may be edited: it is one of the hundred');
        DB::table('html_blocks')->where('id', 5)->update(['style' => null]);
        $this->assertFalse(HtmlBlockStyle::capReached(null), 'unstyling one allows another');
    }

    // ---- sessionless, no inline style or script ----------------------------------------------------------------------------------------

    public function test_pages_with_a_styled_block_stay_sessionless_with_no_inline_style_or_script(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>Promo</p>', 'style' => self::STYLE]);
        $this->showEverywhere($block);

        foreach (['/', '/product/summer-dress'] as $url) {
            $this->app->forgetScopedInstances();
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();

            $this->assertSame([], $response->headers->getCookies(), $url.': no cookie is set');
            $this->assertStringNotContainsString(' style=', $html, $url);
            $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html), $url.': no inline script');
        }
    }
}
