<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuWriter;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Http\Controllers\Storefront\FooterPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Menu\CompiledMenus;
use Carbon\Carbon;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FT-1: the footer builder's data and its rendering. The layout is validated on save, publish and read; the compiled copy carries the menus and HTML blocks so the page costs no query;
 * the year is the clock's at render time; colours and the border are only classes and CSS variables; a draft never reaches the public page.
 */
class FooterBuilderTest extends TestCase
{
    use AssertsSafeHtml;
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
        FooterCatalog::forgetWarnings();
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    /** @param  array<string, mixed>  $settings */
    private function block(string $type, array $settings = []): array
    {
        return ['type' => $type, 'settings' => $settings + FooterCatalog::defaultsFor($type)];
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private function column(array $blocks, int $span = 1, string $align = 'start'): array
    {
        return ['span' => $span, 'align' => $align, 'blocks' => $blocks];
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function layout(callable $change): array
    {
        return $change(FooterLayout::default());
    }

    /** A layout with these rows (each a list of columns) in the upper level. @param  list<list<array<string, mixed>>>  $rows */
    private function withRows(array $rows, ?string $template = null): array
    {
        return $this->layout(function (array $l) use ($rows, $template): array {
            $l['upper']['rows'] = array_map(fn (array $columns): array => ['columns' => $columns], $rows);

            if ($template !== null) {
                $l['lower']['text_template'] = $template;
            }

            return $l;
        });
    }

    private function publish(array $layout): void
    {
        app(FooterCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    private function footerHtml(string $html): string
    {
        $start = strpos($html, '<footer');

        return substr($html, (int) $start);
    }

    private function page(string $slug, string $title, string $status = 'published'): PageModel
    {
        return app(PageWriter::class)->create(['title' => $title, 'slug' => $slug, 'body' => '<p>Text</p>', 'status' => $status]);
    }

    // ---- validation ----------------------------------------------------------------------------------------------------------------

    public function test_the_default_layout_is_valid(): void
    {
        $validation = FooterValidator::validate(FooterLayout::default());

        $this->assertTrue($validation->passes(), json_encode($validation->errors));
        $this->assertSame(['menu'], array_column($validation->layout['upper']['rows'][0]['columns'][0]['blocks'], 'type'));
        $this->assertSame('© [year] Raf Shop', $validation->layout['lower']['text_template']);
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function brokenLayouts(): array
    {
        $col = static fn (array $blocks = [], int|string $span = 1): array => ['span' => $span, 'align' => 'start', 'blocks' => $blocks];
        $social = static fn (string $url): array => ['type' => 'social', 'settings' => ['items' => [['network' => 'facebook', 'url' => $url]]]];
        $textBlock = ['type' => 'text', 'settings' => ['heading' => '', 'body' => 'x']];
        $rows = static fn (array $l, array $rows): array => (function () use ($l, $rows) {
            $l['upper']['rows'] = $rows;

            return $l;
        })();

        return [
            'six columns' => [fn (array $l): array => $rows($l, [['columns' => array_fill(0, 6, $col())]]), 'upper.rows.0.columns'],
            'zero columns' => [fn (array $l): array => $rows($l, [['columns' => []]]), 'upper.rows.0.columns'],
            'three rows' => [fn (array $l): array => $rows($l, array_fill(0, 3, ['columns' => [$col()]])), 'upper.rows'],
            'no rows' => [fn (array $l): array => $rows($l, []), 'upper.rows'],
            'seven blocks in a column' => [fn (array $l): array => $rows($l, [['columns' => [$col(array_fill(0, 7, $textBlock))]]]), 'upper.rows.0.columns.0.blocks'],
            'forty-one blocks in all' => [fn (array $l): array => $rows($l, [['columns' => array_fill(0, 5, $col(array_fill(0, 6, $textBlock)))], ['columns' => [...array_fill(0, 4, $col(array_fill(0, 2, $textBlock))), $col(array_fill(0, 3, $textBlock))]]]), 'upper'],
            'a span of three' => [fn (array $l): array => $rows($l, [['columns' => [$col([], 3)]]]), 'upper.rows.0.columns.0.span'],
            'a social address with javascript:' => [fn (array $l): array => $rows($l, [['columns' => [$col([$social('javascript:alert(1)')])]]]), 'upper.rows.0.columns.0.blocks.0.settings.items.0.url'],
            'a social address with http:' => [fn (array $l): array => $rows($l, [['columns' => [$col([$social('http://example.com/x')])]]]), 'upper.rows.0.columns.0.blocks.0.settings.items.0.url'],
            'a colour that is not hex' => [fn (array $l): array => (function () use ($l) { $l['upper']['bg'] = 'red'; return $l; })(), 'upper.bg'],
            'a colour with url()' => [fn (array $l): array => (function () use ($l) { $l['lower']['text'] = 'url(x)'; return $l; })(), 'lower.text'],
            'a border of seven' => [fn (array $l): array => (function () use ($l) { $l['upper']['border_width'] = 7; return $l; })(), 'upper.border_width'],
            'a lower text over 300 characters' => [fn (array $l): array => (function () use ($l) { $l['lower']['text_template'] = str_repeat('a', 301); return $l; })(), 'lower.text_template'],
            'a bad padding' => [fn (array $l): array => (function () use ($l) { $l['upper']['padding'] = 'huge'; return $l; })(), 'upper.padding'],
            'an unknown key' => [fn (array $l): array => $l + ['extra' => 1], 'layout'],
            'an html block with no id' => [fn (array $l): array => $rows($l, [['columns' => [$col([['type' => 'html_block', 'settings' => ['html_block' => null]]])]]]), 'upper.rows.0.columns.0.blocks.0.settings.html_block'],
            'a body over 400 characters' => [fn (array $l): array => $rows($l, [['columns' => [$col([['type' => 'text', 'settings' => ['heading' => '', 'body' => str_repeat('a', 401)]]])]]]), 'upper.rows.0.columns.0.blocks.0.settings.body'],
            'a bad email' => [fn (array $l): array => $rows($l, [['columns' => [$col([['type' => 'contacts', 'settings' => ['email' => 'not-an-email']]])]]]), 'upper.rows.0.columns.0.blocks.0.settings.email'],
        ];
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $break */
    #[DataProvider('brokenLayouts')]
    public function test_a_broken_layout_is_refused_with_a_message_on_its_path(callable $break, string $path): void
    {
        $validation = FooterValidator::validate($break(FooterLayout::default()));

        $this->assertArrayHasKey($path, $validation->errors, json_encode($validation->errors));
        $this->assertNotSame('', $validation->errors[$path]);
        $this->expectException(\InvalidArgumentException::class);
        app(FooterCompiler::class)->publish($break(FooterLayout::default()), $this->settings());
    }

    public function test_five_columns_and_two_rows_and_a_wide_column_are_accepted(): void
    {
        $five = array_fill(0, 5, $this->column([$this->block('spacer'), $this->block('text', ['body' => 'x'])]));
        $layout = $this->withRows([$five, [$this->column([$this->block('text', ['body' => 'a'])], 2, 'start'), $this->column([$this->block('payments', ['methods' => ['visa']])], 1, 'end')]]);

        $this->assertTrue(FooterValidator::validate($layout)->passes());
    }

    public function test_a_block_type_outside_the_catalog_is_dropped_and_is_no_error(): void
    {
        $layout = $this->withRows([[$this->column([['type' => 'carousel', 'settings' => []], $this->block('text', ['body' => 'kept'])])]]);
        $validation = FooterValidator::validate($layout);

        $this->assertTrue($validation->passes());
        $this->assertSame(['text'], array_column($validation->layout['upper']['rows'][0]['columns'][0]['blocks'], 'type'));
    }

    public function test_an_unknown_payment_method_is_dropped_and_the_rest_keep_their_order(): void
    {
        $layout = $this->withRows([[$this->column([$this->block('payments', ['methods' => ['paypal', 'bitcoin', 'visa', 'paypal']])])]]);

        $this->assertSame(['paypal', 'visa'], FooterValidator::validate($layout)->layout['upper']['rows'][0]['columns'][0]['blocks'][0]['settings']['methods']);
    }

    public function test_colours_are_normalised_to_lower_case_and_an_empty_one_means_null(): void
    {
        $layout = $this->layout(function (array $l): array {
            $l['upper']['bg'] = '#ABCDEF';
            $l['upper']['text'] = '';

            return $l;
        });
        $clean = FooterValidator::validate($layout)->layout;

        $this->assertSame(['#abcdef', null], [$clean['upper']['bg'], $clean['upper']['text']]);
    }

    public function test_an_extension_block_is_accepted_through_the_filter_and_a_built_in_cannot_be_replaced(): void
    {
        Hook::filter(FooterCatalog::HOOK, function (array $catalog): array {
            $catalog['promo'] = ['label' => 'Promo', 'schema' => ['label' => ['kind' => 'text', 'max' => 20, 'default' => '']], 'view' => 'storefront.footer.text', 'builtin' => false];

            return $catalog;
        });

        $this->assertArrayHasKey('promo', FooterCatalog::all());
        $layout = $this->withRows([[$this->column([['type' => 'promo', 'settings' => ['label' => 'Hi']]])]]);
        $this->assertSame('promo', FooterValidator::validate($layout)->layout['upper']['rows'][0]['columns'][0]['blocks'][0]['type']);
    }

    // ---- the year ------------------------------------------------------------------------------------------------------------------

    public function test_the_year_follows_the_clock_from_the_same_compiled_value(): void
    {
        $this->publish($this->withRows([[$this->column([$this->block('text', ['body' => 'x'])])]], 'Fetish Harmanli © [year] - run by Mari Ned'));
        $stored = (string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY);

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->fresh();
        $this->assertStringContainsString('Fetish Harmanli © 2026 - run by Mari Ned', $this->home());

        Carbon::setTestNow('2027-01-01 00:30:00');
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('Fetish Harmanli © 2027 - run by Mari Ned', $this->home(), 'the same compiled value, a year later');
        $this->assertSame($stored, (string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), 'nothing was recompiled');
        $this->assertStringContainsString('[year]', $stored, 'the stored value keeps the token');
        $this->assertStringNotContainsString('2026', $stored);
    }

    public function test_the_line_is_escaped_and_an_unknown_token_stays_literal(): void
    {
        $this->publish($this->withRows([[$this->column([$this->block('text', ['body' => 'x'])])]], '<b>Shop</b> & [year] [name] [x]'));
        Carbon::setTestNow('2026-03-01 10:00:00');

        $line = $this->footerHtml($this->home());

        $this->assertStringContainsString('&lt;b&gt;Shop&lt;/b&gt; &amp; 2026 [name] [x]', $line);
        $this->assertStringNotContainsString('<b>Shop</b>', $line);
    }

    // ---- the blocks ----------------------------------------------------------------------------------------------------------------

    public function test_the_newsletter_block_is_saved_always_and_printed_only_with_its_route(): void
    {
        $layout = $this->withRows([[$this->column([$this->block('newsletter', ['heading' => 'News', 'consent' => 'I agree'])]), $this->column([$this->block('text', ['body' => 'other'])])]]);
        $this->publish($layout);

        $html = $this->footerHtml($this->home());
        $this->assertStringNotContainsString('sf-ft-newsletter', $html, 'no subscription route: nothing is printed');
        $this->assertStringContainsString('"type":"newsletter"', (string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), 'but it is saved');

        Route::post('/newsletter', fn () => 'ok')->name(FooterCatalog::NEWSLETTER_ROUTE);
        app('router')->getRoutes()->refreshNameLookups();
        $this->fresh();
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('class="sf-ft-block sf-ft-newsletter"', $html);
        $this->assertStringContainsString('action="/newsletter"', $html);
        $this->assertStringContainsString('News', $html);
        $this->assertStringContainsString('I agree', $html);
        $this->assertStringContainsString('type="email"', $html);
    }

    public function test_the_payments_block_prints_only_catalog_methods_each_with_a_name(): void
    {
        $this->publish($this->withRows([[$this->column([$this->block('payments', ['methods' => ['visa', 'cash_on_delivery', 'apple_pay']])])]]));
        $html = $this->footerHtml($this->home());

        $this->assertSame(3, substr_count($html, 'class="sf-pay-icon"'));
        $this->assertStringContainsString('Visa</span>', $html);
        $this->assertStringContainsString('Cash on delivery</span>', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_the_footer_with_every_block_has_no_active_content(): void
    {
        Route::post('/newsletter', fn () => 'ok')->name(FooterCatalog::NEWSLETTER_ROUTE);
        app('router')->getRoutes()->refreshNameLookups();
        $this->publish($this->withRows([[
            $this->column([$this->block('text', ['body' => 'x']), $this->block('newsletter')]),
            $this->column([$this->block('payments', ['methods' => FooterCatalog::PAYMENTS]), $this->block('social', ['items' => [['network' => 'facebook', 'url' => 'https://facebook.com/x']]])]),
        ]]));

        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $this->footerHtml($this->home())), 'the footer');
    }

    public function test_the_menu_block_prints_top_level_links_and_badges_and_no_sub_levels(): void
    {
        $terms = $this->page('terms', 'Terms');
        $menu = app(MenuWriter::class)->create('legal', 'Legal');
        app(MenuWriter::class)->save($menu, 'Legal', [
            $this->menuItem('page', ['target_id' => $terms->id, 'badge_text' => 'NEW', 'badge_color' => 'red', 'submenu' => 'dropdown', 'children' => [$this->menuItem('link', ['label' => 'Hidden child', 'url' => '/child'])]]),
            $this->menuItem('link', ['label' => 'Contacts', 'url' => '/contacts']),
        ]);
        $this->publish($this->withRows([[$this->column([$this->block('menu', ['menu' => $menu->id, 'heading' => 'Help'])])]]));
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('<p class="sf-ft-heading" id="sf-ft-h-r0c0b0">Help</p>', $html);
        $this->assertStringContainsString('aria-labelledby="sf-ft-h-r0c0b0"', $html);
        $this->assertStringContainsString('href="/info/terms"', $html);
        $this->assertStringContainsString('NEW', $html, 'the badge is shown');
        $this->assertStringContainsString('>Contacts</a>', $html);
        $this->assertStringNotContainsString('Hidden child', $html, 'only the top level');
    }

    public function test_a_menu_block_with_no_menu_chosen_shows_the_menu_of_the_footer_location(): void
    {
        $page = $this->page('returns', 'Returns');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $this->publish($this->withRows([[$this->column([$this->block('menu')])]]));

        $this->assertStringContainsString('href="/info/returns"', $this->footerHtml($this->home()));
    }

    public function test_a_deleted_menu_disappears_from_the_footer_without_an_error(): void
    {
        $menu = app(MenuWriter::class)->create('gone', 'Gone');
        app(MenuWriter::class)->save($menu, 'Gone', [$this->menuItem('link', ['label' => 'Vanishing', 'url' => '/v'])]);
        $this->publish($this->withRows([[$this->column([$this->block('menu', ['menu' => $menu->id])]), $this->column([$this->block('text', ['body' => 'stay'])])]]));
        $this->assertStringContainsString('Vanishing', $this->footerHtml($this->home()));

        // DS-3: the writer now refuses to delete a menu the footer uses; a menu that vanishes by other means (a hand edit, an old row) must still leave the footer without an error
        \App\Content\Menus\MenuModel::query()->whereKey($menu->id)->delete();
        app(\App\Storefront\Menu\MenuRecompiler::class)->now();
        $this->fresh();
        $html = $this->footerHtml($this->home());

        $this->assertStringNotContainsString('Vanishing', $html);
        $this->assertStringContainsString('stay', $html);
        $this->assertSame(1, substr_count($html, 'class="sf-ft-col '), 'the column that has nothing left is gone');
    }

    public function test_an_html_block_prints_sanitized_html_and_a_deleted_block_disappears(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Banner', 'body' => '<figure><a href="/promo"><img src="/storage/html-blocks/b.jpg" alt="Sale" width="300" height="200"></a></figure><script>alert(1)</script>']);
        $this->publish($this->withRows([[$this->column([$this->block('html_block', ['html_block' => $block->id])]), $this->column([$this->block('text', ['body' => 'stay'])])]]));
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('<img loading="lazy" decoding="async" src="/storage/html-blocks/b.jpg"', $html);
        $this->assertStringContainsString('href="/promo"', $html);
        $this->assertStringNotContainsString('<script', $html);

        // HDR-3: the model refuses to delete a block the published footer shows, so the row is removed behind its back (a script, an old row) and the rebuild is asked for
        $this->assertFalse($block->delete(), 'a block the footer shows is not deleted silently');
        DB::table('html_blocks')->where('id', $block->id)->delete();
        app(\App\Storefront\Menu\MenuRecompiler::class)->now();
        $this->fresh();
        $html = $this->footerHtml($this->home());

        $this->assertStringNotContainsString('b.jpg', $html);
        $this->assertStringContainsString('stay', $html);
    }

    public function test_text_contacts_social_and_spacer_blocks(): void
    {
        $this->publish($this->withRows([[
            $this->column([$this->block('text', ['heading' => 'About', 'body' => "Line one\n<i>two</i>"]), $this->block('spacer', ['size' => 'l'])]),
            $this->column([$this->block('contacts', ['phone' => '+359 888 123 456', 'email' => 'shop@example.com', 'address' => "Main st 1\nHarmanli", 'hours' => 'Mon-Fri'])]),
            $this->column([$this->block('social', ['items' => [['network' => 'facebook', 'url' => 'https://facebook.com/x']]])]),
        ]]));
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('<p class="sf-ft-heading">About</p>', $html);
        $this->assertStringContainsString("Line one<br />\n&lt;i&gt;two&lt;/i&gt;", $html);
        $this->assertStringContainsString('sf-ft-space sf-ft-space-l', $html);
        $this->assertStringContainsString('href="tel:+359888123456"', $html);
        $this->assertStringContainsString('href="mailto:shop@example.com"', $html);
        $this->assertStringContainsString('Main st 1<br />', $html);
        $this->assertStringContainsString('href="https://facebook.com/x"', $html);
    }

    public function test_the_grid_classes_follow_the_columns_and_an_empty_row_is_not_printed(): void
    {
        $this->publish($this->withRows([
            [$this->column([$this->block('text', ['body' => 'a'])], 2, 'start'), $this->column([$this->block('text', ['body' => 'b'])], 1, 'end'), $this->column([$this->block('spacer')])],
            [$this->column([$this->block('payments', ['methods' => []])])],
        ]));
        $html = $this->footerHtml($this->home());

        $this->assertSame(1, substr_count($html, 'class="sf-ft-row '), 'the second row has no output');
        $this->assertStringContainsString('sf-ft-row sf-ft-units-3', $html);
        $this->assertStringContainsString('sf-ft-col sf-ft-span-2 sf-ft-align-start', $html);
        $this->assertStringContainsString('sf-ft-col sf-ft-span-1 sf-ft-align-end', $html);
        $this->assertSame(2, substr_count($html, 'class="sf-ft-col '), 'a column with only a spacer is gone');
    }

    // ---- the old footer, the draft and the preview ----------------------------------------------------------------------------------

    public function test_with_nothing_published_the_old_footer_is_printed_and_a_published_layout_replaces_it(): void
    {
        $page = $this->page('returns', 'Returns');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('<footer class="sf-footer">', $html);
        $this->assertStringContainsString('sf-footer-name">Raf Shop', $html);
        $this->assertStringContainsString('href="/info/returns"', $html);
        $this->assertStringNotContainsString('sf-ft', $html);

        $this->publish($this->layout(fn (array $l): array => $l));
        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('<footer class="sf-footer sf-ft">', $html);
        $this->assertStringNotContainsString('sf-footer-inner', $html);
        $this->assertStringContainsString('href="/info/returns"', $html, 'the default layout shows the footer menu too');
    }

    public function test_a_draft_never_reaches_the_public_page_and_unpublishing_brings_back_the_old_footer(): void
    {
        $this->settings()->set(AppearanceSettings::FOOTER_DRAFT_KEY, (string) json_encode($this->withRows([[$this->column([$this->block('text', ['body' => 'DRAFT ONLY'])])]])));
        $this->fresh();

        $this->assertStringNotContainsString('DRAFT ONLY', $this->home());

        $this->publish($this->withRows([[$this->column([$this->block('text', ['body' => 'LIVE'])])]]));
        $this->assertStringContainsString('LIVE', $this->home());

        app(FooterCompiler::class)->unpublish($this->settings());
        $this->fresh();
        $html = $this->footerHtml($this->home());
        $this->assertStringNotContainsString('LIVE', $html);
        $this->assertStringContainsString('sf-footer-inner', $html);
    }

    public function test_the_preview_shows_the_draft_only_through_a_signed_unexpired_link(): void
    {
        $this->settings()->set(AppearanceSettings::FOOTER_DRAFT_KEY, (string) json_encode($this->withRows([[$this->column([$this->block('text', ['body' => 'DRAFT ONLY'])])]])));

        $this->get('/footer-preview')->assertNotFound();
        $this->get('/footer-preview?signature=bad')->assertNotFound();

        $url = FooterPreviewController::previewUrl();
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('DRAFT ONLY', $response->getContent());
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->fresh();
        Carbon::setTestNow(now()->addMinutes(FooterPreviewController::MINUTES + 1));
        $this->get($url)->assertNotFound();
    }

    // ---- colours, border, classes ---------------------------------------------------------------------------------------------------

    public function test_colours_and_the_border_reach_the_page_only_as_variables_of_the_token_stylesheet_never_as_style(): void
    {
        $layout = $this->withRows([[$this->column([$this->block('text', ['body' => 'x'])])]]);
        $layout = (function (array $l): array {
            $l['upper'] = ['bg' => '#112233', 'text' => '#eeeeee', 'border_color' => '#ff0000', 'border_width' => 3, 'padding' => 'spacious'] + $l['upper'];
            $l['lower'] = ['bg' => '#000000', 'text' => '#ffffff', 'padding' => 'normal', 'align' => 'end'] + $l['lower'];

            return $l;
        })($layout);
        $this->publish($layout);
        $html = $this->home();
        $footer = $this->footerHtml($html);

        $this->assertStringNotContainsString('style=', $footer);
        $this->assertStringNotContainsString('#112233', $footer);
        $this->assertStringContainsString('sf-ft-pad-spacious', $footer);
        $this->assertStringContainsString('sf-ft-pad-normal', $footer);
        $this->assertStringContainsString('sf-ft-line sf-ft-align-end', $footer);

        $this->assertSame(1, preg_match('#href="(/storage/appearance/tokens-[0-9a-f]{12}\.css)"#', $html, $m), 'the token stylesheet is linked');
        $css = Storage::disk('public')->get(ltrim(substr($m[1], strlen('/storage/')), '/'));

        foreach (['--sf-ftu-bg: #112233;', '--sf-ftu-text: #eeeeee;', '--sf-ftu-bc: #ff0000;', '--sf-ftu-bw: 3px;', '--sf-ftl-bg: #000000;', '--sf-ftl-text: #ffffff;'] as $declaration) {
            $this->assertStringContainsString($declaration, (string) $css);
        }
    }

    public function test_a_later_appearance_save_keeps_the_footer_variables_in_the_stylesheet(): void
    {
        $this->publish((function (array $l): array {
            $l['upper']['bg'] = '#112233';

            return $l;
        })($this->withRows([[$this->column([$this->block('text', ['body' => 'x'])])]])));
        $this->settings()->set('appearance.color.accent', '#00aa00');
        app(\App\Storefront\Header\AppearanceRecompiler::class)->run($this->settings());
        $this->fresh();

        $this->assertSame(1, preg_match('#href="(/storage/appearance/tokens-[0-9a-f]{12}\.css)"#', $this->home(), $m));
        $css = (string) Storage::disk('public')->get(ltrim(substr($m[1], strlen('/storage/')), '/'));
        $this->assertStringContainsString('--sf-ftu-bg: #112233;', $css);
        $this->assertStringContainsString('--sf-accent: #00aa00;', $css);
    }

    public function test_the_footer_adds_no_query_to_a_warm_page(): void
    {
        $page = $this->page('terms', 'Terms');
        $block = HtmlBlockModel::create(['title' => 'B', 'body' => '<p>hello</p>']);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);

        $count = function (): int {
            $this->get('/')->assertOk();
            $this->app->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->app->forgetScopedInstances();

            return $queries;
        };

        $without = $count();
        $this->publish($this->withRows([[
            $this->column([$this->block('menu', ['heading' => 'Help']), $this->block('html_block', ['html_block' => $block->id])]),
            $this->column([$this->block('payments', ['methods' => ['visa']]), $this->block('social', ['items' => [['network' => 'facebook', 'url' => 'https://facebook.com/x']]])]),
        ]]));

        $this->assertSame($without, $count(), 'menus, HTML blocks and everything else ride the cached appearance read');
    }

    // ---- recompile triggers ---------------------------------------------------------------------------------------------------------

    public function test_the_footer_follows_a_renamed_menu_item_an_unpublished_page_and_a_changed_html_block(): void
    {
        $page = $this->page('terms', 'Terms');
        $block = HtmlBlockModel::create(['title' => 'B', 'body' => '<p>first</p>']);
        $menu = app(MenuWriter::class)->create('legal', 'Legal');
        $items = [$this->menuItem('page', ['target_id' => $page->id]), $this->menuItem('link', ['label' => 'Old name', 'url' => '/x'])];
        app(MenuWriter::class)->save($menu, 'Legal', $items);
        $this->publish($this->withRows([[$this->column([$this->block('menu', ['menu' => $menu->id])]), $this->column([$this->block('html_block', ['html_block' => $block->id])])]]));
        $html = $this->footerHtml($this->home());
        $this->assertStringContainsString('Old name', $html);
        $this->assertStringContainsString('first', $html);

        app(MenuWriter::class)->save($menu, 'Legal', [$items[0], $this->menuItem('link', ['label' => 'New name', 'url' => '/x'])]);
        $this->fresh();
        $html = $this->footerHtml($this->home());
        $this->assertStringContainsString('New name', $html);
        $this->assertStringNotContainsString('Old name', $html);

        $page->status = PageModel::DRAFT;
        $page->save();
        $this->fresh();
        $this->assertStringNotContainsString('href="/info/terms"', $this->footerHtml($this->home()));

        $block->body = '<p>second</p>';
        $block->save();
        $this->fresh();
        $html = $this->footerHtml($this->home());
        $this->assertStringContainsString('second', $html);
        $this->assertStringNotContainsString('first', $html);
    }

    public function test_a_stored_layout_that_no_longer_validates_falls_back_to_the_old_footer(): void
    {
        $this->publish($this->withRows([[$this->column([$this->block('text', ['body' => 'x'])])]]));
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), true);
        $compiled['layout']['upper']['rows'][0]['columns'] = array_fill(0, 6, $compiled['layout']['upper']['rows'][0]['columns'][0]);
        $this->settings()->set(AppearanceSettings::FOOTER_COMPILED_KEY, (string) json_encode($compiled));
        $this->fresh();

        $html = $this->footerHtml($this->home());

        $this->assertStringContainsString('sf-footer-inner', $html);
        $this->assertStringNotContainsString('sf-ft-', $html);
    }
}
