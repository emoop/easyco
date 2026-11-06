<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionCompiler;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use App\Storefront\Sections\SectionTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P4c: the section sources «Мета информация» (`meta`) and «HTML блокове» (`blocks`), the brand condition of an HTML block, and the per-product switch «Показвай на този продукт».
 *
 * The world: brands Nike (1) and Guess (2); tags «дрехи Nike» (1), «обувки Nike» (2), «Лято» (3, a tag with markup in its name: 4); a season «Лято 26». Products: P1 Nike clothes (tag 1, season, tag 3), P2 Nike shoes (tag 2),
 * P3 Guess (tag 1: the Nike clothes tag on another brand), P4 Nike with no tag, no season. Blocks: «Размери Nike облекло» (brand Nike AND tag 1), «Размери Nike обувки» (brand Nike AND tag 2), «Размери Guess» (brand Guess),
 * «За всички» (no condition).
 */
class ProductMetaBlocksSectionsTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const META = '55555555-5555-4555-8555-555555555555';

    private const SIZES = '66666666-6666-4666-8666-666666666666';

    private const DELIV = '11111111-1111-4111-8111-111111111111';

    private const SIZE = '22222222-2222-4222-8222-222222222222';

    /** @var array<string, int> */
    private array $block = [];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Dresses', 'dresses');
        $this->makeBrand(1, 'Nike', 'nike');
        $this->makeBrand(2, 'Guess', 'guess');
        $this->makeTag(1, 'дрехи Nike', 'drehi-nike');
        $this->makeTag(2, 'обувки Nike', 'obuvki-nike');
        $this->makeTag(3, 'Лято', 'lyato');
        $this->makeTag(4, '<script>alert(1)</script> "q"', 'evil');
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Лято 26', 'slug' => 'lyato-26', 'created_at' => now(), 'updated_at' => now()]);

        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => 1, 'tags' => [1, 3]]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => 1]);
        $this->makeProduct(2, ['name' => 'P2', 'slug' => 'p2', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => 1, 'tags' => [2]]);
        $this->makeProduct(3, ['name' => 'P3', 'slug' => 'p3', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => 2, 'tags' => [1]]);
        $this->makeProduct(4, ['name' => 'P4', 'slug' => 'p4', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'brand' => 1]);
        $this->makeProduct(5, ['name' => 'P5', 'slug' => 'p5', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'tags' => [4]]);
    }

    private function visit(string $slug): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug);
    }

    private function block(string $name, string $html, ?array $conditions = null): int
    {
        return $this->block[$name] = (int) HtmlBlockModel::query()->create(['title' => $name, 'body' => $html, 'conditions' => $conditions])->id;
    }

    private function blocks(): void
    {
        $this->block('nike-clothes', '<p>NIKECLOTHESSIZES</p>', ['brands' => [1], 'tags' => [1]]);
        $this->block('nike-shoes', '<p>NIKESHOESSIZES</p>', ['brands' => [1], 'tags' => [2]]);
        $this->block('guess', '<p>GUESSSIZES</p>', ['brands' => [2]]);
        $this->block('all', '<p>FORALLPRODUCTS</p>');
    }

    /** @param list<array<string, mixed>> $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function accordion(): void
    {
        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    private function tabs(array $keys): void
    {
        [$clean, $errors] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => '', 'sections' => $keys]]]);
        $this->assertSame([], $errors, json_encode($errors));
        BelowStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function queryCount(string $slug): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($slug)->assertOk();

        return $n;
    }

    private function sizes(array $ids): array
    {
        return ['key' => self::SIZES, 'title' => 'Sizes', 'source' => 'blocks', 'blocks' => $ids];
    }

    private function meta(bool $seasons = true, bool $tags = true): array
    {
        return ['key' => self::META, 'title' => 'Meta', 'source' => 'meta', 'meta' => ['seasons' => $seasons, 'tags' => $tags]];
    }

    // ---- ★ meta -----------------------------------------------------------------------------------------------------------------------

    /** ★ the season and the tags print as links to their P2 pages, by name; each line only when it has values; both in the accordion and in a tab */
    public function test_the_meta_section_prints_the_season_and_the_tags_as_links(): void
    {
        $this->publish([$this->meta()]);
        $this->accordion();
        $this->tabs([self::META]);

        $html = $this->visit('p1')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<span class="sf-psec-meta-label">Season:</span> <a href="/season/lyato-26">Лято 26</a>'), 'accordion and tab');
        $this->assertSame(2, substr_count($html, '<span class="sf-psec-meta-label">Tags:</span> <a href="/product-tag/drehi-nike">дрехи Nike</a>, <a href="/product-tag/lyato">Лято</a></p>'), 'tags by name (дрехи before Лято)');
        $this->assertStringContainsString('<summary class="sf-psec-title">Meta</summary>', $html);

        // P2 has tags and no season: one line
        $html = $this->visit('p2')->getContent();
        $this->assertStringNotContainsString('Season:', $html);
        $this->assertStringContainsString('<a href="/product-tag/obuvki-nike">обувки Nike</a>', $html);

        // P4 has neither: no section, no tab
        $html = $this->visit('p4')->getContent();
        $this->assertStringNotContainsString('>Meta<', $html);
        $this->assertStringNotContainsString('sf-ptabs', $html);
    }

    /** ★ the two switches */
    public function test_the_meta_switches(): void
    {
        $this->accordion();

        $this->publish([$this->meta(false, true)]);
        $html = $this->visit('p1')->getContent();
        $this->assertStringNotContainsString('Season:', $html);
        $this->assertStringContainsString('Tags:', $html);

        $this->publish([$this->meta(true, false)]);
        $html = $this->visit('p1')->getContent();
        $this->assertStringContainsString('Season:', $html);
        $this->assertStringNotContainsString('Tags:', $html);

        $this->publish([$this->meta(false, false)]);
        $this->assertStringNotContainsString('>Meta<', $this->visit('p1')->getContent(), 'both off: nothing to print');
    }

    /** ★ names are escaped */
    public function test_meta_names_are_escaped(): void
    {
        $this->publish([$this->meta()]);
        $this->accordion();

        $html = $this->visit('p5')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;q&quot;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    /** ★ ONE query for the season and the tags, only when a `meta` section is defined (pin) */
    public function test_the_meta_source_costs_one_query_only_when_used(): void
    {
        $this->accordion();
        $this->publish([['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>']]);
        $this->visit('p1');
        $without = $this->queryCount('p1');

        $this->publish([['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>'], $this->meta()]);
        $this->visit('p1');
        $with = $this->queryCount('p1');

        fwrite(STDERR, "\n[query-count] P4c meta: sections without meta {$without}, with a meta section {$with}\n");
        $this->assertSame($without + 1, $with, 'ONE query reads the season and the tags together');
    }

    // ---- ★ blocks ---------------------------------------------------------------------------------------------------------------------

    /** ★ only the blocks whose conditions match print; the chosen order is kept; a block with no condition prints for all; accordion AND tab */
    public function test_the_blocks_section_prints_the_matching_blocks_in_order(): void
    {
        $this->blocks();
        $this->publish([$this->sizes([$this->block['all'], $this->block['guess'], $this->block['nike-clothes'], $this->block['nike-shoes']])]);
        $this->accordion();
        $this->tabs([self::SIZES]);

        $html = $this->visit('p1')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'NIKECLOTHESSIZES'), 'accordion and tab');
        $this->assertSame(2, substr_count($html, 'FORALLPRODUCTS'));
        $this->assertStringNotContainsString('GUESSSIZES', $html);
        $this->assertStringNotContainsString('NIKESHOESSIZES', $html);
        $this->assertLessThan(strpos($html, 'NIKECLOTHESSIZES'), strpos($html, 'FORALLPRODUCTS'), 'the chosen order');

        $html = $this->visit('p3')->getContent();
        $this->assertStringContainsString('GUESSSIZES', $html, 'a Guess block for a Guess product');
        $this->assertStringNotContainsString('NIKECLOTHESSIZES', $html, 'not the Nike block, even with its tag');
    }

    /** ★ two conditions on one block combine with AND (brand Nike AND the tag): the right size table reaches the right product */
    public function test_two_conditions_on_a_block_combine_with_and(): void
    {
        $this->blocks();
        $this->publish([$this->sizes([$this->block['nike-clothes'], $this->block['nike-shoes']])]);
        $this->accordion();

        $clothes = $this->visit('p1')->getContent();
        $this->assertStringContainsString('NIKECLOTHESSIZES', $clothes);
        $this->assertStringNotContainsString('NIKESHOESSIZES', $clothes);

        $shoes = $this->visit('p2')->getContent();
        $this->assertStringContainsString('NIKESHOESSIZES', $shoes);
        $this->assertStringNotContainsString('NIKECLOTHESSIZES', $shoes);

        $plainNike = $this->visit('p4')->getContent();
        $this->assertStringNotContainsString('SIZES', $plainNike, 'Nike without the tag: neither');
        $this->assertStringNotContainsString('>Sizes<', $plainNike, 'no block matches: no section');

        $guessWithTag = $this->visit('p3')->getContent();
        $this->assertStringNotContainsString('NIKECLOTHESSIZES', $guessWithTag, 'the tag on another brand: not shown');
    }

    /** ★ no block matches: no section and no tab */
    public function test_no_matching_block_prints_nothing(): void
    {
        $this->blocks();
        $this->publish([$this->sizes([$this->block['guess']])]);
        $this->accordion();
        $this->tabs([self::SIZES]);

        $html = $this->visit('p1')->getContent();

        $this->assertStringNotContainsString('>Sizes<', $html);
        $this->assertStringNotContainsString('sf-ptabs', $html);
    }

    /** ★ a deleted block is dropped (compiled copy and read), never a 500; a block used by a section cannot be deleted; editing a block's conditions refreshes the copy */
    public function test_blocks_are_guarded_dropped_when_gone_and_recompiled(): void
    {
        $this->blocks();
        $this->publish([$this->sizes([$this->block['all'], $this->block['guess']])]);
        $this->accordion();

        $this->assertTrue(HtmlBlockUsage::sections($this->block['all']));
        $this->assertFalse(HtmlBlockUsage::sections($this->block['nike-shoes']));
        $this->assertNotNull(HtmlBlockUsage::refusal(HtmlBlockModel::findOrFail($this->block['all'])), 'used: cannot be deleted');

        // editing the conditions refreshes the compiled copy (the save of a block goes through MenuRecompileListeners)
        $guess = HtmlBlockModel::findOrFail($this->block['guess']);
        $guess->conditions = ['brands' => [1]];
        $guess->save();
        app(\App\Storefront\Menu\MenuRecompiler::class)->now();
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('GUESSSIZES', $this->visit('p1')->getContent(), 'the block now matches Nike products: the copy follows');
        $this->assertStringNotContainsString('GUESSSIZES', $this->visit('p3')->getContent());

        // a block that is gone: dropped, the page is fine
        DB::table('html_blocks')->where('id', $this->block['guess'])->delete();
        app(SectionCompiler::class)->refresh();
        $this->app->forgetScopedInstances();
        $html = $this->visit('p1')->assertOk()->getContent();
        $this->assertStringNotContainsString('GUESSSIZES', $html);
        $this->assertStringContainsString('FORALLPRODUCTS', $html);

        // a hand-edited compiled copy cannot bring markup in, and an unknown shape never breaks the page (the whole document is refused by its hash)
        $json = (string) app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY);
        $doc = json_decode($json, true);
        $doc['zones']['sections']['sections'][0]['blocks'] = [['id' => $this->block['all'], 'html' => '<p onclick="x()">EDITED</p><script>alert(1)</script>', 'conditions' => null], 'junk', ['id' => 'x']];
        app(SiteSettingsRepository::class)->set(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($doc));
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
        $this->app->forgetScopedInstances();
        $html = $this->visit('p1')->assertOk()->getContent();
        $this->assertStringNotContainsString('EDITED', $html, 'a document edited by hand does not match its hash: it is not read at all');
        $this->assertDoesNotMatchRegularExpression('/onclick=|<script>alert/', $html);
    }

    /** ★ +0 queries for the blocks themselves: the copy is in the document the page already reads (blocks with no tag condition) (pin) */
    public function test_the_blocks_source_costs_no_query_of_its_own(): void
    {
        $this->accordion();
        $this->publish([['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>']]);
        $this->visit('p1');
        $without = $this->queryCount('p1');

        $this->blocks();
        $this->publish([['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>'], $this->sizes([$this->block['all'], $this->block['guess']])]);
        $this->visit('p1');
        $with = $this->queryCount('p1');

        fwrite(STDERR, "\n[query-count] P4c blocks: sections {$without}, with a blocks section (no condition + a brand condition) {$with}\n");
        $this->assertSame($without, $with, 'blocks and a brand condition: +0');
    }

    /** the settings are validated on save and dropped on read: at most 10 distinct block ids, at least one on save */
    public function test_the_new_sources_are_validated(): void
    {
        foreach ([[], [0], ['a'], [1, 1], range(1, 11)] as $i => $ids) {
            [, $errors] = SectionSet::validate(['sections' => [$this->sizes($ids)]]);
            $this->assertNotSame([], $errors, 'case '.$i);
        }

        [$clean, $errors] = SectionSet::validate(['sections' => [$this->sizes(range(1, 10)), ['key' => self::META, 'title' => 'M', 'source' => 'meta']]]);
        $this->assertSame([], $errors);
        $this->assertSame(['seasons' => true, 'tags' => true], $clean['sections'][1]['meta'], 'both on by default');

        [, $errors] = SectionSet::validate(['sections' => [['key' => self::META, 'title' => 'M', 'source' => 'meta', 'meta' => ['seasons' => 'yes']]]]);
        $this->assertNotSame([], $errors);

        // another source keeps the shape it always had
        [$clean] = SectionSet::validate(['sections' => [['key' => self::DELIV, 'title' => 'D', 'source' => 'global', 'text' => '<p>x</p>', 'meta' => ['seasons' => false], 'blocks' => [1]]]]);
        $this->assertSame(['key', 'title', 'source', 'text'], array_keys($clean['sections'][0]));

        $read = SectionSet::read(['sections' => [$this->sizes([1, 'x']), $this->sizes([3]) + [], ['key' => self::SIZE, 'title' => 'X', 'source' => 'bogus']]]);
        $this->assertSame([self::SIZES], array_column($read['sections'], 'key'), 'a bad block list drops the section; a bad source too');
    }

    // ---- ★ brand condition ------------------------------------------------------------------------------------------------------------

    /** ★ the brand condition: validated (an unknown brand is refused), matches by the product's brand id, dropped when malformed */
    public function test_the_brand_condition(): void
    {
        [$clean, $errors] = ProductConditions::validate(['brands' => [1, 2]]);
        $this->assertSame([], $errors);
        $this->assertSame(['brands' => [1, 2]], $clean);

        [, $errors] = ProductConditions::validate(['brands' => [999]]);
        $this->assertArrayHasKey('brands', $errors, 'an unknown brand is refused on save');

        [, $errors] = ProductConditions::validate(['brands' => 'x']);
        $this->assertArrayHasKey('brands', $errors);

        $this->assertSame(['brands' => [1]], ProductConditions::active(['brands' => [1]]));
        $this->assertFalse(ProductConditions::active(['brands' => ['x']]), 'malformed: never shown');

        $this->assertContains('brands', ProductConditions::KEYS);
        $this->assertSame(['brands' => [1]], ProductConditions::fromForm(['on_sale' => 'all', 'brands' => [1]]));
    }

    /** the brand condition on the other places that show a conditioned block (the right column) costs no query either */
    public function test_a_brand_condition_matches_by_the_brand_of_the_page(): void
    {
        $nike = new \App\Storefront\Conditions\ProductContext(1, [], null, 1);
        $none = new \App\Storefront\Conditions\ProductContext(1, [], null, null);

        $this->assertTrue(ProductConditions::matches($nike, ['brands' => [1, 2]]));
        $this->assertFalse(ProductConditions::matches($nike, ['brands' => [2]]));
        $this->assertFalse(ProductConditions::matches($none, ['brands' => [1]]), 'a product with no brand matches no brand');
        $this->assertTrue(ProductConditions::matches($none, null));
    }

    // ---- ★ the per-product switch -----------------------------------------------------------------------------------------------------

    private function hide(int $product, string $key, bool $hidden = true, ?string $text = null): void
    {
        DB::table('product_section_texts')->updateOrInsert(['product_id' => $product, 'section_key' => $key], ['body' => $text ?? '', 'hidden' => $hidden, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** ★ a section switched off for ONE product is absent there (accordion and tab), present on another, whatever its source; hidden wins over content */
    public function test_a_hidden_section_is_absent_for_that_product_only(): void
    {
        $this->blocks();
        $this->publish([
            ['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>GLOBALDELIVERY</p>'],
            $this->meta(),
            $this->sizes([$this->block['all']]),
        ]);
        $this->accordion();
        $this->tabs([self::DELIV, self::META, self::SIZES]);
        $this->hide(1, self::DELIV);
        $this->hide(1, self::META);
        $this->hide(1, self::SIZES);

        $one = $this->visit('p1')->assertOk()->getContent();
        $this->assertStringNotContainsString('GLOBALDELIVERY', $one);
        $this->assertStringNotContainsString('>Meta<', $one);
        $this->assertStringNotContainsString('FORALLPRODUCTS', $one);
        $this->assertStringNotContainsString('sf-ptabs', $one, 'every tab hidden: no slot');

        $two = $this->visit('p2')->getContent();
        $this->assertStringContainsString('GLOBALDELIVERY', $two);
        $this->assertStringContainsString('FORALLPRODUCTS', $two);
        $this->assertSame(2, substr_count($two, 'GLOBALDELIVERY'), 'accordion and tab');
    }

    /** ★ empty content hides a section even when the switch is on; a flag for a removed section is ignored */
    public function test_empty_content_hides_and_a_flag_of_a_removed_section_is_ignored(): void
    {
        $this->publish([['key' => self::SIZE, 'title' => 'Size', 'source' => 'product']]);
        $this->accordion();

        $this->assertStringNotContainsString('>Size<', $this->visit('p1')->getContent(), 'switch on, no text: not printed');

        DB::table('product_section_texts')->insert(['product_id' => 1, 'section_key' => self::SIZE, 'body' => '<p>SIZETEXT</p>', 'hidden' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertStringNotContainsString('SIZETEXT', $this->visit('p1')->getContent(), 'hidden wins over a text');

        DB::table('product_section_texts')->where('product_id', 1)->update(['hidden' => false]);
        $this->assertStringContainsString('SIZETEXT', $this->visit('p1')->getContent(), 'switched back on: the text is kept and shown');

        $this->hide(1, self::DELIV);
        $this->assertStringContainsString('SIZETEXT', $this->visit('p1')->getContent(), 'a flag for a section that is not defined is ignored');
    }

    /** ★ the switch is stored only when it differs from the default; the text is kept while it is off; the log has one line per change */
    public function test_the_switch_is_saved_as_a_flag_beside_the_text(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        $id = 1;
        $rows = fn (): array => DB::table('product_section_texts')->where('product_id', $id)->get(['section_key', 'body', 'hidden'])->map(fn ($r): array => [$r->section_key, $r->body, (bool) $r->hidden])->all();

        SectionTexts::save($id, [], [], [self::DELIV => true], [self::DELIV]);
        $this->assertSame([], $rows(), 'switch ON and no text: no row');

        SectionTexts::save($id, [], [], [self::DELIV => false], [self::DELIV]);
        $this->assertSame([[self::DELIV, '', true]], $rows(), 'OFF, no text: a row with an empty body');

        SectionTexts::save($id, [self::DELIV => '<p>T</p>'], [self::DELIV], [self::DELIV => false], [self::DELIV]);
        $this->assertSame([[self::DELIV, '<p>T</p>', true]], $rows(), 'hidden and a text coexist');

        SectionTexts::save($id, [self::DELIV => '<p>T</p>'], [self::DELIV], [self::DELIV => true], [self::DELIV]);
        $this->assertSame([[self::DELIV, '<p>T</p>', false]], $rows(), 'back ON keeps the text');

        SectionTexts::save($id, [self::DELIV => ''], [self::DELIV], [self::DELIV => true], [self::DELIV]);
        $this->assertSame([], $rows(), 'ON and no text: the row is gone');

        $this->assertSame(1, DB::table('activity_log')->where('entity_type', 'product')->where('field', 'section_hidden:'.self::DELIV)->where('new_value', '1')->count(), 'one log line for switching off');
    }

    /** the texts and the switches of a product go with the product */
    public function test_the_switch_rows_are_deleted_with_the_product(): void
    {
        $this->hide(2, self::DELIV);
        $this->assertSame(1, DB::table('product_section_texts')->where('product_id', 2)->count());

        DB::table('catalog_product_categories')->where('product_id', 2)->delete();
        DB::table('stock_levels')->whereIn('variation_id', DB::table('catalog_variations')->where('product_id', 2)->pluck('id'))->delete();
        DB::table('catalog_variations')->where('product_id', 2)->delete();
        DB::table('catalog_product_tags')->where('product_id', 2)->delete();
        DB::table('catalog_products')->where('id', 2)->delete();

        $this->assertSame(0, DB::table('product_section_texts')->where('product_id', 2)->count());
    }
    /** a change of a block that a `blocks` section shows asks for the recompile (the same listener the other places use); a shop with no such section is not bothered */
    public function test_a_block_change_asks_for_the_recompile_only_when_a_section_shows_blocks(): void
    {
        $recompiler = app(\App\Storefront\Menu\MenuRecompiler::class);

        $recompiler->forgetHolds();
        $recompiler->request('html');
        $this->assertFalse($recompiler->hasPending(), 'no place shows a block');

        $this->blocks();
        $this->publish([$this->sizes([$this->block['all']])]);
        $recompiler->forgetHolds();
        $recompiler->request('html');
        $this->assertTrue($recompiler->hasPending(), 'a section shows a block');
    }
}