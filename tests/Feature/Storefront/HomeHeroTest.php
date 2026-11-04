<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockUsage;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Home\HeroGeometry;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HP-6: the «Hero слайдер» section of the home page: its closed lists and the slide list (validated on save, publish and read; at most one in a layout), the markup (a region with an accessible name, `ul > li`
 * slides labelled «i / N», the desktop block and, only when set, the phone block, the closed-list classes, the pause control only when it animates, no style, no script), the data (the compiled blocks are cleaned
 * again on read, a block a layout shows cannot be deleted, a draft reaches the shop only through the signed preview) and the pinned query counts (a hero costs no query).
 */
class HomeHeroTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use MakesPickerAssets;
    use RefreshDatabase;

    private const WITHOUT_HERO = 2;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
    }

    /** @param  list<array<string, mixed>>  $slides @param  array<string, mixed>  $settings */
    private function hero(array $slides, array $settings = []): array
    {
        return ['type' => 'hero', 'settings' => ['slides' => $slides] + $settings];
    }

    /** @return array{block: int, block_phone: int|null} */
    private function slide(HtmlBlockModel|int $block, HtmlBlockModel|int|null $phone = null): array
    {
        return ['block' => $block instanceof HtmlBlockModel ? $block->id : $block, 'block_phone' => $phone instanceof HtmlBlockModel ? $phone->id : $phone];
    }

    private function block(string $text = 'Cell'): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $text, 'body' => "<p>{$text}</p>"]);
    }

    private function card(string $title): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $title, 'kind' => 'card', 'card' => ['picture' => $this->pickerAsset()->id, 'title' => $title, 'subtitle' => '', 'text' => '', 'link' => '/product-category/x', 'alt' => '']]);
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

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $sections): array
    {
        return HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
    }

    // ---- ★ validation ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string}> */
    public static function refused(): array
    {
        $h = static fn (array $settings = [], array $slides = [['block' => 1, 'block_phone' => null]]): array => ['type' => 'hero', 'settings' => ['slides' => $slides] + $settings];
        $seven = array_fill(0, 7, ['block' => 1, 'block_phone' => null]);

        return [
            'seconds 7' => [[$h(['seconds' => '7'])], 'sections.0.settings.seconds'],
            'seconds with a unit' => [[$h(['seconds' => '12s'])], 'sections.0.settings.seconds'],
            'seconds 0' => [[$h(['seconds' => '0'])], 'sections.0.settings.seconds'],
            'seconds an int' => [[$h(['seconds' => 5])], 'sections.0.settings.seconds'],
            'seconds an array' => [[$h(['seconds' => ['5']])], 'sections.0.settings.seconds'],
            'ratio outside the list' => [[$h(['ratio' => '5:1'])], 'sections.0.settings.ratio'],
            'ratio an array' => [[$h(['ratio' => ['21:9']])], 'sections.0.settings.ratio'],
            'a phone ratio as the desktop one' => [[$h(['ratio' => '4:5'])], 'sections.0.settings.ratio'],
            'phone ratio outside the list' => [[$h(['ratio_phone' => '5:4'])], 'sections.0.settings.ratio_phone'],
            'a desktop ratio as the phone one' => [[$h(['ratio_phone' => '21:9'])], 'sections.0.settings.ratio_phone'],
            'phone ratio an array' => [[$h(['ratio_phone' => ['4:5']])], 'sections.0.settings.ratio_phone'],
            'autoplay a word' => [[$h(['autoplay' => 'yes'])], 'sections.0.settings.autoplay'],
            'autoplay an array' => [[$h(['autoplay' => [true]])], 'sections.0.settings.autoplay'],
            'an unknown setting' => [[$h(['delay' => '5'])], 'sections.0.settings.delay'],
            'no slide' => [[$h([], [])], 'sections.0.settings.slides'],
            'seven slides' => [[$h([], $seven)], 'sections.0.settings.slides'],
            'slides that are not a list' => [[$h([], ['a' => ['block' => 1]])], 'sections.0.settings.slides'],
            'a slide that is not an array' => [[$h([], [5])], 'sections.0.settings.slides.0'],
            'a slide without a block' => [[$h([], [['block' => null, 'block_phone' => null]])], 'sections.0.settings.slides.0.block'],
            'a slide block 0' => [[$h([], [['block' => 0, 'block_phone' => null]])], 'sections.0.settings.slides.0.block'],
            'a slide block a word' => [[$h([], [['block' => 'x', 'block_phone' => null]])], 'sections.0.settings.slides.0.block'],
            'a slide block an array' => [[$h([], [['block' => [1], 'block_phone' => null]])], 'sections.0.settings.slides.0.block'],
            'a phone block a word' => [[$h([], [['block' => 1, 'block_phone' => 'x']])], 'sections.0.settings.slides.0.block_phone'],
            'a phone block 0' => [[$h([], [['block' => 1, 'block_phone' => 0]])], 'sections.0.settings.slides.0.block_phone'],
            'a slide with an unknown key' => [[$h([], [['block' => 1, 'block_phone' => null, 'link' => '/x']])], 'sections.0.settings.slides.0'],
            'a second hero' => [[$h(), $h()], 'sections.1'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_bad_value_is_refused_on_save_and_on_publish(array $sections, string $key): void
    {
        [, $errors] = $this->validate($sections);
        $this->assertArrayHasKey($key, $errors, json_encode($errors));

        $this->expectException(\InvalidArgumentException::class);
        app(HomeCompiler::class)->publish(['spacing' => '32', 'sections' => $sections], app(SiteSettingsRepository::class));
    }

    public function test_the_defaults_and_a_valid_hero_with_one_to_six_slides(): void
    {
        [$clean, $errors] = $this->validate([$this->hero([$this->slide(7)])]);
        $s = $clean['sections'][0]['settings'];

        $this->assertSame([], $errors);
        $this->assertSame([true, '5', '21:9', '4:5', [['block' => 7, 'block_phone' => null]]], [$s['autoplay'], $s['seconds'], $s['ratio'], $s['ratio_phone'], $s['slides']]);

        foreach (range(1, 6) as $n) {
            $this->assertSame([], $this->validate([$this->hero(array_map(fn (int $i): array => $this->slide($i, $i + 100), range(1, $n)))])[1], "{$n} slides");
        }

        foreach (HeroGeometry::SECONDS as $seconds) {
            $this->assertSame([], $this->validate([$this->hero([$this->slide(1)], ['seconds' => $seconds])])[1], "seconds {$seconds}");
        }

        foreach (HeroGeometry::RATIOS as $ratio) {
            $this->assertSame([], $this->validate([$this->hero([$this->slide(1)], ['ratio' => $ratio])])[1], $ratio);
        }

        foreach (HeroGeometry::PHONE_RATIOS as $ratio) {
            $this->assertSame([], $this->validate([$this->hero([$this->slide(1)], ['ratio_phone' => $ratio])])[1], $ratio);
        }

        // a block id as a numeric string (a form) is the number; the slide keeps its order
        [$clean] = $this->validate([$this->hero([['block' => '3', 'block_phone' => '9'], $this->slide(2)])]);
        $this->assertSame([['block' => 3, 'block_phone' => 9], ['block' => 2, 'block_phone' => null]], $clean['sections'][0]['settings']['slides']);
    }

    public function test_the_seconds_are_their_own_setting_and_the_common_show_still_hides_the_section(): void
    {
        [$clean, $errors] = $this->validate([$this->hero([$this->slide(1)], ['show' => false, 'seconds' => '8'])]);

        $this->assertSame([], $errors);
        $this->assertSame([false, '8'], [$clean['sections'][0]['settings']['show'], $clean['sections'][0]['settings']['seconds']]);
        $this->assertSame(1, HomeCatalog::MAX_HEROES);
        $this->assertSame(6, HeroGeometry::SLIDES_MAX);
    }

    public function test_on_read_a_bad_value_is_its_default_a_bad_slide_is_dropped_and_the_second_hero_is_dropped(): void
    {
        $a = $this->block();
        $layout = ['spacing' => '32', 'sections' => [
            $this->hero([$this->slide($a), ['block' => 'x', 'block_phone' => null], ['block' => 5, 'block_phone' => 'y'], $this->slide(6, 7)], ['seconds' => '99', 'ratio' => 'x', 'ratio_phone' => '21:9', 'autoplay' => 'maybe']),
            $this->hero([$this->slide($a)]),
        ]];

        [$clean, $errors] = HomeValidator::validateForRead($layout);

        $this->assertSame([], $errors, 'nothing makes the layout unusable on read');
        $this->assertCount(1, $clean['sections'], 'the second hero is dropped');
        $s = $clean['sections'][0]['settings'];
        $this->assertSame(['5', '21:9', '4:5'], [$s['seconds'], $s['ratio'], $s['ratio_phone']], 'a bad list value is its default');
        $this->assertSame([['block' => $a->id, 'block_phone' => null], ['block' => 6, 'block_phone' => 7]], $s['slides'], 'a slide that does not validate is dropped');
    }

    public function test_a_block_that_does_not_exist_is_not_refused_on_save_but_drops_its_slide_when_the_page_is_printed(): void
    {
        $a = $this->card('Alive');
        $gone = 987654;

        // saving only checks that it is an id (this class asks no database; the admin's selector offers existing blocks only), exactly as the image grid's cells and the carousel's blocks do
        $this->assertSame([], $this->validate([$this->hero([$this->slide($a), $this->slide($gone, $gone + 1)])])[1]);
        $this->publish([$this->hero([$this->slide($a), $this->slide($gone, $gone + 1), $this->slide($a, $gone)])]);

        $page = $this->home();

        $this->assertStringContainsString('sf-hs-n-2', $page, 'the slide whose desktop block is gone is dropped: the count is the slides printed');
        $this->assertSame(2, substr_count($page, 'class="sf-hs-slide sf-car-item'));
        $this->assertStringContainsString('aria-label="2 / 2"', $page);
        $this->assertSame(0, substr_count($page, 'sf-hs-m"'), 'a phone block that is gone is none: the desktop block shows at every size');
    }

    public function test_a_hero_with_no_slide_left_prints_nothing_and_the_other_sections_stay(): void
    {
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Still here']], $this->hero([$this->slide(987654)])]);

        $page = $this->home();

        $this->assertStringContainsString('Still here', $page);
        $this->assertStringNotContainsString('sf-hs', $page);
        $this->assertStringNotContainsString('sf-hp-hero', $page, 'no empty box');
    }

    // ---- ★ markup -------------------------------------------------------------------------------------------------------------------------

    public function test_the_partial_prints_a_region_a_focusable_track_a_list_of_labelled_slides_and_the_blocks(): void
    {
        $one = $this->card('Desktop one');
        $two = $this->block('Text two');
        $phone = $this->card('Phone one');
        $this->publish([$this->hero([$this->slide($one, $phone), $this->slide($two), $this->slide($one)])]);

        $page = $this->home();

        $this->assertMatchesRegularExpression('/<section class="sf-hs sf-hs-auto sf-hs-n-3 sf-hs-t-5 sf-hs-r-21-9 sf-hs-rp-4-5" role="region" aria-roledescription="carousel" aria-label="Hero slider" data-carousel>/', $page);
        $this->assertStringContainsString('<ul class="sf-hs-track sf-car-track" tabindex="0">', $page);
        $this->assertSame(3, substr_count($page, '<li class="sf-hs-slide'));
        foreach (['1 / 3', '2 / 3', '3 / 3'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $page);
        }
        $this->assertSame(3, substr_count($page, 'class="sf-hs-d"'));
        $this->assertSame(1, substr_count($page, 'class="sf-hs-m"'), 'a phone block only where it is set');
        $this->assertSame(1, substr_count($page, 'sf-hs-slide-p'), 'and only that slide is marked as having one');
        $this->assertStringContainsString('<p>Text two</p>', $page);
        $this->assertStringContainsString('Desktop one', $page);
        $this->assertStringContainsString('Phone one', $page);
        $this->assertMatchesRegularExpression('/<li class="sf-hs-slide sf-car-item sf-hs-slide-p" aria-label="1 \/ 3">\s*<div class="sf-hs-d">/', $page);
    }

    public function test_the_slides_keep_their_order(): void
    {
        $a = $this->block('Alpha');
        $b = $this->block('Bravo');
        $c = $this->block('Charlie');
        $this->publish([$this->hero([$this->slide($c), $this->slide($a), $this->slide($b)])]);

        $page = $this->home();

        $this->assertLessThan(strpos($page, 'Alpha'), strpos($page, 'Charlie'));
        $this->assertLessThan(strpos($page, 'Bravo'), strpos($page, 'Alpha'));
    }

    public function test_the_classes_match_the_settings_and_the_page_is_identical_for_every_visitor(): void
    {
        $a = $this->block();
        $this->publish([$this->hero([$this->slide($a), $this->slide($a)], ['seconds' => '8', 'ratio' => '16:9', 'ratio_phone' => '3:4'])]);

        $page = $this->home();

        $this->assertStringContainsString('class="sf-hs sf-hs-auto sf-hs-n-2 sf-hs-t-8 sf-hs-r-16-9 sf-hs-rp-3-4"', $page);
        $this->assertDoesNotMatchRegularExpression('/<section class="sf-hs[^>]*style=/', $page);
        $this->assertSame($page, $this->home(), 'the same HTML for every visitor');
        $this->assertSame($page, $this->withHeaders(['Cookie' => 'x=1'])->get('/')->getContent());
    }

    public function test_the_pause_control_is_printed_only_when_it_animates(): void
    {
        $a = $this->block();

        // autoplay on, two slides: the checkbox, its label with both names, and the arrows (hidden: the manual row of a visitor who asked for less motion)
        $this->publish([$this->hero([$this->slide($a), $this->slide($a)])]);
        $page = $this->home();
        $this->assertStringContainsString('<input type="checkbox" class="sf-hs-toggle" id="sf-hs-toggle">', $page);
        $this->assertMatchesRegularExpression('/<label class="sf-car-arrow sf-hs-pause" for="sf-hs-toggle"><span class="sf-hs-pause-text sf-sr">Pause<\/span><span class="sf-hs-play-text sf-sr">Play<\/span><\/label>/', $page);
        $this->assertSame(2, substr_count($page, 'data-car-step'));
        $this->assertSame(2, preg_match_all('/class="sf-car-arrow sf-car-(?:prev|next)"[^>]* hidden>/', $page));
        $this->assertLessThan(strpos($page, '<ul class="sf-hs-track'), strpos($page, 'sf-hs-toggle'), 'the checkbox comes before the track: the pause is a sibling rule');

        // autoplay off: the manual row, no pause control
        $this->publish([$this->hero([$this->slide($a), $this->slide($a)], ['autoplay' => false])]);
        $page = $this->home();
        $this->assertStringContainsString('class="sf-hs sf-hs-manual sf-hs-n-2', $page);
        $this->assertStringNotContainsString('sf-hs-toggle', $page);
        $this->assertStringNotContainsString('sf-hs-pause', $page);
        $this->assertSame(2, substr_count($page, 'data-car-step'), 'the arrows stay');

        // one slide: nothing moves, so no pause control, no arrows, a track that is not a tab stop; autoplay on or off
        foreach ([true, false] as $autoplay) {
            $this->publish([$this->hero([$this->slide($a)], ['autoplay' => $autoplay])]);
            $page = $this->home();
            $this->assertStringContainsString('class="sf-hs sf-hs-manual sf-hs-n-1', $page);
            $this->assertStringNotContainsString('sf-hs-toggle', $page);
            $this->assertStringNotContainsString('data-car-step', $page);
            $this->assertStringContainsString('<ul class="sf-hs-track sf-car-track">', $page);
        }
    }

    public function test_the_page_has_no_inline_style_or_script_from_the_hero_and_the_text_is_escaped(): void
    {
        $card = HtmlBlockModel::query()->create(['title' => 'x', 'kind' => 'card', 'card' => ['picture' => $this->pickerAsset()->id, 'title' => 'Tom & "Jerry"', 'subtitle' => '', 'text' => '', 'link' => '', 'alt' => 'alt']]);
        $this->publish([$this->hero([$this->slide($card), $this->slide($card)])]);

        $page = $this->home();
        $this->assertSame(1, preg_match('#<section class="sf-hs.*?</section>#s', $page, $found));
        $hero = $found[0];

        $this->assertStringNotContainsString('style=', $hero);
        $this->assertStringNotContainsString('<script', $hero);
        $this->assertStringNotContainsString('onclick', $hero);
        $this->assertStringContainsString('Tom &amp; "Jerry"', $hero, 'the ampersand is escaped');
        $this->assertStringNotContainsString('Tom & "Jerry"', $hero);
        $this->assertStringNotContainsString('<img', $hero, 'a «Снимка с текст» block draws its picture as a CSS background, not an img: there is no lazy attribute to set (HP-6 step 0)');
    }

    public function test_the_section_takes_part_in_the_home_flow_like_the_others(): void
    {
        $a = $this->block();
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Above']], $this->hero([$this->slide($a)], ['spacing' => '64']), ['type' => 'heading', 'settings' => ['text' => 'Below']]]);

        $page = $this->home();

        $this->assertStringContainsString('<section class="sf-hp-section sf-hp-hero sf-hp-mt-64">', $page);
        $this->assertLessThan(strpos($page, 'sf-hp-hero'), strpos($page, 'Above'));
        $this->assertLessThan(strpos($page, 'Below'), strpos($page, 'sf-hp-hero'));

        // the common «show» hides it
        $this->publish([$this->hero([$this->slide($a)], ['show' => false])]);
        $this->assertStringNotContainsString('sf-hs', $this->home());
    }

    // ---- ★ data ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_compiled_blocks_are_cleaned_again_when_the_page_is_read(): void
    {
        $a = $this->block('Real');
        $this->publish([$this->hero([$this->slide($a), $this->slide($a)])]);
        $settings = app(SiteSettingsRepository::class);
        $doc = json_decode((string) $settings->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $doc['zones']['home']['global']['blocks'][(string) $a->id] = '<p>safe</p><script>alert(7)</script><img src="data:image/gif;base64,R0lGOD" onerror="alert(9)" alt="x"><a href="javascript:alert(8)" onclick="alert(10)">l</a>';
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($doc));
        app(StorefrontAppearance::class)->refresh();

        $page = $this->home();

        $this->assertStringContainsString('<p>safe</p>', $page);
        $this->assertStringNotContainsString('alert(7)', $page);
        $this->assertStringNotContainsString('alert(9)', $page);
        $this->assertStringNotContainsString('alert(10)', $page);
        $this->assertStringNotContainsString('onerror', $page);
        $this->assertStringNotContainsString('data:image/gif', $page);
        $this->assertStringNotContainsString('javascript:', $page);
    }

    public function test_a_block_a_layout_shows_as_a_slide_or_a_phone_slide_cannot_be_deleted(): void
    {
        $desktop = $this->block('Desktop');
        $phone = $this->block('Phone');
        $free = $this->block('Free');
        $this->publish([$this->hero([$this->slide($desktop, $phone)])]);

        $this->assertTrue(HtmlBlockUsage::home($desktop->id));
        $this->assertTrue(HtmlBlockUsage::home($phone->id), 'the phone block counts too');
        $this->assertTrue(HtmlBlockUsage::isUsed($phone->id));
        $this->assertFalse(HtmlBlockUsage::isUsed($free->id));
        $this->assertNotNull(HtmlBlockUsage::refusal($desktop));
        $this->assertNotNull(HtmlBlockUsage::refusal($phone));
        $this->assertNull(HtmlBlockUsage::refusal($free));
        $this->assertFalse($phone->delete(), 'the last line of defence: the model refuses');
        $this->assertNotNull(HtmlBlockModel::query()->find($phone->id));
        $this->assertTrue($free->delete());
    }

    public function test_a_draft_holds_its_blocks_never_reaches_the_shop_and_the_signed_preview_shows_it(): void
    {
        $desktop = $this->card('Only in the draft');
        $phone = $this->block('Draft phone');
        [$draft] = $this->validate([$this->hero([$this->slide($desktop, $phone)])]);
        HomeDrafts::saveDraft($draft);

        $this->assertTrue(HtmlBlockUsage::home($desktop->id), 'the draft counts');
        $this->assertTrue(HtmlBlockUsage::home($phone->id), 'the draft counts for the phone block too');
        $this->assertStringNotContainsString('sf-hs', $this->home(), 'the shop still prints the old home page');
        $preview = $this->get(DesignPreviewController::previewUrl('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Only in the draft', $preview);
        $this->assertStringContainsString('class="sf-hs-m"', $preview);
    }

    public function test_a_block_that_is_gone_drops_its_slide_after_the_recompile_and_a_layout_without_a_hero_stays_as_it_was(): void
    {
        $a = $this->block('Stay');
        $b = $this->block('Leave');
        $this->publish([$this->hero([$this->slide($a), $this->slide($b)])]);
        $this->assertStringContainsString('Leave', $this->home());

        DB::table('html_blocks')->where('id', $b->id)->delete();
        app(HomeCompiler::class)->refresh();
        $page = $this->home();

        $this->assertStringNotContainsString('Leave', $page);
        $this->assertStringContainsString('sf-hs-n-1', $page);

        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
        $doc = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $this->assertSame(['layout', 'blocks'], array_keys($doc['zones']['home']['global']), 'a layout without a hero adds nothing to the compiled document');
    }

    // ---- ★ query counts -------------------------------------------------------------------------------------------------------------------

    public function test_a_hero_costs_no_query_at_all(): void
    {
        $cards = [$this->card('One'), $this->card('Two'), $this->card('Three')];
        $phone = $this->card('Phone');
        $text = $this->block('Text');
        $heading = ['type' => 'heading', 'settings' => ['text' => 'Hi']];

        $this->publish([$heading]);
        $base = $this->queries();
        $this->assertSame(self::WITHOUT_HERO, $base, 'the home with a heading only');

        $this->publish([$heading, $this->hero([$this->slide($cards[0])])]);
        $this->assertSame($base, $this->queries(), 'a hero of one slide');

        $this->publish([$this->hero([$this->slide($cards[0], $phone), $this->slide($cards[1], $phone), $this->slide($cards[2]), $this->slide($text), $this->slide($cards[0]), $this->slide($cards[1], $phone)]), $heading]);
        $this->assertSame($base, $this->queries(), 'a hero of six slides with phone blocks');

        $this->publish([$this->hero([$this->slide($cards[0]), $this->slide($cards[1])], ['autoplay' => false]), $heading]);
        $this->assertSame($base, $this->queries(), 'a manual hero');
    }
}
