<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockCard;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Footer\FooterCompiler;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Media\BuildsImageFixtures;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HB-5: the «Снимка с текст» kind of HTML block: the validator (closed lists, lengths, colours, links, the alt rule), the markup the renderer builds from the validated values, the narrow card filter of the
 * sanitiser, the places that print blocks, the picture in the token stylesheet (and its recompile when the asset becomes ready) and the promise that a text block is untouched.
 */
class HtmlBlockCardTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use MakesPickerAssets;
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
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'categories' => [1], 'price' => '100.00', 'stock' => 4]);
    }

    /** @param  array<string, mixed>  $over  @return array<string, mixed> */
    private function card(array $over = []): array
    {
        return $over + ['picture' => $this->pickerAsset()->id, 'title' => 'Spring', 'subtitle' => 'New in', 'text' => "Line one\nLine two", 'link' => '/product-category/clothing', 'alt' => ''];
    }

    private function make(array $over = [], string $title = 'Card'): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $title, 'kind' => 'card', 'card' => $this->card($over)]);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function html(HtmlBlockModel $block): string
    {
        return HtmlBlockModel::renderMany([(int) $block->id])[(int) $block->id] ?? '';
    }

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): array
    {
        $base = ['picture' => 1, 'title' => 'T'];

        return [
            'size' => [$base + ['title_size' => 'xxl'], 'title_size'],
            'halign' => [$base + ['halign' => 'justify'], 'halign'],
            'valign' => [$base + ['valign' => 'baseline'], 'valign'],
            'overlay' => [$base + ['overlay' => 'heavy'], 'overlay'],
            'height' => [$base + ['height' => 300], 'height'],
            'height text' => [$base + ['height' => '320px'], 'height'],
            'text colour' => [$base + ['text_color' => 'red'], 'text_color'],
            'border width' => [$base + ['border_w' => 3], 'border_w'],
            'border colour' => [$base + ['border_color' => 'pink'], 'border_color'],
            'radius' => [$base + ['radius' => 12], 'radius'],
            'title 81' => [['picture' => 1, 'title' => str_repeat('a', 81)], 'title'],
            'subtitle 121' => [$base + ['subtitle' => str_repeat('a', 121)], 'subtitle'],
            'text 301' => [$base + ['text' => str_repeat('a', 301)], 'text'],
            'alt 121' => [$base + ['alt' => str_repeat('a', 121)], 'alt'],
            'hex name' => [$base + ['text_color' => 'custom', 'text_custom' => 'red'], 'text_custom'],
            'hex short' => [$base + ['text_color' => 'custom', 'text_custom' => '#12'], 'text_custom'],
            'hex url' => [$base + ['text_color' => 'custom', 'text_custom' => 'url(x)'], 'text_custom'],
            'hex letters' => [$base + ['border_color' => 'custom', 'border_custom' => '#ggg'], 'border_custom'],
            'custom with no colour' => [$base + ['text_color' => 'custom'], 'text_custom'],
            'javascript link' => [$base + ['link' => 'javascript:alert(1)'], 'link'],
            'protocol-relative link' => [$base + ['link' => '//evil.test/x'], 'link'],
            'data link' => [$base + ['link' => 'data:text/html,x'], 'link'],
            'http link' => [$base + ['link' => 'http://evil.test'], 'link'],
            'picture only needs alt' => [['picture' => 1], 'alt'],
            'no picture' => [['title' => 'T'], 'picture'],
            'bad picture' => [['picture' => 'abc', 'title' => 'T'], 'picture'],
            'unknown key' => [$base + ['style' => 'x'], 'card'],
            'array as a list value' => [$base + ['title_size' => ['l']], 'title_size'],
        ];
    }

    /** @param array<string, mixed> $card */
    #[DataProvider('refused')]
    public function test_a_card_that_is_not_valid_is_refused_on_save_and_prints_nothing(array $card, string $key): void
    {
        [, $errors] = HtmlBlockCard::validate($card);

        $this->assertArrayHasKey($key, $errors, json_encode($errors));
        $this->expectException(InvalidArgumentException::class);
        HtmlBlockModel::query()->create(['title' => 'Bad', 'kind' => 'card', 'card' => $card]);
    }

    public function test_the_values_the_form_allows_are_accepted_and_a_valid_card_gets_every_default(): void
    {
        $asset = $this->pickerAsset();
        [$clean, $errors] = HtmlBlockCard::validate([
            'picture' => (string) $asset->id, 'title' => ' Hi ', 'title_size' => 'xl', 'halign' => 'left', 'valign' => 'bottom', 'overlay' => 'strong', 'height' => '400',
            'text_color' => 'custom', 'text_custom' => '#ABCDEF', 'border_w' => '4', 'border_color' => 'custom', 'border_custom' => '#112233', 'radius' => '24', 'link' => 'https://example.com/a',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('Hi', $clean['title']);
        $this->assertSame(400, $clean['height']);
        $this->assertSame('#abcdef', $clean['text_custom'], 'lower case');
        $this->assertSame([], array_diff(array_keys(HtmlBlockCard::DEFAULTS), array_keys($clean)));

        [$defaults] = HtmlBlockCard::validate(['picture' => $asset->id, 'title' => 'T']);
        $this->assertSame(['l', 'center', 'middle', 'medium', 320, 'light', 0, 'dark', 0], [$defaults['title_size'], $defaults['halign'], $defaults['valign'], $defaults['overlay'], $defaults['height'], $defaults['text_color'], $defaults['border_w'], $defaults['border_color'], $defaults['radius']]);
    }

    public function test_an_unknown_kind_is_refused_and_the_kind_never_changes_after_creation(): void
    {
        try {
            HtmlBlockModel::query()->create(['title' => 'X', 'kind' => 'video', 'body' => '<p>x</p>']);
            $this->fail('an unknown kind must be refused');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $block = $this->make();
        $block->kind = 'text';
        $block->save();
        $this->assertSame('card', $block->fresh()->kind, 'a change of the kind is ignored');

        $text = HtmlBlockModel::query()->create(['title' => 'T', 'body' => '<p>x</p>']);
        $this->assertSame('text', $text->fresh()->kind, 'the default is `text`');
        $text->kind = 'card';
        $text->card = $this->card();
        $text->save();
        $this->assertSame('text', $text->fresh()->kind);
        $this->assertNull($text->fresh()->card);
    }

    public function test_a_hand_edited_row_that_does_not_validate_prints_nothing(): void
    {
        $block = $this->make();
        DB::table('html_blocks')->where('id', $block->id)->update(['card' => json_encode(['picture' => 1, 'title' => 'x', 'height' => 999])]);

        $this->assertSame('', $this->html($block));
        DB::table('html_blocks')->where('id', $block->id)->update(['card' => json_encode('a string')]);
        $this->assertSame('', $this->html($block));
        DB::table('html_blocks')->where('id', $block->id)->update(['card' => null]);
        $this->assertSame('', $this->html($block));
    }

    // ---- ★ markup -------------------------------------------------------------------------------------------------------------------------

    public function test_with_a_link_the_whole_block_is_inside_one_link_and_without_it_there_is_no_link(): void
    {
        $linked = $this->html($this->make());
        $plain = $this->html($this->make(['link' => '']));

        $this->assertSame(1, substr_count($linked, '<a '));
        $this->assertMatchesRegularExpression('#^<div class="sf-hb sf-hb-\d+ sf-pc [^"]*"><a class="sf-pc-link" href="/product-category/clothing" rel="noopener nofollow"><div class="sf-pc-text">.*</div></a></div>$#', $linked);
        $this->assertStringNotContainsString('<a ', $plain);
        $this->assertStringNotContainsString('role=', $plain, 'with text the picture is decorative');
    }

    public function test_a_picture_only_block_is_an_image_with_its_alt_as_the_name(): void
    {
        $plain = $this->html($this->make(['title' => '', 'subtitle' => '', 'text' => '', 'link' => '', 'alt' => 'Spring collection']));
        $linked = $this->html($this->make(['title' => '', 'subtitle' => '', 'text' => '', 'link' => '/x', 'alt' => 'Spring collection']));

        $this->assertStringContainsString('role="img" aria-label="Spring collection"', $plain);
        $this->assertStringNotContainsString('sf-pc-text', $plain);
        $this->assertStringContainsString('<a class="sf-pc-link" href="/x" aria-label="Spring collection" rel="noopener nofollow"></a>', $linked, 'inside a link the link is named by the alt');
        $this->assertStringNotContainsString('role=', $linked);
    }

    public function test_the_title_the_subtitle_and_the_text_are_divs_never_headings_and_the_text_is_escaped(): void
    {
        $html = $this->html($this->make(['title' => '<script>alert(1)</script> "Q" & co', 'subtitle' => "It's <b>b</b>", 'text' => "a < b\r\nsecond & <i>third</i>", 'link' => '']));

        $this->assertDoesNotMatchRegularExpression('#<h[1-6]#i', $html);
        $this->assertStringContainsString('<div class="sf-pc-title">&lt;script&gt;alert(1)&lt;/script&gt; "Q" &amp; co</div>', $html);
        $this->assertStringContainsString('<div class="sf-pc-sub">It\'s &lt;b&gt;b&lt;/b&gt;</div>', $html);
        $this->assertStringContainsString('<div class="sf-pc-par">a &lt; b<br>second &amp; &lt;i&gt;third&lt;/i&gt;</div>', $html, 'a line break is <br> and nothing else');
        $this->assertSame(1, substr_count($html, '<br>'));
        $this->assertStringNotContainsString('<script', $html);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function choices(): array
    {
        return [
            'size s' => [['title_size' => 's'], ['sf-pc-t-s']], 'size m' => [['title_size' => 'm'], ['sf-pc-t-m']], 'size xl' => [['title_size' => 'xl'], ['sf-pc-t-xl']],
            'left top' => [['halign' => 'left', 'valign' => 'top'], ['sf-pc-ha-left', 'sf-pc-va-top']], 'right bottom' => [['halign' => 'right', 'valign' => 'bottom'], ['sf-pc-ha-right', 'sf-pc-va-bottom']],
            'veil' => [['overlay' => 'none'], ['sf-pc-o-none']], 'strong' => [['overlay' => 'strong'], ['sf-pc-o-strong']],
            'height' => [['height' => 160], ['sf-pc-ht-160']], 'height 400' => [['height' => '400'], ['sf-pc-ht-400']],
            'border' => [['border_w' => 2, 'border_color' => 'accent'], ['sf-pc-bw-2', 'sf-pc-bc-accent']], 'radius' => [['radius' => 16], ['sf-pc-r-16']],
            'dark text' => [['text_color' => 'dark'], ['sf-pc-c-dark']],
        ];
    }

    /** @param array<string, mixed> $choice @param list<string> $classes */
    #[DataProvider('choices')]
    public function test_every_choice_prints_its_own_class_and_nothing_else(array $choice, array $classes): void
    {
        $block = $this->make($choice);
        $html = $this->html($block);
        preg_match('#^<div class="([^"]+)"#', $html, $m);
        $tokens = explode(' ', $m[1]);

        foreach ($classes as $class) {
            $this->assertContains($class, $tokens);
        }

        // one class per kind of choice: sf-hb, sf-hb-N, sf-pc and the nine choices
        $this->assertCount(12, $tokens);
        $this->assertStringNotContainsString(' style=', $html);
    }

    public function test_custom_colours_reach_the_page_only_through_the_validated_rule_of_the_stylesheet(): void
    {
        $block = $this->make(['text_color' => 'custom', 'text_custom' => '#AA00BB', 'border_w' => 2, 'border_color' => 'custom', 'border_custom' => '#00ccdd']);
        $html = $this->html($block);

        $this->assertStringContainsString('sf-pc-c-custom', $html);
        $this->assertStringContainsString('sf-pc-bc-custom', $html);
        $this->assertStringNotContainsString('#aa00bb', $html);
        $this->assertStringNotContainsString(' style=', $html);

        $rule = collect(HtmlBlockStyle::storedRules())->first(fn (string $r): bool => str_starts_with($r, ".sf-hb-{$block->id}{"));
        $this->assertNotNull($rule);
        $this->assertStringContainsString('color:#aa00bb', $rule);
        $this->assertStringContainsString('border-color:#00ccdd', $rule);
        $this->assertStringContainsString("url(/storage/", $rule);
        $this->assertTrue(HtmlBlockStyle::isValidRule($rule));
        $this->assertFalse(HtmlBlockStyle::isValidRule(".sf-hb-1{border-color:red}"));
        $this->assertFalse(HtmlBlockStyle::isValidRule(".sf-hb-1{border-color:#12345g}"));
    }

    // ---- ★ the sanitiser ------------------------------------------------------------------------------------------------------------------

    public function test_the_card_markup_survives_a_second_cleaning_unchanged(): void
    {
        foreach ([$this->make(), $this->make(['link' => '']), $this->make(['title' => '', 'subtitle' => '', 'text' => '', 'alt' => 'x'])] as $block) {
            $html = $this->html($block);

            $this->assertNotSame('', $html);
            $this->assertSame($html, HtmlBlockSanitizer::render($html), 'idempotent');
        }
    }

    public function test_the_card_filter_strips_scripts_events_style_other_roles_and_foreign_classes(): void
    {
        $evil = '<div class="sf-hb sf-hb-5 sf-pc evil sf-pc-x" style="x:y" onclick="a()" id="i" role="button" data-a="1" aria-hidden="false">'
            .'<script>alert(1)</script><a class="sf-pc-link bad" href="javascript:alert(1)" onmouseover="b()" target="_blank" aria-label="lab"><div class="sf-pc-text" style="color:red">t<img src="/storage/x.png"></div></a></div>';
        $out = HtmlBlockSanitizer::render($evil);

        $this->assertSame('<div class="sf-hb sf-hb-5 sf-pc sf-pc-x"><a class="sf-pc-link" aria-label="lab" rel="noopener nofollow"><div class="sf-pc-text">t</div></a></div>', $out);
        $this->assertSame('<div class="sf-hb sf-hb-5 sf-pc" role="img" aria-label="x" aria-hidden="true"></div>', HtmlBlockSanitizer::render('<div class="sf-hb sf-hb-5 sf-pc" role="img" aria-label="x" aria-hidden="true"></div>'));
    }

    public function test_every_other_block_keeps_the_filter_it_always_had(): void
    {
        $this->assertSame('<p>x</p>', HtmlBlockSanitizer::render('<div class="sf-pc"><p>x</p></div>'), 'a div and a class in a text block are still removed');
        $this->assertSame('<p>x</p>', HtmlBlockSanitizer::render('<div class="sf-hb sf-hb-7 evil"><p>x</p></div>'));
        $this->assertSame('<p>x</p>', HtmlBlockSanitizer::clean('<p role="img" class="sf-x">x</p>'));
        $this->assertSame('x', HtmlBlockSanitizer::clean('<div role="img" class="sf-pc">x</div>'));
    }

    // ---- ★ old blocks ---------------------------------------------------------------------------------------------------------------------

    public function test_old_text_blocks_print_byte_identically_and_need_no_migration_step(): void
    {
        $plain = HtmlBlockModel::query()->create(['title' => 'Plain', 'body' => '<p>Hello <b>there</b></p><h2>Head</h2>']);
        $styled = HtmlBlockModel::query()->create(['title' => 'Styled', 'body' => '<p>Promo</p>', 'style' => ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'align' => 'center']]);
        $asset = $this->pickerAsset();
        $pictured = HtmlBlockModel::query()->create(['title' => 'Pics', 'body' => '<p>Look</p>', 'pictures' => [['media_asset_id' => $asset->id, 'alt' => 'A', 'url' => '/x']], 'style' => ['bg_image' => $asset->id, 'overlay' => 40, 'min_height' => 240]]);

        $this->assertSame('<p>Hello <b>there</b></p><h2>Head</h2>', $this->html($plain));
        $this->assertSame('<div class="sf-hb sf-hb-'.$styled->id.'"><p>Promo</p></div>', $this->html($styled));
        $this->assertStringStartsWith('<div class="sf-hb sf-hb-'.$pictured->id.'"><figure><a href="/x" rel="noopener nofollow"><img loading="lazy" decoding="async" src="', $this->html($pictured));

        // the rows are as they were stored: kind `text`, no card, nothing else touched
        foreach ([$plain, $styled, $pictured] as $block) {
            $row = DB::table('html_blocks')->where('id', $block->id)->first();
            $this->assertSame('text', $row->kind);
            $this->assertNull($row->card);
        }

        // a row written BEFORE the columns existed (no kind, no card in the query) still prints: the renderer works from what the table has
        $this->assertSame('<p>Hello <b>there</b></p><h2>Head</h2>', $this->html($plain));
    }

    public function test_the_migration_is_additive_and_reversible(): void
    {
        $migration = require base_path('database/migrations/2026_11_03_000001_add_kind_and_card_to_html_blocks.php');
        $text = HtmlBlockModel::query()->create(['title' => 'Keep', 'body' => '<p>keep me</p>']);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('html_blocks', 'kind'));
        $this->assertFalse(Schema::hasColumn('html_blocks', 'card'));
        $this->assertSame('<p>keep me</p>', DB::table('html_blocks')->where('id', $text->id)->value('body'), 'nothing else is touched');

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('html_blocks', 'kind') && Schema::hasColumn('html_blocks', 'card'), 'idempotent');
        $this->assertSame('text', DB::table('html_blocks')->where('id', $text->id)->value('kind'), 'an old row is a text block');
    }

    // ---- ★ places -------------------------------------------------------------------------------------------------------------------------

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    public function test_the_card_prints_in_the_home_section_the_footer_the_mega_menu_and_the_category_banner(): void
    {
        $block = $this->make(['title' => 'Everywhere']);
        $own = $this->html($block);

        [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]);
        HomeDrafts::publish($clean);
        $layout = FooterLayout::default();
        $layout['upper']['rows'] = [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]]]];
        app(FooterCompiler::class)->publish($layout, $this->settings());
        $this->saveMenu('main', [$this->megaItem('Dresses', '/dresses', 1, [$this->columnItem([$this->menuItem('link', ['label' => 'Maxi', 'url' => '/maxi'])])], ['html_block_id' => $block->id])]);

        $home = $this->home();
        $this->assertStringContainsString('sf-hp-html-block', $home);
        $this->assertStringContainsString($own, $home, 'the home section');
        $this->assertStringContainsString('<div class="sf-mega-media sf-mega-html">'.$own.'</div>', $home, 'the mega menu');
        $this->assertStringContainsString($own, substr($home, (int) strpos($home, '<footer')), 'the footer');

        DB::table('catalog_categories')->where('id', 1)->update(['header_block_id' => $block->id]);
        $this->app->forgetScopedInstances();
        $category = $this->get('/product-category/clothing')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="sf-cat-banner">'.$own.'</div>', $category);
        $this->assertSame(1, substr_count($category, '<h1'), 'the banner adds no heading: the category keeps its own h1');

        foreach ([$home, $category] as $page) {
            $this->assertStringNotContainsString(' style=', $page);
            $this->assertStringNotContainsString('<script>', $page);
        }
    }

    public function test_the_compiled_copies_are_cleaned_again_on_read_and_a_card_costs_no_extra_query(): void
    {
        $text = HtmlBlockModel::query()->create(['title' => 'T', 'body' => '<p>text</p>']);
        $card = $this->make();
        $count = function (HtmlBlockModel $block): int {
            [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]);
            HomeDrafts::publish($clean);
            $this->home();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->home();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($text), $count($card), 'a card block costs the home page no query more than a text block');

        // a hand-edited compiled copy cannot bring markup in
        $json = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $json['zones']['home']['global']['blocks'][(string) $card->id] = '<div class="sf-hb sf-hb-'.$card->id.' sf-pc" onclick="x()"><script>alert(1)</script><div class="sf-pc-text" style="x:y">kept</div></div>';
        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($json));
        app(\App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
        $page = $this->home();

        $this->assertStringContainsString('<div class="sf-pc-text">kept</div>', $page);
        $this->assertStringNotContainsString('onclick', $page);
        $this->assertStringNotContainsString('<script>alert', $page);
    }

    public function test_a_block_the_home_layout_shows_still_cannot_be_deleted_and_the_picture_is_reported_as_used(): void
    {
        $block = $this->make();
        [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]);
        HomeDrafts::publish($clean);

        $this->assertFalse($block->delete());
        $this->assertNotNull(HtmlBlockModel::query()->find($block->id));

        $registry = app(\App\Media\Usage\MediaUsageRegistry::class);
        $id = (int) $block->card['picture'];
        $this->assertSame([$id => 1], $registry->counts([$id]), 'the media library knows the card uses its picture');
        $this->assertCount(1, $registry->usagesOf($id));
    }

    public function test_the_page_is_the_same_for_every_visitor_and_has_no_inline_script(): void
    {
        $block = $this->make();
        [$clean] = HomeValidator::validate(['spacing' => '32', 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $block->id]]]]);
        HomeDrafts::publish($clean);

        $first = $this->home();
        $this->flushSession();
        $second = $this->withHeader('Cookie', 'x=1')->get('/')->assertOk()->getContent();
        $this->assertSame(preg_replace('/csrf-token" content="[^"]*"/', '', $first), preg_replace('/csrf-token" content="[^"]*"/', '', $second));
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $first, 'no inline script');
    }

    // ---- ★ the picture and the ready event ------------------------------------------------------------------------------------------------

    public function test_a_card_compiled_while_its_picture_is_processing_is_recompiled_when_the_asset_is_ready(): void
    {
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $uuid = uniqid('', true);
        $path = "uploads/2026/11/{$uuid}.jpg";
        Storage::disk('public')->put($path, $this->jpeg());
        $asset = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => $path, 'processing_status' => 'pending', 'variants' => [], 'original_name' => "{$uuid}.jpg"]);
        $block = $this->make(['picture' => $asset->id]);

        HtmlBlockStyle::rebuildStylesheet();
        $rule = fn (): string => (string) collect(HtmlBlockStyle::storedRules())->first(fn (string $r): bool => str_starts_with($r, ".sf-hb-{$block->id}{"));
        $this->assertStringContainsString($asset->path, $rule(), 'processing: the original file');

        (new ProcessMediaAssetJob((string) $asset->id))->handle(app(MediaAssetRepository::class), app(MediaImageProcessor::class), app(MediaStorageAdapter::class));

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);
        $css = (string) Storage::disk('public')->get('appearance/tokens-'.($compiled['hash'] ?? 'none').'.css');
        $this->assertStringContainsString('.webp', $rule());
        $this->assertStringNotContainsString($asset->path, $rule());
        $this->assertStringContainsString('.webp', $css, 'the stored stylesheet follows, with nobody saving the block');
    }

    public function test_a_style_only_save_of_a_card_picture_rebuilds_the_stylesheet_and_deleting_it_removes_the_rule(): void
    {
        $block = $this->make();
        $has = fn (): bool => collect(HtmlBlockStyle::storedRules())->contains(fn (string $r): bool => str_starts_with($r, ".sf-hb-{$block->id}{"));
        $stored = fn (): string => (string) Storage::disk('public')->get('appearance/tokens-'.(json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true)['hash'] ?? 'none').'.css');

        $this->assertTrue($has());
        $this->assertStringContainsString(".sf-hb-{$block->id}{", $stored());

        $block->delete();
        $this->assertFalse($has());
        $this->assertStringNotContainsString(".sf-hb-{$block->id}{", $stored());
    }
}
