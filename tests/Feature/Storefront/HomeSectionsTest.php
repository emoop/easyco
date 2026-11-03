<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HP-1: the home page built from the published sections. With NO layout published the page is the one it always was (its intro, its category tiles, its newest products); a published layout takes the page
 * over (a hidden <h1> for search engines, then the sections in order, each with its spacing); the heading is a real <h2|h3|h4> with escaped text; an HTML block comes from the compiled copy (no query of its
 * own, however many blocks); the draft never reaches the shop and the signed preview shows it; a layout that does not validate on read is the old page.
 */
class HomeSectionsTest extends TestCase
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

        for ($i = 1; $i <= 3; $i++) {
            $this->makeProduct($i, ['name' => "P{$i}", 'slug' => "p{$i}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => sprintf('2026-01-%02d 10:00:00', $i)]);
        }
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function publish(array $sections, string $spacing = '32'): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => $spacing, 'sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    /** @return array<string, mixed> */
    private function heading(string $text, array $settings = []): array
    {
        return ['type' => 'heading', 'settings' => ['text' => $text] + $settings];
    }

    /** @return array<string, mixed> */
    private function blockSection(HtmlBlockModel $block, array $settings = []): array
    {
        return ['type' => 'html_block', 'settings' => ['html_block' => (int) $block->id] + $settings];
    }

    private function block(string $body = '<p>Big sale</p>', string $title = 'Promo'): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $title, 'body' => $body]);
    }

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    /** The queries a warm home page runs. */
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

    // ---- no layout: the page it always was ------------------------------------------------------------------------------------------------

    public function test_with_no_layout_published_the_page_is_the_old_one(): void
    {
        $html = $this->home();

        $this->assertStringNotContainsString('sf-intro', $html, 'no intro any more');
        $this->assertStringContainsString('class="sf-category-row"', $html);
        $this->assertStringContainsString('id="newest"', $html);
        $this->assertStringNotContainsString('sf-hp', $html);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY));
    }

    public function test_publishing_the_default_layout_or_taking_the_layout_back_is_byte_identical_to_before(): void
    {
        $before = $this->home();

        $this->publish([$this->heading('Hello')]);
        $this->assertStringContainsString('sf-hp', $this->home());

        // «Начални настройки»: the default layout (no sections) is not stored at all
        $this->publish([]);

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY));
        $this->assertSame($before, $this->home(), 'the same page, byte for byte');
    }

    // ---- a published layout ---------------------------------------------------------------------------------------------------------------

    public function test_a_layout_takes_the_page_over_keeps_one_h1_and_prints_the_sections_in_order(): void
    {
        $block = $this->block('<p>Big sale</p>');
        $this->publish([$this->heading('Spring', ['level' => 'h3']), $this->blockSection($block), $this->heading('Last')]);
        $html = $this->home();

        $this->assertStringNotContainsString('sf-category-row', $html, 'the old sections are not printed beside the new ones');
        $this->assertStringNotContainsString('id="newest"', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'one h1 for the page');
        $this->assertStringContainsString('<h1 class="sf-sr">', $html, 'hidden for the eye, kept for search engines and screen readers');

        $positions = array_map(static fn (string $needle): int|false => strpos($html, $needle), ['>Spring<', 'Big sale', '>Last<']);
        $this->assertNotContains(false, $positions);
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2], 'in the published order');
    }

    public function test_a_hidden_section_is_not_printed_and_the_spacing_classes_follow_the_values(): void
    {
        $this->publish([
            $this->heading('Hidden', ['show' => false]),
            $this->heading('One'),
            $this->heading('Two', ['spacing' => '96']),
            $this->heading('Three', ['spacing' => '0']),
            $this->heading('Four'),
        ], '48');
        $html = $this->home();

        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertStringContainsString('<div class="sf-hp sf-hp-gap-48">', $html, 'the general spacing is the wrapper\'s class');
        $this->assertSame(['sf-hp-section sf-hp-heading', 'sf-hp-section sf-hp-heading sf-hp-mt-96', 'sf-hp-section sf-hp-heading sf-hp-mt-0', 'sf-hp-section sf-hp-heading'], $this->sectionClasses($html), 'the first printed section has no top spacing');
    }

    /** @return list<string> */
    private function sectionClasses(string $html): array
    {
        preg_match_all('/<section class="(sf-hp-section[^"]*)">/', $html, $m);

        return $m[1];
    }

    public function test_the_default_spacing_is_32_and_every_step_of_the_list_is_a_class_of_the_wrapper(): void
    {
        $this->publish([$this->heading('Hi')]);
        $this->assertStringContainsString('sf-hp-gap-32', $this->home());

        foreach (HomeCatalog::SPACINGS as $step) {
            $this->publish([$this->heading('Hi')], $step);
            $this->assertStringContainsString("sf-hp-gap-{$step}\"", $this->home(), $step);
        }
    }

    // ---- the heading --------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function levels(): array
    {
        return ['h2' => ['h2'], 'h3' => ['h3'], 'h4' => ['h4']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('levels')]
    public function test_the_heading_is_a_real_heading_of_its_level_with_decorative_lines(string $level): void
    {
        $this->publish([$this->heading('New in', ['level' => $level, 'thickness' => '2'])]);
        $html = $this->home();

        $this->assertStringContainsString("<{$level} class=\"sf-hp-heading-text\">New in</{$level}>", $html);
        $this->assertSame(2, substr_count($html, '<span class="sf-hp-line" aria-hidden="true"></span>'), 'a line on each side, decorative');
        $this->assertStringContainsString("sf-hp-{$level} sf-hp-lines sf-hp-l2", $html);
    }

    public function test_the_text_only_style_has_no_lines_and_the_text_is_escaped(): void
    {
        $this->publish([$this->heading('Tom & "Jerry" it\'s 5 > 4', ['lines' => 'none'])]);
        $html = $this->home();

        $this->assertStringNotContainsString('sf-hp-line', $html);
        $this->assertStringContainsString('sf-hp-plain', $html);
        $this->assertStringContainsString('Tom &amp; &quot;Jerry&quot; it&#039;s 5 &gt; 4', $html);
        $this->assertStringNotContainsString('Tom & "Jerry"', $html);
    }

    // ---- the HTML block -----------------------------------------------------------------------------------------------------------------------

    public function test_the_html_block_comes_from_the_compiled_copy_with_no_query_per_block(): void
    {
        $one = $this->block('<p>One</p>', 'One');
        $this->publish([$this->blockSection($one)]);
        $withOne = $this->queries();

        $two = $this->block('<p>Two</p>', 'Two');
        $three = $this->block('<p>Three</p>', 'Three');
        $this->publish([$this->blockSection($one), $this->blockSection($two), $this->blockSection($three), $this->heading('Hi')]);
        $withThree = $this->queries();

        $this->assertStringContainsString('<p>Three</p>', $this->home());
        $this->assertSame($withOne, $withThree, 'the blocks and the heading cost no query: they are in the cached appearance read');
    }

    public function test_the_home_page_costs_fewer_queries_with_a_layout_than_the_old_page_and_the_count_is_pinned(): void
    {
        $old = $this->queries();

        $this->publish([$this->heading('Hi'), $this->blockSection($this->block())]);
        $layout = $this->queries();

        $this->assertLessThan($old, $layout, 'no listing and no category tree');
        $this->assertSame(self::LAYOUT_QUERIES, $layout, 'a home page of sections: pinned');
    }

    /** The queries of a warm home page with a layout of sections (the shared storefront pipeline only: no listing, no tree). */
    private const LAYOUT_QUERIES = 2;

    public function test_a_block_edited_after_publishing_is_in_the_page_after_the_compiled_copy_is_refreshed(): void
    {
        $block = $this->block('<p>Old text</p>');
        $this->publish([$this->blockSection($block)]);
        $this->assertStringContainsString('Old text', $this->home());

        DB::table('html_blocks')->where('id', $block->id)->update(['body' => '<p>New text</p>']);

        $this->assertTrue(app(HomeCompiler::class)->refresh(), 'the compiled copy was out of date');
        $this->assertFalse(app(HomeCompiler::class)->refresh(), 'and is now current');
        $this->assertStringContainsString('New text', $this->home());
        $this->assertStringNotContainsString('Old text', $this->home());
    }

    public function test_a_block_used_by_the_published_or_the_draft_home_layout_cannot_be_deleted(): void
    {
        $published = $this->block('<p>A</p>', 'Used published');
        $drafted = $this->block('<p>B</p>', 'Used draft');
        $free = $this->block('<p>C</p>', 'Free');

        $this->assertFalse(HtmlBlockUsage::isUsed((int) $published->id));

        $this->publish([$this->blockSection($published)]);
        [$draft] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->blockSection($published), $this->blockSection($drafted)]]);
        HomeDrafts::saveDraft($draft);

        $this->assertTrue(HtmlBlockUsage::home((int) $published->id));
        $this->assertTrue(HtmlBlockUsage::home((int) $drafted->id), 'the working copy counts too');
        $this->assertFalse(HtmlBlockUsage::home((int) $free->id));
        $this->assertTrue(HtmlBlockUsage::isUsed((int) $drafted->id));
        $this->assertStringContainsString('the home page', (string) HtmlBlockUsage::refusal($published));
        $this->assertNull(HtmlBlockUsage::refusal($free));
    }

    // ---- the draft, the preview, a bad row ------------------------------------------------------------------------------------------------

    public function test_a_draft_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        [$draft] = HomeValidator::validate(['spacing' => '16', 'sections' => [$this->heading('Only in the draft')]]);
        HomeDrafts::saveDraft($draft);

        $this->assertStringNotContainsString('Only in the draft', $this->home());
        $this->assertStringContainsString('class="sf-category-row"', $this->home());

        $this->app->forgetScopedInstances();
        $preview = $this->get(DesignPreviewController::previewUrl(HomeCatalog::ZONE))->assertOk();

        $this->assertStringContainsString('Only in the draft', $preview->getContent());
        $this->assertStringContainsString('sf-hp-gap-16', $preview->getContent());
        $preview->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_the_preview_needs_a_valid_signature(): void
    {
        $this->get('/design-preview?zone=home')->assertNotFound();
    }

    public function test_a_layout_that_does_not_validate_on_read_is_the_old_page(): void
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([
            ['spacing' => '10', 'sections' => [$this->heading('Hi')]],
            ['spacing' => '32', 'sections' => [$this->heading('Hi', ['level' => 'h1'])]],
            ['spacing' => '32', 'sections' => [['type' => 'slider', 'settings' => []]]],
        ] as $layout) {
            $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode(['hash' => 'x', 'zones' => ['home' => ['global' => ['layout' => $layout, 'blocks' => []]]]]));
            app(StorefrontAppearance::class)->refresh();
            $html = $this->home();

            $this->assertStringContainsString('class="sf-category-row"', $html, json_encode($layout));
            $this->assertStringNotContainsString('sf-hp-heading', $html);
        }
    }

    // ---- the markup ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_page_has_no_style_attribute_no_inline_script_and_is_the_same_for_every_visitor(): void
    {
        $this->publish([$this->heading('Hi', ['spacing' => '24']), $this->blockSection($this->block()), $this->heading('Bye')], '96');
        $html = $this->home();

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
        $this->assertSame($html, $this->home());
    }
}
