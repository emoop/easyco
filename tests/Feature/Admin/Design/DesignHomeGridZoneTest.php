<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\ImageGridGeometry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HP-2: the «Решетка от снимки» component in the «Начална» zone of the Design screen: it is offered by the palette, the cell selectors follow the scheme live, the size diagram shows the numbers of
 * ImageGridGeometry, the phone list and its warning, «Приложи» → «Публикувай» makes it live, a bad value is refused under its field, and every label and hint exists in both languages.
 */
class DesignHomeGridZoneTest extends TestCase
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

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', 'image_grid');
    }

    private function block(string $title): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $title, 'body' => "<p>{$title}</p>"]);
    }

    public function test_the_component_is_offered_and_a_new_grid_has_the_defaults(): void
    {
        $screen = $this->screen();
        $node = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0];

        $this->assertSame('image_grid', $node['type']);
        $this->assertSame(['three', '3:2', '16', '0', '8', [], []], [$node['settings']['scheme'], $node['settings']['ratio'], $node['settings']['gap'], $node['settings']['radius'], $node['settings']['phone_gap'], $node['settings']['phone_blocks'], $node['settings']['cells']]);
        $screen->assertSee('Image grid');
        $this->assertContains('image_grid', array_keys(HomeCatalog::all()));
    }

    public function test_the_scheme_changes_the_cell_selectors_and_the_diagram_numbers(): void
    {
        $screen = $this->screen();

        foreach (['one' => 1, 'three' => 3, 'four' => 4, 'six' => 6, 'uneven-a' => 6, 'uneven-b' => 3, 'uneven-c' => 3] as $scheme => $count) {
            $screen->set('props.settings.scheme', $scheme);
            $html = $screen->html();

            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i => $cell) {
                $label = 'Block for cell '.strtoupper($cell);
                $i < $count ? $this->assertStringContainsString($label, $html, "{$scheme} {$cell}") : $this->assertStringNotContainsString($label, $html, "{$scheme} {$cell}");
            }

            foreach (ImageGridGeometry::cells($scheme, '3:2', 16) as $cell) {
                $this->assertStringContainsString($cell['rounded_width'].' × '.$cell['rounded_height'], $html, "{$scheme} {$cell['name']}");
            }
        }

        // the proportion and the gap change the numbers too
        $screen->set('props.settings.ratio', '16:9');
        $screen->set('props.settings.gap', '32');
        $screen->set('props.settings.scheme', 'four');
        foreach (ImageGridGeometry::cells('four', '16:9', 32) as $cell) {
            $this->assertStringContainsString($cell['rounded_width'].' × '.$cell['rounded_height'], $screen->html());
        }

        $this->assertStringContainsString('Recommended size for a 1440px screen', $screen->html());
        $this->assertStringContainsString('Picture with text', $screen->html());
    }

    public function test_the_phone_warning_is_shown_while_the_list_is_empty(): void
    {
        $screen = $this->screen();
        $this->assertStringContainsString('Nothing will be shown on a phone', $screen->html());
        $this->assertStringContainsString('750 px', $screen->html());

        $block = $this->block('Phone one');
        $screen->set('props.settings.phone_items', [['block' => (string) $block->id]]);
        $this->assertStringNotContainsString('Nothing will be shown on a phone', $screen->html());
    }

    public function test_apply_and_publish_make_the_grid_live_with_its_cells_and_phone_blocks_in_order(): void
    {
        $a = $this->block('Alpha');
        $b = $this->block('Beta');
        $c = $this->block('Gamma');
        $screen = $this->screen()
            ->set('props.settings.scheme', 'two')->set('props.settings.ratio', '4:3')->set('props.settings.gap', '8')->set('props.settings.radius', '12')
            ->set('props.settings.cells.a', (string) $a->id)->set('props.settings.cells.b', (string) $b->id)
            ->set('props.settings.phone_items', [['block' => (string) $c->id], ['block' => (string) $a->id]])->set('props.settings.phone_gap', '12')
            ->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $stored = (array) json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), true);
        $s = $stored['sections'][0]['settings'];

        $this->assertSame(['two', '4:3', '8', '12', '12'], [$s['scheme'], $s['ratio'], $s['gap'], $s['radius'], $s['phone_gap']]);
        $this->assertSame(['a' => $a->id, 'b' => $b->id], $s['cells']);
        $this->assertSame([$c->id, $a->id], $s['phone_blocks']);

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('sf-hp-s-two', $page);
        $this->assertStringContainsString('Gamma', $page);
        $screen->assertSet('dirty', false);
    }

    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_published(): void
    {
        foreach (['scheme' => 'seven', 'ratio' => '21:9', 'gap' => '10', 'radius' => '10', 'phone_gap' => '5'] as $key => $value) {
            $screen = $this->screen()->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
            $screen->call('publish');
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), $key);
        }
    }

    public function test_the_chip_says_which_grid_it_is(): void
    {
        $screen = $this->screen()->set('props.settings.scheme', 'uneven-a')->call('apply');

        $screen->assertSee('Grid: Uneven A');
    }

    public function test_every_label_hint_option_and_error_exists_in_both_languages(): void
    {
        $keys = [];

        foreach (['scheme', 'ratio', 'gap', 'radius', 'phone_blocks', 'phone_block', 'phone_gap', 'cells.a', 'cells.b', 'cells.c', 'cells.d', 'cells.e', 'cells.f'] as $k) {
            $keys[] = 'design.home.grid.settings.'.$k;
        }

        foreach (['scheme', 'ratio', 'gap', 'radius', 'cell', 'phone_blocks', 'phone_gap'] as $k) {
            $keys[] = 'design.home.grid.hints.'.$k;
        }

        foreach (array_keys(ImageGridGeometry::SCHEMES) as $k) {
            $keys[] = 'design.home.grid.schemes.'.$k;
        }

        foreach (ImageGridGeometry::RATIOS as $k) {
            $keys[] = 'design.home.grid.ratios.'.$k;
        }

        foreach (ImageGridGeometry::GAPS as $k) {
            $keys[] = 'design.home.grid.gap.'.$k;
        }

        foreach (ImageGridGeometry::RADII as $k) {
            $keys[] = 'design.home.grid.radius.'.$k;
        }

        foreach (['summary', 'cell_empty', 'sizes_title', 'sizes_note', 'sizes_kind', 'phone_note', 'phone_empty', 'phone_add'] as $k) {
            $keys[] = 'design.home.grid.'.$k;
        }

        foreach (['too_many_phone', 'bad_cells', 'unknown_cell'] as $k) {
            $keys[] = 'design.home.errors.'.$k;
        }

        $keys[] = 'design.home.types.image_grid';

        foreach (['bg', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, [], $locale), "{$locale}: {$key}");
            }
        }
    }
}
