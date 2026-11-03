<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HP-1: the «Начална» zone of the Design screen — an ordered list of sections: add (a heading, an HTML block), move, remove, show / hide, the general spacing and a section's own, apply → publish like the other
 * zones, the refusals with their messages, «Начални настройки», and the labels and help in both languages.
 */
class DesignHomeZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home');
    }

    /** @return list<array<string, mixed>> */
    private function items(Testable $screen): array
    {
        return $screen->get('tree')['main']['rows'][0]['cells'][0]['items'];
    }

    /** @return list<string> */
    private function types(Testable $screen): array
    {
        return array_column($this->items($screen), 'type');
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) $this->settings()->get($key), true);
    }

    /** Adds a heading with this text and leaves the screen with it applied. */
    private function addHeading(Testable $screen, string $text, array $extra = []): Testable
    {
        // adding a section selects it, so its properties are the ones the next lines set
        $screen->call('addNode', 'heading')->set('props.settings.text', $text);

        foreach ($extra as $key => $value) {
            $screen->set('props.settings.'.$key, $value);
        }

        return $screen->call('apply')->assertHasNoErrors();
    }

    // ---- the zone -----------------------------------------------------------------------------------------------------------------------

    public function test_the_zone_is_in_the_list_starts_empty_with_its_one_cell_selected_and_offers_its_components(): void
    {
        $screen = $this->screen();

        $this->assertArrayHasKey('home', DesignScreen::ZONES);
        $this->assertStringContainsString('>Home</button>', $screen->html(), 'the zone is a button of the list');
        $this->assertSame([], $this->types($screen));
        $screen->assertSet('selected', ['kind' => 'cell', 'id' => 'h-r0-c0'])->assertSet('dirty', false);
        $this->assertSame(['heading', 'html_block', 'image_grid'], array_column($screen->instance()->palette(), 'type'));
        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY));
    }

    public function test_adding_components_is_stored_as_the_draft_at_once_and_the_shop_does_not_change(): void
    {
        $screen = $this->screen()->call('addNode', 'heading')->call('addNode', 'html_block');

        $this->assertSame(['heading', 'html_block'], $this->types($screen));
        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());
    }

    public function test_a_section_moves_up_and_down_and_is_removed_and_the_list_stops_at_twelve(): void
    {
        $screen = $this->screen()->call('addNode', 'heading')->call('addNode', 'html_block')->call('addNode', 'heading');
        $second = $this->items($screen)[1]['id'];

        $screen->call('moveNode', $second, 'up');
        $this->assertSame(['html_block', 'heading', 'heading'], $this->types($screen));
        $screen->call('moveNode', $second, 'down')->call('removeNode', $second);
        $this->assertSame(['heading', 'heading'], $this->types($screen));

        for ($i = 0; $i < 10; $i++) {
            $screen->call('addNode', 'heading');
        }

        $this->assertCount(12, $this->items($screen));
        $screen->call('addNode', 'heading');
        $this->assertCount(12, $this->items($screen), 'a thirteenth section is refused');
    }

    // ---- apply → publish ---------------------------------------------------------------------------------------------------------------

    public function test_apply_writes_the_draft_and_publish_makes_it_live_and_forgets_the_draft(): void
    {
        $screen = $this->addHeading($this->screen(), 'New in', ['level' => 'h3', 'lines' => 'none', 'thickness' => '2']);

        $draft = $this->stored(AppearanceSettings::HOME_DRAFT_KEY);
        $this->assertSame('New in', $draft['sections'][0]['settings']['text']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'the shop is not touched by «Apply»');

        $screen->call('publish')->assertHasNoErrors();

        $live = $this->stored(AppearanceSettings::HOME_PUBLISHED_KEY);
        $this->assertSame(['text' => 'New in', 'level' => 'h3', 'lines' => 'none', 'thickness' => '2'], array_intersect_key($live['sections'][0]['settings'], array_flip(['text', 'level', 'lines', 'thickness'])));
        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_DRAFT_KEY));
        $this->assertStringContainsString('"home"', (string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
    }

    public function test_the_general_spacing_and_a_sections_own_spacing_and_its_switch_are_stored(): void
    {
        $screen = $this->addHeading($this->screen(), 'One');
        $screen = $this->addHeading($screen, 'Two', ['spacing' => '96', 'show' => false]);

        $screen->call('selectLayout', 'main')->set('props.layout.spacing', '64')->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $live = $this->stored(AppearanceSettings::HOME_PUBLISHED_KEY);
        $this->assertSame('64', $live['spacing']);
        $this->assertSame('96', $live['sections'][1]['settings']['spacing']);
        $this->assertFalse($live['sections'][1]['settings']['show']);
        $this->assertTrue($live['sections'][0]['settings']['show']);
    }

    public function test_an_html_block_is_chosen_from_the_list_of_blocks(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'Promo', 'body' => '<p>Sale</p>']);
        $screen = $this->screen()->call('addNode', 'html_block');
        $id = $this->items($screen)[0]['id'];

        $screen->call('selectNode', $id)->set('props.settings.html_block', (string) $block->id)->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $this->assertSame((int) $block->id, $this->stored(AppearanceSettings::HOME_PUBLISHED_KEY)['sections'][0]['settings']['html_block']);
    }

    // ---- the refusals --------------------------------------------------------------------------------------------------------------------

    public function test_a_value_outside_the_lists_is_refused_under_its_field_and_nothing_is_published(): void
    {
        $screen = $this->screen()->call('addNode', 'heading');
        $id = $this->items($screen)[0]['id'];

        foreach ([['text', str_repeat('a', 121)], ['text', 'Hi <b>there</b>'], ['level', 'h1'], ['spacing', '10']] as [$key, $value]) {
            $screen->call('selectNode', $id)->set('props.settings.text', 'Fine')->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
            $screen->call('publish');
            $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY), "{$key}: {$value}");
        }
    }

    public function test_the_general_spacing_outside_the_list_is_refused(): void
    {
        $screen = $this->addHeading($this->screen(), 'One');

        $screen->call('selectLayout', 'main')->set('props.layout.spacing', '10')->call('apply')->assertHasErrors(['props.layout.spacing']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY));
    }

    public function test_a_section_without_a_chosen_block_is_refused(): void
    {
        $screen = $this->screen()->call('addNode', 'html_block');
        $id = $this->items($screen)[0]['id'];

        $screen->call('selectNode', $id)->call('apply')->assertHasErrors(['props.settings.html_block']);
    }

    // ---- the default and the discard -----------------------------------------------------------------------------------------------------

    public function test_the_initial_settings_are_the_empty_layout_and_publishing_it_takes_the_page_back_to_the_old_one(): void
    {
        $this->addHeading($this->screen(), 'One')->call('publish');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY));

        $this->screen()->call('resetToDefault')->call('publish');

        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_PUBLISHED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), 'nothing else is compiled either: the compiled document is gone');
    }

    public function test_discarding_the_working_copy_goes_back_to_the_published_layout(): void
    {
        $this->addHeading($this->screen(), 'Published')->call('publish');
        $screen = $this->addHeading($this->screen(), 'Draft only');

        $this->assertNotNull($this->settings()->get(AppearanceSettings::HOME_DRAFT_KEY));

        $screen->call('discard');

        $this->assertNull($this->settings()->get(AppearanceSettings::HOME_DRAFT_KEY));
        $this->assertCount(1, $this->items($this->screen()));
    }

    // ---- the words ------------------------------------------------------------------------------------------------------------------------

    public function test_the_labels_and_the_help_exist_in_both_languages_for_every_control(): void
    {
        foreach (['en', 'bg'] as $locale) {
            app()->setLocale($locale);

            foreach (['gap', 'show', 'spacing', 'text', 'level', 'lines', 'thickness', 'html_block'] as $key) {
                $this->assertNotSame('design.home.settings.'.$key, __('design.home.settings.'.$key), "{$locale}: label {$key}");
                $this->assertNotSame('design.home.hints.'.$key, __('design.home.hints.'.$key), "{$locale}: help {$key}");
            }

            foreach (['heading', 'html_block'] as $type) {
                $this->assertNotSame('design.home.types.'.$type, __('design.home.types.'.$type), "{$locale}: type {$type}");
            }

            foreach (['level' => ['h2', 'h3', 'h4'], 'lines' => ['lines', 'none'], 'thickness' => ['1', '2'], 'gap' => ['0', '8', '16', '24', '32', '48', '64', '96'], 'spacing' => ['default', '0', '96']] as $field => $options) {
                foreach ($options as $option) {
                    $this->assertNotSame("design.home.options.{$field}.{$option}", __("design.home.options.{$field}.{$option}"), "{$locale}: {$field} {$option}");
                }
            }

            $this->assertNotSame('design.zones.home', __('design.zones.home'));
            $this->assertNotSame('design.preview.home', __('design.preview.home'));
            $this->assertNotSame('design.notices.published_home', __('design.notices.published_home'));
        }
    }

    public function test_the_hints_are_info_icons_and_not_paragraphs_under_the_fields(): void
    {
        $screen = $this->screen()->call('addNode', 'heading');
        $id = $this->items($screen)[0]['id'];
        $html = $screen->call('selectNode', $id)->html();

        $this->assertStringContainsString('info-hint-bubble', $html);
        $this->assertStringNotContainsString('fi-fo-field-helper-text', $html);
        $this->assertStringContainsString(__('design.home.hints.text'), $html, 'the text is in the bubble');
    }
}
