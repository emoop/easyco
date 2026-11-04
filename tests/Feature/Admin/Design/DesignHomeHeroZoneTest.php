<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HeroGeometry;
use App\Storefront\Home\HomeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HP-6: the «Hero слайдер» component in the «Начална» zone of the Design screen: the seconds are hidden while the slides do not change by themselves, the recommended picture sizes come from HeroGeometry (the
 * numbers the tests pin), the settings are two per row and the explanations are info icons, the slides are applied as a list of `{block, block_phone}` in order, apply → publish makes it live and the chip says what
 * it is, a bad value is refused under its field, the second hero cannot be added, and every label, hint and option exists in both languages.
 */
class DesignHomeHeroZoneTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->actingAsStaff('Administrator');
    }

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', 'hero');
    }

    private function labelShown(string $html, string $label): bool
    {
        return preg_match('/>\s*'.preg_quote($label, '/').'\s*</', $html) === 1;
    }

    private function block(string $title): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $title, 'body' => "<p>{$title}</p>"]);
    }

    /** @return array<string, mixed> the stored settings of the first section */
    private function stored(Testable $screen): array
    {
        return $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0]['settings'];
    }

    public function test_the_component_is_offered_and_a_new_section_has_the_defaults(): void
    {
        $screen = $this->screen();
        $s = $this->stored($screen);

        $this->assertSame([true, '5', '21:9', '4:5', []], [$s['autoplay'], $s['seconds'], $s['ratio'], $s['ratio_phone'], $s['slides']]);
        $this->assertTrue($s['show']);
        $screen->assertSee('Hero slider');
        $this->assertContains('hero', array_column($screen->instance()->palette(), 'type'));
    }

    public function test_the_seconds_are_hidden_when_the_slides_do_not_change_by_themselves(): void
    {
        $screen = $this->screen();

        foreach (['Change by itself', 'Seconds per slide', 'Proportion on a desktop', 'Proportion on a phone'] as $shown) {
            $this->assertTrue($this->labelShown($screen->html(), $shown), $shown);
        }

        $html = $screen->set('props.settings.autoplay', false)->html();
        $this->assertFalse($this->labelShown($html, 'Seconds per slide'), 'autoplay off: no seconds');
        $this->assertTrue($this->labelShown($html, 'Proportion on a desktop'), 'the proportions stay');

        $this->assertTrue($this->labelShown($screen->set('props.settings.autoplay', true)->html(), 'Seconds per slide'));
    }

    public function test_the_size_hint_numbers_equal_hero_geometry(): void
    {
        foreach ([['21:9', '4:5'], ['16:9', '1:1'], ['3:1', '3:4'], ['2:1', '4:3']] as [$ratio, $phone]) {
            $screen = $this->screen()->set('props.settings.ratio', $ratio)->set('props.settings.ratio_phone', $phone);
            $d = HeroGeometry::desktop($ratio);
            $p = HeroGeometry::phone($phone);

            $this->assertStringContainsString(e((string) __('design.home.hero.hint_size', [
                'w' => $d['rounded_width'], 'h' => $d['rounded_height'], 'w2' => $d['double_width'], 'h2' => $d['double_height'],
                'pw' => $p['rounded_width'], 'ph' => $p['rounded_height'], 'pw2' => $p['double_width'], 'ph2' => $p['double_height'],
            ])), $screen->html(), "{$ratio} / {$phone}");
        }

        $this->assertStringContainsString('Picture for a desktop: 1320 × 570 px, 2× for sharp screens: 2640 × 1130. Picture for a phone: 360 × 450 px, 2×: 720 × 900.', html_entity_decode($this->screen()->html()), 'the defaults');
    }

    public function test_the_settings_are_one_per_row_a_slide_is_two_tabs_and_the_hints_are_info_icons(): void
    {
        $screen = $this->screen()->set('props.settings.slide_items', [['block' => '', 'block_phone' => '']]);
        $html = $screen->html();

        // the settings above the slide list are ONE column at every width (no two-column grid before the list); the slide's own two selectors are a two-column grid inside the list
        $this->assertSame(1, preg_match('/>\s*Slides\s*</', $html, $at, PREG_OFFSET_CAPTURE));
        $settings = substr($html, (int) strpos($html, 'Hero slider'), $at[0][1] - (int) strpos($html, 'Hero slider'));
        $this->assertStringNotContainsString('--cols-md', $settings, 'one setting per row');
        $this->assertStringNotContainsString('--cols-md', substr($html, $at[0][1]), 'nor a two-column grid inside a slide');
        $this->assertTrue($this->labelShown($html, 'Desktop') && $this->labelShown($html, 'Phone'), 'a slide is two tabs: its desktop block and its phone block');

        foreach (['autoplay', 'seconds', 'ratio', 'ratio_phone', 'slides', 'block', 'block_phone'] as $k) {
            $this->assertStringContainsString(e((string) __('design.home.hero.hints.'.$k)), $html, $k);
        }

        // each tab holds one selector
        $this->assertTrue($this->labelShown($html, 'Block for a desktop'));
        $this->assertTrue($this->labelShown($html, 'Block for a phone (optional)'));
        $this->assertStringContainsString('Add a slide', $html);
    }

    public function test_apply_and_publish_make_the_hero_live_and_the_chip_says_what_it_is(): void
    {
        $a = $this->block('First');
        $b = $this->block('Second');
        $phone = $this->block('Phone of first');
        $screen = $this->screen();
        $screen->set('props.settings.seconds', '8')->set('props.settings.ratio', '16:9')->set('props.settings.ratio_phone', '1:1')
            ->set('props.settings.slide_items', [['block' => (string) $a->id, 'block_phone' => (string) $phone->id], ['block' => (string) $b->id, 'block_phone' => '']]);
        $screen->call('apply')->assertHasNoErrors()->assertSee('Hero slider: 2 slides, 8 s')->call('publish')->assertHasNoErrors();

        $s = (array) json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), true)['sections'][0]['settings'];
        $this->assertSame(['8', '16:9', '1:1', true], [$s['seconds'], $s['ratio'], $s['ratio_phone'], $s['autoplay']]);
        $this->assertSame([['block' => $a->id, 'block_phone' => $phone->id], ['block' => $b->id, 'block_phone' => null]], $s['slides'], 'in order, an empty phone block is none');

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="sf-hs sf-hs-auto sf-hs-n-2 sf-hs-t-8 sf-hs-r-16-9 sf-hs-rp-1-1"', $page);
        $this->assertStringContainsString('Phone of first', $page);
        $screen->assertSet('dirty', false);

        // the panel shows what is stored: the zone turns the stored slides into the repeater's items
        $tree = $screen->get('tree');
        $state = (new \App\Filament\Design\Zones\HomeZone)->propsState($tree, 'main', ['kind' => 'node', 'id' => $tree['main']['rows'][0]['cells'][0]['items'][0]['id']]);
        $this->assertSame([['block' => $a->id, 'block_phone' => $phone->id], ['block' => $b->id, 'block_phone' => null]], $state['settings']['slide_items']);
    }

    public function test_the_chip_for_a_manual_hero_and_for_one_slide(): void
    {
        $a = $this->block('A');
        $b = $this->block('B');
        $screen = $this->screen()->set('props.settings.slide_items', [['block' => (string) $a->id, 'block_phone' => ''], ['block' => (string) $b->id, 'block_phone' => '']]);

        $screen->set('props.settings.autoplay', false)->call('apply')->assertHasNoErrors()->assertSee('Hero slider: 2 slides, manual');
        $screen->set('props.settings.slide_items', [['block' => (string) $a->id, 'block_phone' => '']])->call('apply')->assertHasNoErrors()->assertSee('Hero slider: 1 slide');
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badValues(): array
    {
        return [
            'seconds' => ['seconds', '7'],
            'ratio' => ['ratio', '5:1'],
            'a phone ratio as the desktop one' => ['ratio', '4:5'],
            'ratio phone' => ['ratio_phone', '5:4'],
            'a desktop ratio as the phone one' => ['ratio_phone', '21:9'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_published(string $key, mixed $value): void
    {
        $a = $this->block('A');
        $screen = $this->screen()->set('props.settings.slide_items', [['block' => (string) $a->id, 'block_phone' => '']])->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
        $screen->call('publish');

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), $key);
    }

    public function test_no_slide_seven_slides_and_a_slide_without_a_block_are_refused(): void
    {
        $screen = $this->screen()->call('apply');
        $screen->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'no slide');

        $a = $this->block('A');
        $seven = array_fill(0, 7, ['block' => (string) $a->id, 'block_phone' => '']);
        $screen = $this->screen()->set('props.settings.slide_items', $seven)->call('apply');
        $screen->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'seven slides');

        $screen = $this->screen()->set('props.settings.slide_items', [['block' => '', 'block_phone' => (string) $a->id]])->call('apply');
        $screen->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'a slide without a block');
    }

    public function test_a_second_hero_cannot_be_added_and_the_palette_says_why(): void
    {
        $screen = $this->screen()->call('addNode', 'hero');
        $this->assertCount(1, $screen->get('tree')['main']['rows'][0]['cells'][0]['items']);

        $palette = array_column($screen->instance()->palette(), null, 'type');
        $this->assertSame('Only 1 hero slider on the home page.', $palette['hero']['reason']);
        $this->assertNull($palette['heading']['reason']);
        $this->assertNull($palette['carousel']['reason'], 'a hero does not count against the carousel limit');
        $this->assertSame(1, HomeCatalog::MAX_HEROES);
    }

    /** DRIBNI-4: an invalid slide #2 shows its message at ITS OWN row (state `slide_items`, settings `slides`), and slide #1 shows none. */
    public function test_an_invalid_slide_gets_its_message_at_its_own_row(): void
    {
        $a = $this->block('A');
        $phone = $this->block('P');
        $screen = $this->screen()->set('props.settings.slide_items', [
            (string) Str::uuid() => ['block' => (string) $a->id, 'block_phone' => ''],
            (string) Str::uuid() => ['block' => '', 'block_phone' => (string) $phone->id],
        ])->call('apply');

        // Filament re-keys the items while the request runs: the rendered keys are the ones after it
        [$first, $second] = array_keys($screen->get('props')['settings']['slide_items']);

        $screen->assertHasErrors(["props.settings.slide_items.{$second}.block"]);
        $screen->assertHasNoErrors(["props.settings.slide_items.{$first}.block"]);
        $this->assertStringContainsString(e((string) $screen->errors()->first("props.settings.slide_items.{$second}.block")), $screen->html(), 'the message of slide #2 is in the page');
    }

    public function test_every_label_hint_option_and_error_exists_in_both_languages(): void
    {
        $keys = ['design.home.types.hero', 'design.home.hero.summary', 'design.home.hero.summary_one', 'design.home.hero.summary_manual', 'design.home.hero.slide_add', 'design.home.hero.hint_size', 'design.home.hero.tabs.desktop', 'design.home.hero.tabs.phone',
            'storefront.hero.label', 'storefront.hero.pause', 'storefront.hero.play'];

        foreach (['too_many_heroes', 'too_few_slides', 'too_many_slides'] as $k) {
            $keys[] = 'design.home.errors.'.$k;
        }

        foreach (['autoplay', 'seconds', 'ratio', 'ratio_phone', 'slides', 'block', 'block_phone'] as $k) {
            $keys[] = 'design.home.hero.settings.'.$k;
            $keys[] = 'design.home.hero.hints.'.$k;
        }

        foreach (HeroGeometry::SECONDS as $k) {
            $keys[] = 'design.home.hero.seconds.'.$k;
        }

        foreach ([...HeroGeometry::RATIOS, ...HeroGeometry::PHONE_RATIOS] as $k) {
            $keys[] = 'design.home.hero.ratios.'.$k;
        }

        foreach (['bg', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, ['max' => 1], $locale), "{$locale}: {$key}");
            }
        }
    }
}
