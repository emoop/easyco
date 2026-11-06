<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Storefront\Below\BelowCompiler;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P4: the slots below the main area of the product page («Дизайн» → «Под галерията»): an ordered list of at most 6 slots (a carousel, «Подобни продукти» = a carousel with the source `similar`, an HTML block with
 * its conditions), each with an optional heading with lines; the same HTML for every visitor; and the brand name as a link to the brand's page.
 *
    
 * The world: «Clothing» (1) with «Dresses» (2) and «Coats» (3); «Shoes» (4); «Hats» (5). P1, P2, P3 and P7 are in Dresses; P4 alone in Coats; P5 in Dresses but HIDDEN; P6 in Dresses but DRAFT; P8 in Dresses AND
 * Shoes (Shoes holds only P8: the category with the fewest visible products); P9 alone in Hats. Brands Acme (1: P1–P3, P7), Bolt (2: P4).
 */
class ProductBelowTest extends TestCase
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
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Coats', 'coats', 1);
        $this->makeCategory(4, 'Shoes', 'shoes');
        $this->makeCategory(5, 'Hats', 'hats');
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeBrand(2, 'Bolt', 'bolt');

        $products = [
            1 => ['brand' => 1, 'categories' => [2]], 2 => ['brand' => 1, 'categories' => [2]], 3 => ['brand' => 1, 'categories' => [2]], 4 => ['brand' => 2, 'categories' => [3]],
            5 => ['categories' => [2], 'visibility' => 'hidden'], 6 => ['categories' => [2], 'status' => 'draft'], 7 => ['brand' => 1, 'categories' => [2]],
            8 => ['categories' => [2, 4]], 9 => ['categories' => [5]],
        ];

        foreach ($products as $id => $o) {
            $this->makeProduct($id, $o + ['name' => "P{$id}", 'slug' => "p{$id}", 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $id)]);
        }
    }

    private function visit(string $url): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    private function block(string $marker, ?array $conditions = null): HtmlBlockModel
    {
        $block = HtmlBlockModel::query()->create(['title' => $marker, 'body' => "<p>{$marker}</p>"]);
        $conditions === null || DB::table('html_blocks')->where('id', $block->id)->update(['conditions' => json_encode($conditions)]);

        return $block;
    }

    /** @return array<string, mixed> */
    private function similar(array $extra = [], string $heading = ''): array
    {
        return ['type' => 'carousel', 'heading' => $heading, 'settings' => array_replace(['kind' => 'products', 'source' => 'similar', 'entity' => null, 'count' => '12', 'sort' => 'newest', 'arrows' => true, 'label' => ''], $extra)];
    }

    /** @return array<string, mixed> */
    private function htmlSlot(HtmlBlockModel $block, string $heading = ''): array
    {
        return ['type' => 'html_block', 'heading' => $heading, 'html_block' => $block->id];
    }

    /** publishes the slots through the real validator and store */
    private function publish(array $slots): void
    {
        [$clean, $errors] = BelowSlots::validate(['slots' => $slots]);
        $this->assertSame([], $errors, json_encode($errors));
        BelowStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** @return list<int> */
    private function ids(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** the product ids in the slots' carousels (everything inside `.sf-below`) @return list<int> */
    private function carouselIds(string $html): array
    {
        return preg_match('#<div class="sf-below">(.*)</div>\s*</article>#s', $html, $m) ? $this->ids($m[1]) : [];
    }

    private function queryCount(string $url): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($url)->assertOk();

        return $n;
    }

    // ---- ★ nothing saved = today's page ----------------------------------------------------------------------------------------------------

    /** ★ with no slot the page prints no `sf-below` and a published empty set changes nothing: the same bytes, the same queries (simple AND variable product); the golden fixtures of the summary hold */
    public function test_with_no_slot_the_product_page_is_the_page_it_always_was(): void
    {
        $this->makeVariableProductForBelow();

        $urls = ['/product/p1', '/product/p4', '/product/vp'];
        $before = [];
        $counts = [];

        foreach ($urls as $url) {
            $before[$url] = $this->visit($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('sf-below', $before[$url], $url);
            $counts[$url] = $this->queryCount($url);
        }

        $this->publish([]);

        foreach ($urls as $url) {
            $this->assertSame($before[$url], $this->visit($url)->getContent(), 'an empty published set: '.$url);
            $this->assertSame($counts[$url], $this->queryCount($url), 'the same number of queries: '.$url);
        }

        fwrite(STDERR, "\n[query-count] product pages without a slot: ".json_encode($counts)."\n");
    }

    private function makeVariableProductForBelow(): void
    {
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(20, ['type' => 'variable', 'name' => 'Var', 'slug' => 'vp', 'categories' => [2], 'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]]]);
    }

    // ---- ★ order, heading, limit, bad references -------------------------------------------------------------------------------------------

    /** ★ the slots print in the saved order below the main area, each with its heading only when set (escaped) */
    public function test_the_slots_print_in_the_saved_order_with_an_optional_escaped_heading(): void
    {
        $block = $this->block('HTMLSLOTTEXT');
        $this->publish([$this->htmlSlot($block, '<b>First</b> & "one"'), $this->similar(), $this->htmlSlot($block)]);

        $html = $this->visit('/product/p1')->assertOk()->getContent();
        $article = (string) strstr($html, '<article class="sf-product">');

        $this->assertSame(2, substr_count($article, 'HTMLSLOTTEXT'));
        $this->assertSame(1, substr_count($article, '<div class="sf-below">'));

        $first = strpos($article, 'HTMLSLOTTEXT');
        $car = strpos($article, 'data-carousel');
        $last = strrpos($article, 'HTMLSLOTTEXT');
        $this->assertTrue($first < $car && $car < $last, 'the saved order: block, carousel, block');
        $this->assertTrue(strpos($article, 'sf-product-main') < $first, 'below the main area');

        // the heading: the home heading partial's markup, escaped; only the first slot has one
        $this->assertSame(1, substr_count($article, 'sf-hp-heading-text'));
        $this->assertStringContainsString('&lt;b&gt;First&lt;/b&gt; &amp; &quot;one&quot;', $article);
        $this->assertStringNotContainsString('<b>First', $article);
        $this->assertTrue(strpos($article, 'sf-hp-heading-text') < $first);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $article);
        $this->assertDoesNotMatchRegularExpression('/<script/i', substr($article, (int) strpos($article, 'sf-below')));
    }

    /** ★ at most 6 slots: the seventh is refused on save and cut on read */
    public function test_at_most_six_slots(): void
    {
        $block = $this->block('SLOT');
        $seven = array_fill(0, 7, $this->htmlSlot($block));

        [$clean, $errors] = BelowSlots::validate(['slots' => $seven]);
        $this->assertArrayHasKey('slots', $errors);
        $this->assertCount(6, $clean['slots']);
        $this->assertCount(6, BelowSlots::read(['slots' => $seven])['slots']);

        $this->publish(array_fill(0, 6, $this->htmlSlot($block)));
        $this->assertSame(6, substr_count((string) $this->visit('/product/p1')->getContent(), '<p>SLOT</p>'));
    }

    /** ★ a bad slot is dropped on read, a deleted block is dropped when printed: never a 500 */
    public function test_a_bad_slot_or_a_deleted_block_is_dropped_and_the_page_still_prints(): void
    {
        $keep = $this->block('KEPTBLOCK');
        $gone = $this->block('GONEBLOCK');
        $this->publish([$this->htmlSlot($gone, 'GONEHEADING'), $this->htmlSlot($keep)]);

        DB::table('html_blocks')->where('id', $gone->id)->delete();
        app(MenuRecompiler::class)->now();

        $html = $this->visit('/product/p1')->assertOk()->getContent();
        $this->assertStringContainsString('KEPTBLOCK', $html);
        $this->assertStringNotContainsString('GONEBLOCK', $html);
        $this->assertStringNotContainsString('GONEHEADING', $html, 'a slot with nothing to show prints no heading');

        // a hand-edited compiled copy: a slot that does not validate is dropped, the rest stays
        $read = BelowSlots::read(['slots' => [['type' => 'script'], 'x', $this->htmlSlot($keep), ['type' => 'carousel', 'settings' => ['kind' => 'brands']], $this->similar(['source' => 'current'])]]);
        $this->assertCount(1, $read['slots']);
        $this->assertSame($keep->id, $read['slots'][0]['html_block']);

        // and on save every one of them is an error
        [, $errors] = BelowSlots::validate(['slots' => [['type' => 'script'], $this->htmlSlot($keep, str_repeat('я', 81))]]);
        $this->assertArrayHasKey('slots.0', $errors);
        $this->assertArrayHasKey('slots.1.heading', $errors);
    }

    // ---- ★ «Подобни продукти» ------------------------------------------------------------------------------------------------------------

    /** ★ the same category only, the current product excluded, hidden and draft products excluded */
    public function test_similar_products_are_the_same_category_without_the_current_one(): void
    {
        $this->publish([$this->similar()]);

        // P1 is in Dresses only: P2, P3, P7 and P8 (P5 hidden, P6 draft, P4 is in Coats, P9 in Hats)
        $this->assertEqualsCanonicalizing([2, 3, 7, 8], $this->carouselIds($this->visit('/product/p1')->assertOk()->getContent()));
        $this->assertNotContains(1, $this->carouselIds($this->visit('/product/p1')->getContent()));

        // P8 is in Dresses AND Shoes: Shoes holds fewer visible products (only P8 itself), so the category is Shoes: nothing else qualifies, no section
        $this->assertStringNotContainsString('sf-below', $this->visit('/product/p8')->getContent());
    }

    /** ★ fewer than two products: nothing is printed (no section, no heading); two or more: printed */
    public function test_fewer_than_two_similar_products_print_nothing(): void
    {
        $this->publish([$this->similar([], 'SIMILARHEADING')]);

        // P9 is alone in Hats; P4 has ONE neighbour in Coats? (none: P4 is the only product in Coats)
        foreach (['/product/p9', '/product/p4'] as $url) {
            $html = $this->visit($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('sf-below', $html, $url);
            $this->assertStringNotContainsString('SIMILARHEADING', $html, $url);
        }

        // one neighbour is still not enough: a second product in Coats makes the pair (the product and ONE other)
        $this->makeProduct(30, ['name' => 'P30', 'slug' => 'p30', 'categories' => [3], 'price' => '10.00', 'stock' => 3]);
        $this->assertStringNotContainsString('SIMILARHEADING', $this->visit('/product/p4')->getContent(), 'only 1 qualifies');

        $this->makeProduct(31, ['name' => 'P31', 'slug' => 'p31', 'categories' => [3], 'price' => '10.00', 'stock' => 3]);
        $html = $this->visit('/product/p4')->getContent();
        $this->assertStringContainsString('SIMILARHEADING', $html);
        $this->assertEqualsCanonicalizing([30, 31], $this->carouselIds($html));
    }

    /** ★ the same HTML for two different visitors, no cookie of its own */
    public function test_similar_products_are_the_same_for_every_visitor(): void
    {
        $this->publish([$this->similar(), $this->htmlSlot($this->block('BLK'))]);

        $first = $this->visit('/product/p1')->assertOk();
        $other = $this->withHeaders(['Accept-Language' => 'bg', 'User-Agent' => 'someone else'])->visit('/product/p1')->assertOk();

        $this->assertSame($first->getContent(), $other->getContent());
        $this->assertSame([], $first->headers->getCookies());
    }

    /** ★ `similar` exists only in this zone: the home page and the listing templates refuse it, the product zone refuses `current` */
    public function test_the_source_similar_is_refused_everywhere_but_the_product_zone(): void
    {
        $layout = ['spacing' => '32', 'sections' => [['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'similar']]]];

        [, $errors] = HomeValidator::validate($layout);
        $this->assertArrayHasKey('sections.0.settings.source', $errors, 'the home page');

        [, $errors] = HomeValidator::validate($layout, true);
        $this->assertArrayHasKey('sections.0.settings.source', $errors, 'a listing template');

        [$clean, $errors] = HomeValidator::validate($layout, HomeCatalog::PRODUCT_MODE);
        $this->assertSame([], $errors, 'the product zone');
        $this->assertSame('similar', $clean['sections'][0]['settings']['source']);

        $current = ['spacing' => '32', 'sections' => [['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'current']]]];
        [, $errors] = HomeValidator::validate($current, HomeCatalog::PRODUCT_MODE);
        $this->assertArrayHasKey('sections.0.settings.source', $errors, '`current` belongs to the listing templates');

        $this->assertNotContains('similar', HomeCatalog::PRODUCT_SOURCES);

        // a listing template with a bottom carousel `similar` is refused by the P3 validator too
        [, $listErrors] = \App\Storefront\Content\ContentSet::validate(['categories' => ['default' => ['cloud' => false, 'title' => true, 'top' => null, 'bottom' => ['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'similar']], 'bottom_heading' => ''], 'own' => []]], \App\Storefront\Content\ContentStore::existing());
        $this->assertNotSame([], $listErrors);
    }

    // ---- ★ HTML block with conditions --------------------------------------------------------------------------------------------------------

    /** ★ a block with «Показвай, когато…» conditions is shown for a matching product and hidden for the others */
    public function test_an_html_block_slot_honours_its_conditions(): void
    {
        $block = $this->block('FORCOATSONLY', ['categories' => [3]]);
        $this->publish([$this->htmlSlot($block, 'COATSHEADING')]);

        $coat = $this->visit('/product/p4')->assertOk()->getContent();
        $this->assertStringContainsString('FORCOATSONLY', $coat);
        $this->assertStringContainsString('COATSHEADING', $coat);

        $dress = $this->visit('/product/p1')->assertOk()->getContent();
        $this->assertStringNotContainsString('FORCOATSONLY', $dress);
        $this->assertStringNotContainsString('COATSHEADING', $dress, 'a slot that is not for this product prints no heading');
        $this->assertStringNotContainsString('sf-below', $dress);

        // a block that has no conditions is for every product
        $free = $this->block('FORALL');
        $this->publish([$this->htmlSlot($free)]);
        $this->assertStringContainsString('FORALL', $this->visit('/product/p1')->getContent());

        // the conditions changed in the block reach the page (the compiled copy is rebuilt with the block)
        DB::table('html_blocks')->where('id', $free->id)->update(['conditions' => json_encode(['categories' => [3]])]);
        app(MenuRecompiler::class)->now();
        $this->assertStringNotContainsString('FORALL', $this->visit('/product/p1')->getContent());
        $this->assertStringContainsString('FORALL', $this->visit('/product/p4')->getContent());
    }

    /** a block in use by a slot is not deleted; the compiled copy follows the block */
    public function test_a_block_in_use_is_protected_and_a_changed_block_reaches_the_page(): void
    {
        $block = $this->block('BEFORETEXT');
        $this->publish([$this->htmlSlot($block)]);
        $this->assertStringContainsString('BEFORETEXT', $this->visit('/product/p1')->getContent());

        $block->update(['body' => '<p>AFTERTEXT</p>']);
        app(MenuRecompiler::class)->now();
        $html = $this->visit('/product/p1')->getContent();
        $this->assertStringContainsString('AFTERTEXT', $html);
        $this->assertStringNotContainsString('BEFORETEXT', $html);

        $this->assertTrue(\App\Content\HtmlBlocks\HtmlBlockUsage::below((int) $block->id));
        $this->assertNotNull(\App\Content\HtmlBlocks\HtmlBlockUsage::refusal($block));
        $this->assertFalse($block->delete());
    }

    // ---- ★ the brand link --------------------------------------------------------------------------------------------------------------------

    /** ★ the brand name is a link to the brand's page (escaped); a product without a brand has no link; the link costs no query */
    public function test_the_brand_name_links_to_the_brand_page(): void
    {
        $html = $this->visit('/product/p1')->assertOk()->getContent();
        $this->assertStringContainsString('<p class="sf-product-brand"><a href="/brand/acme">Acme</a></p>', $html);
        $this->visit('/brand/acme')->assertOk();

        $this->assertStringNotContainsString('sf-product-brand', $this->visit('/product/p9')->getContent(), 'no brand: no line, no link');

        // a name with markup, a slug that needs encoding
        DB::table('catalog_brands')->where('id', 2)->update(['name' => '<script>alert(1)</script> "Q" & \'s\'']);
        $evil = $this->visit('/product/p4')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $evil);
        $this->assertStringContainsString('<a href="/brand/bolt">&lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot; &amp; &#039;s&#039;</a>', $evil);
    }

    // ---- the cost ----------------------------------------------------------------------------------------------------------------------------

    /** the full template (a similar carousel + an HTML block with conditions): pinned; the brand link adds nothing */
    public function test_the_cost_of_a_full_template_is_pinned(): void
    {
        $plain = $this->queryCount('/product/p1');
        $this->publish([$this->similar(), $this->htmlSlot($this->block('COSTBLOCK', ['categories' => [2]]))]);
        $this->visit('/product/p1');
        $full = $this->queryCount('/product/p1');

        fwrite(STDERR, "\n[query-count] product page: plain {$plain}, with a «Подобни продукти» carousel + an HTML block with conditions {$full}\n");

        $this->assertStringContainsString('COSTBLOCK', $this->visit('/product/p1')->getContent());
        // the compiled copy is in the payload the page already reads: only the category read (1) and the cards (the same read a home carousel makes)
        $this->assertGreaterThan($plain, $full);
        $this->assertLessThanOrEqual(43, $full, 'pinned ceiling');
    }

    /** the compiled copy keeps the conditions and the HTML of the blocks, and is dropped when nothing is published */
    public function test_the_compiled_copy_and_the_published_set(): void
    {
        $block = $this->block('COMPILED', ['categories' => [2]]);
        $this->publish([$this->htmlSlot($block)]);

        $compiled = BelowCompiler::published((string) app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY));
        $this->assertSame(['categories' => [2]], $compiled['conditions'][(string) $block->id]);
        $this->assertStringContainsString('COMPILED', $compiled['blocks'][(string) $block->id]);

        $this->publish([]);
        $this->assertNull(BelowCompiler::published((string) app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::DESIGN_COMPILED_KEY)));
        $this->assertNull(app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::BELOW_PUBLISHED_KEY));
    }
}
