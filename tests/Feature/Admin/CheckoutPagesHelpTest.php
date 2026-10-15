<?php

namespace Tests\Feature\Admin;

use App\Content\PageWriter;
use App\Filament\Pages\Help;
use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Resources\PageResource;
use App\Filament\Support\HelpTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HELP-2: the help topics `checkout` (Admin > Checkout) and `pages` (Content > Pages): both files in both languages with the SAME anchors, the topics
 * registered and labelled, every «Help: how this works →» link added to those screens pointing at an anchor that really exists.
 */
class CheckoutPagesHelpTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    /** The contract with the two Markdown files of each topic (pinned like HelpPageTest::ANCHORS): later edits must never rename them. */
    private const ANCHORS = [
        'checkout' => ['action-checkout-overview', 'action-checkout-region', 'action-checkout-fields', 'action-checkout-terms', 'action-checkout-terms-version', 'action-checkout-invoice', 'action-checkout-privacy', 'action-checkout-cron'],
        'pages' => ['action-pages-overview', 'action-pages-slug', 'action-pages-editor', 'action-pages-publish', 'action-pages-preview', 'action-pages-revisions', 'action-pages-footer', 'action-pages-archive', 'action-pages-legal', 'action-storefront-home'],
    ];

    /** @return array<string, array{0: string, 1: string}> */
    public static function topicsAndLocales(): array
    {
        $cases = [];
        foreach (['checkout', 'pages'] as $topic) {
            foreach (['bg', 'en'] as $locale) {
                $cases["{$topic} {$locale}"] = [$topic, $locale];
            }
        }

        return $cases;
    }

    /** @return list<string> the anchors of a file, in order */
    private function anchorsOf(string $topic, string $locale): array
    {
        preg_match_all('/^#{2,4} .*\{#([a-z0-9-]+)\} *$/m', (string) file_get_contents(resource_path("help/{$locale}/{$topic}.md")), $m);

        return $m[1];
    }

    #[DataProvider('topicsAndLocales')]
    public function test_every_anchor_is_in_the_file_of_both_languages_in_order_and_once(string $topic, string $locale): void
    {
        $this->assertFileExists(resource_path("help/{$locale}/{$topic}.md"));
        $this->assertSame(self::ANCHORS[$topic], $this->anchorsOf($topic, $locale));
    }

    #[DataProvider('topicsAndLocales')]
    public function test_every_heading_carries_an_anchor_so_both_languages_have_the_same_number(string $topic, string $locale): void
    {
        $headings = preg_match_all('/^#{2,4} /m', (string) file_get_contents(resource_path("help/{$locale}/{$topic}.md")));

        $this->assertSame(count(self::ANCHORS[$topic]), $headings, 'no heading without an anchor');
    }

    public function test_the_two_topics_are_registered_and_labelled_in_both_languages(): void
    {
        $this->assertSame(['orders', 'shipping', 'mail', 'checkout', 'pages'], HelpTopics::all());
        $this->assertTrue(HelpTopics::has('checkout'));
        $this->assertTrue(HelpTopics::has('pages'));
        $this->assertSame('Настройки на чекаута', trans('help.topics.checkout', [], 'bg'));
        $this->assertSame('Страници', trans('help.topics.pages', [], 'bg'));
        $this->assertSame('Checkout settings', trans('help.topics.checkout', [], 'en'));
        $this->assertSame('Pages', trans('help.topics.pages', [], 'en'));
        $this->assertNotNull(HelpTopics::path('checkout', 'bg'));
        $this->assertNotNull(HelpTopics::path('pages', 'en'));
    }

    #[DataProvider('topicsAndLocales')]
    public function test_the_help_page_renders_each_topic_with_its_headings_as_ids_and_no_script(string $topic, string $locale): void
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale($locale);

        $html = $this->get(Help::getUrl(['topic' => $topic]))->assertOk()->getContent();

        foreach (self::ANCHORS[$topic] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $html, "{$topic}/{$locale}: #{$anchor} renders as an id");
        }

        // The rendered text is the <article class="help-content"> (the page around it carries the panel's own scripts).
        $start = (int) strpos($html, '<article class="help-content">');
        $article = substr($html, $start, (int) strpos($html, '</article>', $start) - $start);
        $this->assertNotSame('', $article);
        $this->assertStringNotContainsString('<script', $article, 'the rendered text carries no script');
    }

    public function test_an_unknown_topic_still_404s(): void
    {
        $this->actingAsStaff('Administrator');

        $this->get(url('/admin/help/does-not-exist'))->assertNotFound();
        $this->get(url('/admin/help/checkout-2'))->assertNotFound();
    }

    // ---- the links ------------------------------------------------------------------------------------------------------------------

    /** @return list<array{0: string, 1: string}> [topic, anchor] of every help link in a page, in order */
    private function linksIn(string $html): array
    {
        preg_match_all('/<a href="[^"]*\/admin\/help\/([a-z]+)#(action-[a-z0-9-]+)"/', $html, $m, PREG_SET_ORDER);

        return array_map(fn (array $x): array => [$x[1], $x[2]], $m);
    }

    /** @param  list<array{0: string, 1: string}>  $links */
    private function assertEveryLinkHasItsAnchor(array $links, string $where): void
    {
        $this->assertNotSame([], $links, "{$where} has help links");

        foreach ($links as [$topic, $anchor]) {
            foreach (['bg', 'en'] as $locale) {
                $this->assertContains($anchor, $this->anchorsOf($topic, $locale), "{$where}: the link to {$topic}#{$anchor} has a section in {$locale}");
            }
        }
    }

    public function test_the_checkout_settings_page_links_each_section_and_the_terms_to_existing_anchors(): void
    {
        $this->actingAsStaff('Administrator');
        $links = $this->linksIn($this->get(CheckoutSettings::getUrl())->assertOk()->getContent());

        $this->assertEveryLinkHasItsAnchor($links, 'Checkout settings');
        $this->assertEqualsCanonicalizing(
            [['checkout', 'action-checkout-fields'], ['checkout', 'action-checkout-terms'], ['checkout', 'action-checkout-region'], ['checkout', 'action-checkout-invoice'], ['checkout', 'action-checkout-privacy']],
            $links,
            'one at the top of each of the four sections, plus the terms',
        );
    }

    public function test_the_pages_screens_link_to_existing_anchors(): void
    {
        $this->actingAsStaff('Administrator');
        $page = app(PageWriter::class)->create(['title' => 'Returns', 'slug' => 'returns', 'body' => '<p>Text.</p>', 'status' => 'published']);

        $list = $this->linksIn($this->get(PageResource::getUrl('index'))->assertOk()->getContent());
        $this->assertEveryLinkHasItsAnchor($list, 'the Pages list');
        $this->assertSame([['pages', 'action-pages-overview']], $list);

        $form = $this->linksIn($this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk()->getContent());
        $this->assertEveryLinkHasItsAnchor($form, 'the page form');
        $this->assertEqualsCanonicalizing([['pages', 'action-pages-slug'], ['pages', 'action-pages-publish'], ['pages', 'action-pages-footer']], $form);

        $revision = $this->linksIn($this->get(PageResource::getUrl('revision', ['record' => $page, 'revision' => 1]))->assertOk()->getContent());
        $this->assertEveryLinkHasItsAnchor($revision, 'the revision view');
        $this->assertSame([['pages', 'action-pages-revisions']], $revision);
    }

    public function test_the_create_form_has_the_same_links_as_the_edit_form(): void
    {
        $this->actingAsStaff('Administrator');

        $this->assertEqualsCanonicalizing(
            [['pages', 'action-pages-slug'], ['pages', 'action-pages-publish'], ['pages', 'action-pages-footer']],
            $this->linksIn($this->get(PageResource::getUrl('create'))->assertOk()->getContent()),
        );
    }
}
