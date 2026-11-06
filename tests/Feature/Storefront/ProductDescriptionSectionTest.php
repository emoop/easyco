<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Below\BelowStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P4b.1: the product page has no description block of its own; the description is the SOURCE `description` of a section (accordion and tabs); the tabs slot prints a heading only when one was typed; the right column
 * has no inner scroll (CSS + the small script).
 */
class ProductDescriptionSectionTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const DESC = '44444444-4444-4444-8444-444444444444';

    private const DELIV = '11111111-1111-4111-8111-111111111111';

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
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'description' => '<p>THEDESCRIPTION</p>']);
        $this->makeProduct(2, ['name' => 'P2', 'slug' => 'p2', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'description' => '<p></p>']);
        $this->makeProduct(3, ['name' => 'P3', 'slug' => 'p3', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'description' => '<p>&nbsp; </p>']);
        $this->makeProduct(4, ['name' => 'P4', 'slug' => 'p4', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'description' => '<p>OK</p><script>alert(1)</script><p onclick="x()" style="color:red">CLICKY</p>']);
        $this->makeAttribute(1, 'Size', ['S', 'M']);
        $this->makeProduct(5, ['type' => 'variable', 'name' => 'P5', 'slug' => 'p5', 'categories' => [1], 'description' => '<p>VARDESC</p>', 'variations' => [['attrs' => [1 => 'S'], 'price' => '40.00', 'stock' => 1], ['attrs' => [1 => 'M'], 'price' => '45.00', 'stock' => 3]]]);
    }

    private function visit(string $slug): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug);
    }

    private function publishSections(): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => [
            ['key' => self::DESC, 'title' => 'Description', 'source' => 'description', 'text' => '<p>IGNOREDGENERAL</p>'],
            ['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>GLOBALDELIVERY</p>'],
        ]]);
        $this->assertSame([], $errors, json_encode($errors));
        SectionStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    private function publishAccordion(): void
    {
        $layout = ProductSummaryLayout::default();
        $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
        app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();
    }

    private function publishTabs(string $heading = ''): void
    {
        [$clean, $errors] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => $heading, 'sections' => [self::DESC, self::DELIV]]]]);
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

    /** ★ the description block is gone: no `sf-description` markup for any description, with or without sections */
    public function test_the_product_page_has_no_description_block(): void
    {
        foreach (['p1', 'p2', 'p4', 'p5'] as $slug) {
            $this->assertStringNotContainsString('sf-description', $this->visit($slug)->assertOk()->getContent(), $slug);
        }

        $this->publishSections();
        $this->publishAccordion();
        $this->assertStringNotContainsString('sf-description', $this->visit('p1')->getContent());
    }

    /** ★ the source `description` prints the product's description in the accordion AND in a tab; the general text of such a section means nothing */
    public function test_the_description_source_prints_in_the_accordion_and_in_a_tab(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->publishTabs();

        $html = $this->visit('p1')->assertOk()->getContent();

        $column = (string) substr($html, (int) strpos($html, 'sf-psec-list'), (int) strpos($html, '<div class="sf-below">') - (int) strpos($html, 'sf-psec-list'));
        $this->assertSame(1, substr_count($column, 'THEDESCRIPTION'), 'in the accordion');
        $this->assertSame(1, substr_count((string) strstr($html, '<div class="sf-below">'), 'THEDESCRIPTION'), 'in the tab panel');
        $this->assertStringContainsString('<summary class="sf-psec-title">Description</summary>', $html);
        $this->assertStringContainsString('<label class="sf-ptab-label" for="sf-ptab-1-1">Description</label>', $html);
        $this->assertStringNotContainsString('IGNOREDGENERAL', $html);
        $this->assertStringContainsString('VARDESC', $this->visit('p5')->getContent(), 'a variable product too');
    }

    /** ★ an empty, whitespace-only or `<p></p>` description prints no section and no tab; the other sections stay */
    public function test_an_empty_description_prints_nothing(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->publishTabs();

        foreach (['p2', 'p3'] as $slug) {
            $html = $this->visit($slug)->assertOk()->getContent();

            $this->assertStringNotContainsString('>Description</summary>', $html, $slug);
            $this->assertStringNotContainsString('>Description</label>', $html, $slug);
            $this->assertStringContainsString('>Delivery</summary>', $html, $slug);
            $this->assertSame(1, substr_count($html, 'class="sf-ptab-input"'), $slug.': one tab left');
        }
    }

    /** ★ markup in the description is neutralised as it always was */
    public function test_the_description_is_sanitised(): void
    {
        $this->publishSections();
        $this->publishAccordion();
        $this->publishTabs();

        $html = $this->visit('p4')->assertOk()->getContent();
        $body = (string) strstr($html, 'sf-psec-list');

        $this->assertStringContainsString('CLICKY', $body);
        $this->assertDoesNotMatchRegularExpression('/<script|onclick=|\sstyle=/i', $body);
    }

    /** ★ the closed list of sources: exactly these four, a bad value is refused and dropped on read; stored data stays valid */
    public function test_the_source_list_is_closed(): void
    {
        $this->assertEqualsCanonicalizing(['description', 'global', 'product', 'product_or_global', 'meta', 'blocks'], SectionSet::SOURCES);

        [, $errors] = SectionSet::validate(['sections' => [['title' => 'T', 'source' => 'html_block']]]);
        $this->assertNotSame([], $errors);

        $read = SectionSet::read(['sections' => [['key' => self::DESC, 'title' => 'D', 'source' => 'description'], ['key' => self::DELIV, 'title' => 'X', 'source' => 'bogus']]]);
        $this->assertSame([self::DESC], array_column($read['sections'], 'key'));

        [$clean] = SectionSet::validate(['sections' => [['key' => self::DESC, 'title' => 'D', 'source' => 'description', 'text' => '<p>x</p>']]]);
        $this->assertSame('', $clean['sections'][0]['text'], 'no general text for the description source');
    }

    /** ★ the description itself costs nothing (it is on the loaded row); the page with sections reads the product's texts and switches in ONE query (P4c), the page without sections is unchanged (pin) */
    public function test_the_description_source_costs_no_query(): void
    {
        $this->visit('p1');
        $plain = $this->queryCount('p1');
        $plainVar = $this->queryCount('p5');

        $this->publishSections();
        $this->publishAccordion();
        $this->publishTabs();

        $this->visit('p1');
        $this->assertSame($plain + 1, $this->queryCount('p1'), 'the description is on the loaded row: only the one read of the texts and switches');
        $this->assertSame($plainVar + 1, $this->queryCount('p5'), 'variable product: the same one read');
        fwrite(STDERR, "\n[query-count] P4b.1 product page: simple {$plain}, variable {$plainVar}\n");
    }

    /** ★ the tabs slot prints a heading ONLY when one was typed; a tab title occurs once (its label); an empty heading is valid */
    public function test_the_tabs_slot_heading_is_never_filled_in(): void
    {
        $this->publishSections();

        $this->publishTabs('');
        $below = (string) strstr($this->visit('p1')->assertOk()->getContent(), '<div class="sf-below">');
        $this->assertStringNotContainsString('sf-hp-heading', $below, 'no typed heading: none printed');
        $this->assertSame(1, substr_count($below, '>Description<'), 'the title once: the tab label');
        $this->assertSame(1, substr_count($below, '>Delivery<'));

        $this->publishTabs('TYPEDHEADING');
        $below = (string) strstr($this->visit('p1')->getContent(), '<div class="sf-below">');
        $this->assertSame(1, preg_match_all('/class="sf-hp-section sf-hp-heading"/', $below));
        $this->assertSame(1, substr_count($below, 'TYPEDHEADING'));
        $this->assertSame(1, substr_count($below, '>Description<'));

        [, $errors] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'sections' => [self::DESC]]]]);
        $this->assertSame([], $errors, 'a heading is not required');
    }

    /** ★ the right column never scrolls inside itself: no max-height, no overflow on `.sf-summary`; the script sets the custom property and listens for the accordion's `toggle` */
    public function test_the_summary_column_has_no_inner_scroll(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));
        preg_match('/\n {8}\.sf-summary \{(.*?)\n {8}\}/s', $css, $m);

        $this->assertNotEmpty($m, 'the sticky rule of .sf-summary');
        $this->assertDoesNotMatchRegularExpression('/max-height|overflow/', preg_replace('#/\*.*?\*/#s', '', $m[1]));
        $this->assertStringContainsString('top: var(--sf-summary-top, auto);', $m[1]);

        $js = (string) file_get_contents(base_path('resources/js/storefront.js'));
        $this->assertStringContainsString("'--sf-summary-top'", $js);
        $this->assertStringContainsString("addEventListener('toggle'", $js);
        $this->assertStringContainsString('ResizeObserver', $js);
    }
}
