<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HP-2: the «Решетка от снимки» section of the home page: its closed lists and the cells (validated on save, publish and read; a bad value is its own default on read), what it prints (a cell with no block is not
 * printed, the phone list in order, the desktop grid carries the classes that hide it on a phone), the compiled copy (no query per block), the protection of the blocks it shows, the draft and the signed preview.
 */
class HomeImageGridTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

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
    }

    private function block(string $text = 'Cell'): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $text, 'body' => "<p>{$text}</p>"]);
    }

    /** @param  array<string, mixed>  $settings */
    private function grid(array $settings = []): array
    {
        return ['type' => 'image_grid', 'settings' => $settings];
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

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): array
    {
        return [
            'scheme' => [['scheme' => 'seven'], 'scheme'], 'scheme list' => [['scheme' => ['three']], 'scheme'],
            'ratio' => [['ratio' => '21:9'], 'ratio'],
            'gap 10' => [['gap' => '10'], 'gap'], 'gap px' => [['gap' => '12px'], 'gap'], 'gap array' => [['gap' => [16]], 'gap'], 'gap int' => [['gap' => 16], 'gap'],
            'radius' => [['radius' => '10'], 'radius'], 'phone gap' => [['phone_gap' => '5'], 'phone_gap'],
            'unknown cell' => [['scheme' => 'two', 'cells' => ['a' => 1, 'c' => 2]], 'cells.c'],
            'cell not an id' => [['cells' => ['a' => 'x']], 'cells.a'], 'cell zero' => [['cells' => ['a' => 0]], 'cells.a'], 'cells not a map' => [['cells' => 'a'], 'cells'],
            'five phone blocks' => [['phone_blocks' => [1, 2, 3, 4, 5]], 'phone_blocks'], 'phone block not an id' => [['phone_blocks' => ['x']], 'phone_blocks.0'],
            'phone blocks not a list' => [['phone_blocks' => ['a' => 1]], 'phone_blocks'],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_on_save(array $settings, string $key): void
    {
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid($settings)]]);

        $this->assertArrayHasKey("sections.0.settings.{$key}", $errors, json_encode($errors));
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Storefront\Home\HomeCompiler::class)->publish(['spacing' => '32', 'sections' => [$this->grid($settings)]], app(SiteSettingsRepository::class));
    }

    public function test_the_defaults_and_the_cells_of_the_chosen_scheme(): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid()]]);
        $s = $clean['sections'][0]['settings'];

        $this->assertSame([], $errors);
        $this->assertSame(['three', '3:2', '16', '0', '8', [], ['a' => null, 'b' => null, 'c' => null]], [$s['scheme'], $s['ratio'], $s['gap'], $s['radius'], $s['phone_gap'], $s['phone_blocks'], $s['cells']]);

        [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(['scheme' => 'uneven-a', 'cells' => ['a' => '5', 'f' => 6, 'g' => null], 'phone_blocks' => [3, 4]])]]);
        $this->assertSame(['a' => 5, 'b' => null, 'c' => null, 'd' => null, 'e' => null, 'f' => 6], $clean['sections'][0]['settings']['cells'], 'the names of the scheme only, in reading order');
        $this->assertSame([3, 4], $clean['sections'][0]['settings']['phone_blocks']);
    }

    public function test_on_read_each_bad_value_is_its_default_and_the_other_settings_stay(): void
    {
        $a = $this->block('Alpha');
        $b = $this->block('Beta');
        $settings = app(SiteSettingsRepository::class);
        $layout = ['spacing' => '32', 'sections' => [$this->grid(['scheme' => 'four', 'ratio' => '99:1', 'gap' => '12px', 'radius' => '24', 'cells' => ['a' => $a->id, 'z' => 5, 'b' => $b->id], 'phone_blocks' => [$a->id, 'x'], 'phone_gap' => '4'])
            + []]];
        $layout['sections'][0]['settings'] += ['show' => true, 'spacing' => 'default'];
        $html = [(string) $a->id => '<p>Alpha</p>', (string) $b->id => '<p>Beta</p>'];
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode(['hash' => 'x', 'zones' => ['home' => ['global' => ['layout' => $layout, 'blocks' => $html]]]]));
        app(StorefrontAppearance::class)->refresh();

        $page = $this->home();

        $this->assertStringContainsString('sf-hp-s-four', $page, 'the good scheme stays');
        $this->assertStringContainsString('sf-hp-r-3-2', $page, 'a bad ratio is the default');
        $this->assertStringContainsString('sf-hp-gg-16', $page, 'a bad gap is the default');
        $this->assertStringContainsString('sf-hp-cr-24', $page, 'a good radius stays');
        $this->assertStringContainsString('sf-hp-pg-4', $page);
        $this->assertStringContainsString('<p>Alpha</p>', $page);
        $this->assertStringContainsString('<p>Beta</p>', $page);
        $this->assertStringNotContainsString('sf-category-row', $page, 'the layout is still the page (not the old home)');
    }

    // ---- ★ printing -----------------------------------------------------------------------------------------------------------------------

    public function test_a_cell_with_no_block_is_not_printed_and_a_block_prints_through_the_compiled_copy(): void
    {
        $a = $this->block('Alpha');
        $c = $this->block('Gamma');
        $this->publish([$this->grid(['scheme' => 'three', 'cells' => ['a' => $a->id, 'c' => $c->id]])]);
        $page = $this->home();

        $this->assertStringContainsString('<div class="sf-hp-cell sf-hp-a-a"><p>Alpha</p></div>', $page);
        $this->assertStringContainsString('<div class="sf-hp-cell sf-hp-a-c"><p>Gamma</p></div>', $page);
        $this->assertStringNotContainsString('sf-hp-a-b', $page, 'an empty cell has no placeholder');
        $this->assertSame(2, substr_count($page, 'class="sf-hp-cell '));
    }

    public function test_the_phone_list_prints_the_blocks_in_order_and_an_empty_list_prints_none(): void
    {
        $a = $this->block('Alpha');
        $b = $this->block('Beta');
        $c = $this->block('Gamma');
        $this->publish([$this->grid(['scheme' => 'two', 'cells' => ['a' => $a->id], 'phone_blocks' => [$c->id, $a->id, $b->id]])]);
        $page = $this->home();

        $this->assertMatchesRegularExpression('#class="sf-hp-grid [^"]*sf-hp-s-two[^"]*"#', $page, 'the desktop grid has its own class (the stylesheet hides it below 48rem)');
        $this->assertMatchesRegularExpression('#class="sf-hp-phone sf-hp-pg-8 sf-hp-cr-0"#', $page);
        $phone = substr($page, (int) strpos($page, 'class="sf-hp-phone'));
        $this->assertLessThan(strpos($phone, 'Alpha'), strpos($phone, 'Gamma'));
        $this->assertLessThan(strpos($phone, 'Beta'), strpos($phone, 'Alpha'), 'in the order chosen');
        $this->assertSame(3, substr_count($page, 'class="sf-hp-pcell"'));

        $this->publish([$this->grid(['scheme' => 'two', 'cells' => ['a' => $a->id]])]);
        $page = $this->home();
        $this->assertStringNotContainsString('sf-hp-phone', $page, 'no phone element when the list is empty');
        $this->assertStringContainsString('sf-hp-grid', $page);

        $this->publish([$this->grid(['scheme' => 'two'])]);
        $this->assertStringNotContainsString('sf-hp-grid', $this->home(), 'nothing to show: no empty box');
    }

    public function test_the_stylesheet_hides_the_grid_on_a_phone_and_the_phone_list_from_48rem(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));
        $section = substr($css, (int) strpos($css, 'HP-2 image grid'));

        $this->assertMatchesRegularExpression('/\.sf-hp-phone\s*\{\s*display:\s*none;/', $section, 'from 48rem the phone list is hidden');
        $this->assertMatchesRegularExpression('/@media \(max-width: 47\.99rem\)\s*\{\s*\.sf-hp-grid\s*\{\s*display:\s*none;\s*\}\s*\.sf-hp-phone\s*\{\s*display:\s*flex;/', $section);
        $this->assertStringContainsString('.sf-hp-grid .sf-hp-cell > .sf-hb', $section);
        $this->assertDoesNotMatchRegularExpression('/!important\s*;/', $section, 'the override wins by specificity');

        foreach (array_keys(\App\Storefront\Home\ImageGridGeometry::SCHEMES) as $scheme) {
            $this->assertStringContainsString(".sf-hp-s-{$scheme} {", $section, $scheme);
        }
    }

    public function test_no_inline_style_no_inline_script_and_the_same_page_for_every_visitor(): void
    {
        $blocks = [];

        foreach (range(1, 6) as $i) {
            $blocks[] = $this->block("Cell {$i}");
        }

        $this->publish([$this->grid(['scheme' => 'six', 'radius' => '16', 'gap' => '24', 'cells' => array_combine(['a', 'b', 'c', 'd', 'e', 'f'], array_map(fn ($b) => $b->id, $blocks)), 'phone_blocks' => array_map(fn ($b) => $b->id, array_slice($blocks, 0, 4))])]);
        $first = $this->home();
        $this->flushSession();
        $second = $this->withHeader('Cookie', 'x=1')->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(' style=', $first);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $first);
        $this->assertSame(preg_replace('/csrf-token" content="[^"]*"/', '', $first), preg_replace('/csrf-token" content="[^"]*"/', '', $second));
    }

    public function test_block_text_is_escaped_as_the_block_renderer_does(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'X', 'body' => '<p>a &lt;script&gt;alert(1)&lt;/script&gt; b</p><script>alert(2)</script>']);
        $this->publish([$this->grid(['scheme' => 'one', 'cells' => ['a' => $block->id]])]);
        $page = $this->home();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertStringNotContainsString('<script>alert', $page);
    }

    // ---- ★ queries, draft, preview, protection --------------------------------------------------------------------------------------------

    public function test_six_cells_and_four_phone_blocks_cost_the_pinned_queries_of_the_home_layout(): void
    {
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
        $pin = $this->queries();

        $blocks = array_map(fn (int $i): HtmlBlockModel => $this->block("Cell {$i}"), range(1, 6));
        $this->publish([$this->grid(['scheme' => 'six', 'cells' => array_combine(['a', 'b', 'c', 'd', 'e', 'f'], array_map(fn ($b) => $b->id, $blocks)), 'phone_blocks' => array_map(fn ($b) => $b->id, array_slice($blocks, 0, 4))])]);

        $this->assertSame($pin, $this->queries(), 'the blocks are in the compiled copy: no query per block');
        $this->assertSame(2, $pin, 'the HP-1 pin of a home layout');
    }

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $block = $this->block('Only in the draft');
        [$draft] = HomeValidator::validate(['spacing' => '16', 'sections' => [$this->grid(['scheme' => 'two', 'cells' => ['a' => $block->id]])]]);
        HomeDrafts::saveDraft($draft);

        $this->assertStringNotContainsString('Only in the draft', $this->home());

        $this->app->forgetScopedInstances();
        $preview = $this->get(DesignPreviewController::previewUrl(HomeCatalog::ZONE))->assertOk();

        $this->assertStringContainsString('Only in the draft', $preview->getContent());
        $this->assertStringContainsString('sf-hp-s-two', $preview->getContent());
        $preview->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_a_block_in_any_cell_or_phone_slot_cannot_be_deleted(): void
    {
        $cell = $this->block('Cell');
        $phone = $this->block('Phone');
        $free = $this->block('Free');
        $this->publish([$this->grid(['scheme' => 'two', 'cells' => ['b' => $cell->id], 'phone_blocks' => [$phone->id]])]);

        foreach ([$cell, $phone] as $block) {
            $this->assertTrue(HtmlBlockUsage::isUsed((int) $block->id));
            $this->assertFalse($block->delete());
            $this->assertNotNull(HtmlBlockModel::query()->find($block->id));
            $this->assertNotNull(HtmlBlockUsage::refusal($block));
        }

        $this->assertTrue($free->delete());

        // a block that is only in the DRAFT is protected too
        $draftOnly = $this->block('Draft');
        [$draft] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid(['cells' => ['a' => $draftOnly->id]])]]);
        HomeDrafts::saveDraft($draft);
        $this->assertTrue(HtmlBlockUsage::isUsed((int) $draftOnly->id));
    }

    public function test_an_edited_block_is_in_the_page_after_the_compiled_copy_is_refreshed(): void
    {
        $block = $this->block('Before');
        $this->publish([$this->grid(['scheme' => 'one', 'cells' => ['a' => $block->id], 'phone_blocks' => [$block->id]])]);

        $block->body = '<p>After</p>';
        $block->save();
        app(\App\Storefront\Home\HomeCompiler::class)->refresh();
        $page = $this->home();

        $this->assertStringContainsString('After', $page);
        $this->assertStringNotContainsString('Before', $page);
    }
}
