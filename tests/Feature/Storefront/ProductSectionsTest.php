<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionCompiler;
use App\Storefront\Sections\SectionResolver;
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
 * P4b: the sections of the product page: the definitions («Дизайн» → «Секции на продукта»), the texts of the products, the «Акордеон» component of the right column and the «Табове» slot below the main area.
 *
 * The world: simple products P1 and P2 (P3 is a variable one) in «Dresses». Sections (fixed keys): `DELIV` «Delivery» (global text), `SIZE` «Size» (the product's text), `COMP` «Composition» (the product's text, else
 * the general one). P1 has its own texts for SIZE and COMP; P2 has none.
 */
class ProductSectionsTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const DELIV = '11111111-1111-4111-8111-111111111111';

    private const SIZE = '22222222-2222-4222-8222-222222222222';

    private const COMP = '33333333-3333-4333-8333-333333333333';

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
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(2, ['name' => 'P2', 'slug' => 'p2', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(3, ['type' => 'variable', 'name' => 'P3', 'slug' => 'p3', 'categories' => [1], 'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]]]);
    }

    private function visit(string $slug): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug);
    }

    /** @return list<array<string, string>> */
    private function defs(): array
    {
        return [
            ['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>GLOBALDELIVERY</p>'],
            ['key' => self::SIZE, 'title' => 'Size', 'source' => 'product', 'text' => ''],
            ['key' => self::COMP, 'title' => 'Composition', 'source' => 'product_or_global', 'text' => '<p>GLOBALCOMPOSITION</p>'],
        ];
    }

    /** publishes the definitions through the real validator and store */
    private function publishSections(?array $sections = null): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => $sections ?? $this->defs()]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** the right column with the accordion under the purchase block */
    private function publishAccordion(bool $firstOpen = false): void
    {
        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => $firstOpen]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    private function texts(int $product, array $texts): void
    {
        SectionTexts::save($product, $texts, array_keys($texts));
    }

    private function publishTabs(array $slots): void
    {
        [$clean, $errors] = BelowSlots::validate(['slots' => $slots]);
        $this->assertSame([], $errors, json_encode($errors));
        BelowStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private function tabs(array $keys, string $heading = ''): array
    {
        return ['type' => 'tabs', 'heading' => $heading, 'sections' => $keys];
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

    /** the titles of the accordion's sections, in order @return list<string> */
    private function accordionTitles(string $html): array
    {
        preg_match_all('#<summary class="sf-psec-title">(.*?)</summary>#s', $html, $m);

        return array_map(static fn (string $title): string => html_entity_decode($title, ENT_QUOTES | ENT_HTML5), $m[1]);
    }

    // ---- ★ nothing defined = today's page --------------------------------------------------------------------------------------------------

    /** ★ with no section the product page is the page it always was: no section markup, a published empty set changes nothing, the same queries (simple AND variable product) */
    public function test_with_no_section_the_product_page_is_the_page_it_always_was(): void
    {
        $urls = ['p1', 'p2', 'p3'];
        $before = [];
        $counts = [];

        foreach ($urls as $slug) {
            $before[$slug] = $this->visit($slug)->assertOk()->getContent();
            $this->assertStringNotContainsString('sf-psec', $before[$slug]);
            $this->assertStringNotContainsString('sf-ptab', $before[$slug]);
            $counts[$slug] = $this->queryCount($slug);
        }

        $this->publishSections([]);

        foreach ($urls as $slug) {
            $this->assertSame($before[$slug], $this->visit($slug)->getContent(), 'an empty published set: '.$slug);
            $this->assertSame($counts[$slug], $this->queryCount($slug), 'the same queries: '.$slug);
        }

        // sections defined but nothing of the page uses them (no accordion, no tabs): the page is the same, and no query is made for the texts
        $this->publishSections();
        foreach ($urls as $slug) {
            $this->assertSame($before[$slug], $this->visit($slug)->getContent(), 'sections defined, not used: '.$slug);
            $this->assertSame($counts[$slug], $this->queryCount($slug), 'sections defined, not used: no query: '.$slug);
        }

        fwrite(STDERR, "\n[query-count] product pages without a section: ".json_encode($counts)."\n");
    }

    // ---- ★ resolution ------------------------------------------------------------------------------------------------------------------

    /** ★ global / product / product_or_global each give the right text; an empty product text falls back only for product_or_global */
    public function test_each_source_resolves_to_the_right_text(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->texts(1, [self::SIZE => '<p>P1SIZETEXT</p>', self::COMP => '<p>P1COMPTEXT</p>']);

        $one = $this->visit('p1')->assertOk()->getContent();
        $this->assertSame(['Delivery', 'Size', 'Composition'], $this->accordionTitles($one), 'the definitions\' order');
        $this->assertStringContainsString('GLOBALDELIVERY', $one);
        $this->assertStringContainsString('P1SIZETEXT', $one);
        $this->assertStringContainsString('P1COMPTEXT', $one);
        $this->assertStringNotContainsString('GLOBALCOMPOSITION', $one, 'the product has its own composition: no fallback');

        // P2 has no text: «Size» (product) is empty and not printed; «Composition» falls back to the general text
        $two = $this->visit('p2')->getContent();
        $this->assertSame(['Delivery', 'Composition'], $this->accordionTitles($two));
        $this->assertStringContainsString('GLOBALCOMPOSITION', $two);
        $this->assertStringNotContainsString('P1SIZETEXT', $two);

        // the editor's empty value is no text: the fallback applies to `product_or_global` only, `product` stays hidden
        DB::table('product_section_texts')->insert(['product_id' => 2, 'section_key' => self::SIZE, 'body' => '<p></p>', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_section_texts')->insert(['product_id' => 2, 'section_key' => self::COMP, 'body' => "<p>&nbsp;</p>\n", 'created_at' => now(), 'updated_at' => now()]);
        $two = $this->visit('p2')->getContent();
        $this->assertSame(['Delivery', 'Composition'], $this->accordionTitles($two));
        $this->assertStringContainsString('GLOBALCOMPOSITION', $two);

        // the pure resolver says the same
        $definitions = SectionCompiler::published((string) app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY));
        $this->assertSame(['Delivery', 'Composition'], array_column(SectionResolver::resolve($definitions, []), 'title'), 'no product texts: the general ones');
        $this->assertSame(['Delivery', 'Size', 'Composition'], array_column(SectionResolver::resolve($definitions, [self::SIZE => '<p>x</p>']), 'title'));
    }

    // ---- ★ the accordion ---------------------------------------------------------------------------------------------------------------

    /** ★ independent <details> (no `name`), closed by default, the first open only with the setting, no script, no inline style */
    public function test_the_accordion_is_independent_closed_and_script_free(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->texts(1, [self::SIZE => '<p>S</p>', self::COMP => '<p>C</p>']);

        $html = $this->visit('p1')->assertOk()->getContent();
        $accordion = (string) strstr($html, '<div class="sf-psec-list">');

        $this->assertSame(3, substr_count($accordion, '<details class="sf-psec">'), 'closed by default');
        $this->assertStringNotContainsString(' open', $accordion);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sname=/', $accordion, 'independent: no exclusive group');
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|\sonclick=|<script/i', substr($accordion, 0, (int) strpos($accordion, '</section>')));

        // under the purchase block (the right column's order)
        $this->assertTrue(strpos($html, 'sf-add-to-cart') < strpos($html, 'sf-psec-list'));

        $this->publishAccordion(true);
        $open = $this->visit('p1')->getContent();
        $this->assertSame(1, substr_count($open, '<details class="sf-psec" open>'), 'only the first one');
        $this->assertTrue(strpos($open, '<details class="sf-psec" open>') < strpos($open, 'GLOBALDELIVERY'));
    }

    /** ★ no text at all: the component prints nothing (no list, no title, no box); a section without a text is not printed */
    public function test_a_page_with_no_section_text_prints_no_accordion_markup(): void
    {
        $this->publishSections([['key' => self::SIZE, 'title' => 'Size', 'source' => 'product', 'text' => '']]);
        $this->publishAccordion();

        $html = $this->visit('p2')->assertOk()->getContent();
        $this->assertStringNotContainsString('sf-psec', $html);
        $this->assertStringNotContainsString('Size</summary>', $html);

        $this->texts(1, [self::SIZE => '<p>ONLYP1</p>']);
        $this->assertStringContainsString('ONLYP1', $this->visit('p1')->getContent());
        $this->assertStringNotContainsString('sf-psec', $this->visit('p2')->getContent());
    }

    // ---- ★ the tabs --------------------------------------------------------------------------------------------------------------------

    /** ★ only the chosen sections that have a text, the first printed tab open, unique ids for two tab slots, keyboard-focusable radios, no script / inline style */
    public function test_the_tabs_slot_prints_the_chosen_sections_with_unique_ids(): void
    {
        $this->publishSections();
        $this->texts(1, [self::SIZE => '<p>P1SIZETEXT</p>']);
        $this->publishTabs([$this->tabs([self::SIZE, self::DELIV], 'TABSHEADING'), $this->tabs([self::DELIV, self::COMP])]);

        $html = $this->visit('p1')->assertOk()->getContent();
        $below = (string) strstr($html, '<div class="sf-below">');

        $this->assertSame(2, substr_count($below, 'sf-hp-section sf-ptabs"'), 'two tab slots');
        $this->assertSame(['sf-ptab-1-1', 'sf-ptab-1-2', 'sf-ptab-2-1', 'sf-ptab-2-2'], $this->radioIds($below), 'the ids carry the slot number');
        $this->assertSame(4, substr_count($below, 'type="radio"'));
        $this->assertSame(2, substr_count($below, ' checked>'), 'the first printed tab of each slot is open');
        $this->assertMatchesRegularExpression('#<input class="sf-ptab-input" type="radio" name="sf-ptabs-1" id="sf-ptab-1-1" checked>#', $below);
        $this->assertStringContainsString('<label class="sf-ptab-label" for="sf-ptab-1-1">Delivery</label>', $below, 'the sections\' order: Delivery before Size');
        $this->assertStringContainsString('TABSHEADING', $below);

        // slot 2: «Delivery» and «Composition» (P1 has no composition text and the general one is the fallback): both are there; no «Size»
        $slotTwo = (string) substr($below, (int) strpos($below, 'sf-ptabs-2'));
        $this->assertStringContainsString('GLOBALCOMPOSITION', $slotTwo);
        $this->assertStringNotContainsString('P1SIZETEXT', $slotTwo);

        $this->assertDoesNotMatchRegularExpression('/\sstyle=|\sonclick=|<script/i', $below);
    }

    /** ★ a tab with no text is not printed; no tab left = no slot, no heading; a section that is gone from the definitions is dropped */
    public function test_empty_tabs_are_hidden_and_a_slot_with_no_tab_prints_nothing(): void
    {
        $this->publishSections();
        $this->publishTabs([$this->tabs([self::SIZE], 'SIZEHEADING'), $this->tabs([self::DELIV, self::SIZE], 'MIXEDHEADING')]);

        // P2: no «Size» text: the first slot has no tab left (nothing, no heading); the second has «Delivery» only
        $two = $this->visit('p2')->assertOk()->getContent();
        $this->assertStringNotContainsString('SIZEHEADING', $two);
        $this->assertStringContainsString('MIXEDHEADING', $two);
        $this->assertSame(1, substr_count($two, 'class="sf-ptab-label"'));
        $this->assertStringNotContainsString('>Size</label>', $two);

        // a section that is removed from the definitions: its key in the slot is dropped, never a 500
        $this->publishSections([$this->defs()[0]]);
        $this->texts(1, [self::SIZE => '<p>REMOVEDTEXT</p>']);
        $one = $this->visit('p1')->assertOk()->getContent();
        $this->assertStringNotContainsString('SIZEHEADING', $one);
        $this->assertStringNotContainsString('REMOVEDTEXT', $one);
        $this->assertSame(1, substr_count($one, 'class="sf-ptab-label"'));
    }

    /** the slot's shape: 1 to 8 section keys, validated on save, dropped on read */
    public function test_a_tabs_slot_is_validated(): void
    {
        foreach ([[], ['not-a-key'], [self::SIZE, self::SIZE], array_fill(0, 9, self::SIZE)] as $bad) {
            [, $errors] = BelowSlots::validate(['slots' => [$this->tabs($bad)]]);
            $this->assertArrayHasKey('slots.0.sections', $errors, json_encode($bad));
            $this->assertSame([], BelowSlots::read(['slots' => [$this->tabs($bad)]])['slots'], 'dropped on read');
        }

        [$clean, $errors] = BelowSlots::validate(['slots' => [$this->tabs([self::SIZE, self::COMP], 'H')]]);
        $this->assertSame([], $errors);
        $this->assertSame([self::SIZE, self::COMP], $clean['slots'][0]['sections']);
    }

    // ---- ★ queries ---------------------------------------------------------------------------------------------------------------------

    /** ★ only global sections: +0 queries; a section that takes a product text: +1 (pinned); the definitions cost nothing */
    public function test_the_product_texts_cost_one_query_and_only_when_asked_for(): void
    {
        $this->visit('p1');
        $plain = $this->queryCount('p1');

        $this->publishAccordion();
        $this->publishSections([$this->defs()[0]]);
        $global = $this->queryCount('p1');
        $this->assertSame($plain, $global, 'only global sections: the definitions are in the compiled document, no query');

        $this->publishSections();
        $this->texts(1, [self::SIZE => '<p>S</p>']);
        $withProduct = $this->queryCount('p1');

        fwrite(STDERR, "\n[query-count] product page: plain {$plain}, with global sections {$global}, with a product-text section {$withProduct}\n");

        $this->assertSame($plain + 1, $withProduct, 'ONE query for the product\'s texts');

        // the tabs ask for the texts the same way: one query
        $this->publishTabs([$this->tabs([self::SIZE])]);
        $this->publishAccordion();
        $this->app->forgetScopedInstances();
    }

    // ---- ★ security --------------------------------------------------------------------------------------------------------------------

    /** ★ a stored text is neutralised on output exactly as the description is; a title with markup is escaped (accordion and tab label) */
    public function test_texts_and_titles_are_neutralised(): void
    {
        $this->publishSections([
            ['key' => self::SIZE, 'title' => '<script>alert(1)</script> "Q" & \'s\'', 'source' => 'product', 'text' => ''],
            ['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p style="color:red" onclick="x()">GLOBALOK</p><script>alert(2)</script><a href="javascript:alert(3)">bad</a>'],
        ]);
        $this->publishAccordion();
        $this->publishTabs([$this->tabs([self::SIZE, self::DELIV])]);
        $this->texts(1, [self::SIZE => '<p>OWNOK</p><img src=x onerror=alert(4)><iframe src="//evil"></iframe><p style="x:y">styled</p>']);

        $html = $this->visit('p1')->assertOk()->getContent();
        $sections = (string) strstr($html, '<div class="sf-psec-list">');

        foreach (['<script>alert', 'onclick=', 'onerror=', 'javascript:', '<iframe', '<img src=x'] as $needle) {
            $this->assertStringNotContainsString($needle, $sections, $needle);
            $this->assertStringNotContainsString($needle, (string) strstr($html, '<div class="sf-below">'), $needle);
        }

        $this->assertStringContainsString('GLOBALOK', $sections);
        $this->assertStringContainsString('OWNOK', $sections);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot; &amp; &#039;s&#039;', $sections);
        $this->assertStringContainsString('for="sf-ptab-1-1">&lt;script&gt;alert(1)', $html);
        $this->assertDoesNotMatchRegularExpression('/<p style=/', $sections);
    }

    // ---- ★ stable keys, texts, cascade ------------------------------------------------------------------------------------------------

    /** ★ renaming a section keeps the texts (the key is stable); removing it hides the text and keeps the row */
    public function test_a_renamed_section_keeps_its_texts_and_a_removed_one_hides_them(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->texts(1, [self::SIZE => '<p>P1SIZETEXT</p>']);

        $renamed = $this->defs();
        $renamed[1]['title'] = 'Individual size';
        $this->publishSections([$renamed[1], $renamed[0], $renamed[2]]);

        $html = $this->visit('p1')->getContent();
        $this->assertSame(['Individual size', 'Delivery', 'Composition'], $this->accordionTitles($html), 'renamed and moved');
        $this->assertStringContainsString('P1SIZETEXT', $html);

        $this->publishSections([$renamed[0], $renamed[2]]);
        $this->assertStringNotContainsString('P1SIZETEXT', $this->visit('p1')->getContent());
        $this->assertSame(1, DB::table('product_section_texts')->where('product_id', 1)->where('section_key', self::SIZE)->count(), 'the text stays in the database');
    }

    /** ★ the texts: saved, changed, cleared (the row is deleted), a key that is not a section key is ignored, the product's delete cascades */
    public function test_the_texts_are_saved_cleared_and_deleted_with_the_product(): void
    {
        $this->texts(1, [self::SIZE => '<p>FIRST</p>']);
        $this->assertSame([self::SIZE => '<p>FIRST</p>'], SectionTexts::forProduct(1));

        $this->texts(1, [self::SIZE => '<p>SECOND</p>']);
        $this->assertSame(1, DB::table('product_section_texts')->where('product_id', 1)->count());
        $this->assertSame('<p>SECOND</p>', SectionTexts::forProduct(1)[self::SIZE]);

        // only the keys asked for are touched
        $this->texts(1, [self::COMP => '<p>COMPTEXT</p>']);
        $this->assertSame(2, DB::table('product_section_texts')->where('product_id', 1)->count());

        // cleared: the editor's empty value, or nothing: the row is deleted
        $this->texts(1, [self::SIZE => '<p></p>']);
        $this->assertArrayNotHasKey(self::SIZE, SectionTexts::forProduct(1));
        SectionTexts::save(1, ['not-a-key' => 'x'], ['not-a-key']);
        $this->assertSame(1, DB::table('product_section_texts')->where('product_id', 1)->count());

        // one row per (product, section): the database refuses a second one
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('product_section_texts')->insert(['product_id' => 1, 'section_key' => self::COMP, 'body' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_deleting_the_product_deletes_its_texts(): void
    {
        $this->texts(2, [self::SIZE => '<p>GONE</p>']);
        $variations = DB::table('catalog_variations')->where('product_id', 2)->pluck('id');
        DB::table('stock_levels')->whereIn('variation_id', $variations)->delete();
        DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $variations)->delete();
        DB::table('catalog_variations')->where('product_id', 2)->delete();
        DB::table('catalog_product_categories')->where('product_id', 2)->delete();
        DB::table('catalog_products')->where('id', 2)->delete();

        $this->assertSame(0, DB::table('product_section_texts')->where('product_id', 2)->count());
    }

    // ---- the definitions: validation and the compiled copy -----------------------------------------------------------------------------

    /** a missing key is generated on save; at most 8; the title 1-60; a bad source and a duplicate key are refused; read drops what does not validate */
    public function test_the_definitions_are_validated_on_save_and_cleaned_on_read(): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => [['title' => ' Delivery ', 'source' => 'global', 'text' => '<p>x</p>']]]);
        $this->assertSame([], $errors);
        $this->assertTrue(SectionSet::isKey($clean['sections'][0]['key']), 'a key is generated');
        $this->assertSame('Delivery', $clean['sections'][0]['title']);

        $nine = array_fill(0, 9, ['title' => 'T', 'source' => 'global', 'text' => '']);
        [$clean, $errors] = SectionSet::validate(['sections' => $nine]);
        $this->assertArrayHasKey('sections', $errors);
        $this->assertCount(8, $clean['sections']);

        foreach ([['title' => '', 'source' => 'global'], ['title' => str_repeat('я', 61), 'source' => 'global'], ['title' => 'T', 'source' => 'nope'], ['title' => "a\u{0007}b", 'source' => 'global'], ['title' => 'T', 'source' => 'global', 'x' => 1]] as $i => $bad) {
            [, $errors] = SectionSet::validate(['sections' => [$bad]]);
            $this->assertNotSame([], $errors, 'case '.$i);
        }

        [, $errors] = SectionSet::validate(['sections' => [['key' => self::SIZE, 'title' => 'A', 'source' => 'product'], ['key' => self::SIZE, 'title' => 'B', 'source' => 'product']]]);
        $this->assertArrayHasKey('sections.1', $errors);

        // the general text means something only for a source that uses it
        [$clean] = SectionSet::validate(['sections' => [['key' => self::SIZE, 'title' => 'A', 'source' => 'product', 'text' => '<p>ignored</p>']]]);
        $this->assertSame('', $clean['sections'][0]['text']);

        $read = SectionSet::read(['sections' => ['x', ['title' => 'no key', 'source' => 'global'], ['key' => self::SIZE, 'title' => 'OK', 'source' => 'product'], ['key' => self::COMP, 'title' => 'Bad', 'source' => '<script>'], ['key' => self::SIZE, 'title' => 'Dup', 'source' => 'product']]]);
        $this->assertSame([self::SIZE], array_column($read['sections'], 'key'));
    }

    /** the compiled copy keeps cleaned HTML, is read again (a hand-edited copy cannot bring markup in) and is gone when nothing is published */
    public function test_the_compiled_copy_is_cleaned_and_removed_with_the_set(): void
    {
        $this->publishSections([['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>OK</p><script>alert(1)</script>']]);

        $json = (string) app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY);
        $this->assertStringNotContainsString('alert(1)', $json, 'cleaned when compiled');

        $doc = json_decode($json, true);
        $doc['zones']['sections']['sections'][0]['html'] = '<p>EVIL</p><script>alert(9)</script>';
        $doc['zones']['sections']['sections'][1] = ['key' => 'not-a-key', 'title' => 'x', 'source' => 'global', 'html' => 'y'];
        $read = SectionCompiler::published((string) json_encode($doc));
        $this->assertSame(1, count($read));
        $this->assertSame('<p>EVIL</p>', $read[0]['html'], 'cleaned again on read; a bad section dropped');

        $this->publishSections([]);
        $this->assertNull(SectionCompiler::published((string) app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY)));
        $this->assertNull(app(SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::SECTIONS_PUBLISHED_KEY));
    }

    /** @return list<string> the radio ids of a piece of html, in order */
    private function radioIds(string $html): array
    {
        preg_match_all('/<input class="sf-ptab-input" type="radio" name="[^"]*" id="([^"]*)"/', $html, $m);

        return $m[1];
    }
}
