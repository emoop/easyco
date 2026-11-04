<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\TileGeometry;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HP-3: the «Плочки» component in the «Начална» zone of the Design screen: it is offered by the palette, the item select follows the source, a tile that would have no picture shows its warning, the size hint
 * carries the numbers of TileGeometry, apply → publish makes it live, a bad value is refused under its field, and every label, hint and option exists in both languages.
 */
class DesignHomeTilesZoneTest extends TestCase
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
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->actingAsStaff('Administrator');
    }

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', 'tiles');
    }

    public function test_the_component_is_offered_and_a_new_section_has_the_defaults(): void
    {
        $screen = $this->screen();
        $node = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0];

        $this->assertSame('tiles', $node['type']);
        $this->assertSame(['4', '2', '16', 'square', '0', 'm', []], [$node['settings']['columns'], $node['settings']['columns_phone'], $node['settings']['gap'], $node['settings']['shape'], $node['settings']['radius'], $node['settings']['caption'], $node['settings']['tiles']]);
        $screen->assertSee('Tiles');
        $this->assertContains('tiles', array_column($screen->instance()->palette(), 'type'));
    }

    public function test_the_item_select_follows_the_source(): void
    {
        SeasonModel::query()->create(['name' => 'Winter Edition', 'slug' => 'winter']);
        $screen = $this->screen();

        $screen->set('props.settings.tiles', [['source' => 'season', 'entity' => null, 'label' => '', 'picture' => null, 'link' => '']]);
        $this->assertStringContainsString('Winter Edition', $screen->html());
        $this->assertStringNotContainsString('Clothing', substr($screen->html(), (int) strpos($screen->html(), 'Source')), 'a season tile offers seasons, not categories');

        $screen->set('props.settings.tiles', [['source' => 'category', 'entity' => null, 'label' => '', 'picture' => null, 'link' => '']]);
        $this->assertStringContainsString('Clothing', $screen->html());
    }

    public function test_a_tile_that_would_have_no_picture_shows_its_warning(): void
    {
        $asset = $this->pickerAsset();
        $screen = $this->screen();

        $screen->set('props.settings.tiles', [['source' => 'custom', 'entity' => null, 'label' => 'Free', 'picture' => null, 'link' => '/x']]);
        $this->assertStringContainsString('The tile has no picture and will not be shown.', $screen->html());

        $screen->set('props.settings.tiles', [['source' => 'custom', 'entity' => null, 'label' => 'Free', 'picture' => $asset->id, 'link' => '/x']]);
        $this->assertStringNotContainsString('The tile has no picture and will not be shown.', $screen->html());

        // a category with its own picture needs none; one without does
        DB::table('catalog_categories')->where('id', 1)->update(['picture_media_asset_id' => $asset->id]);
        $screen->set('props.settings.tiles', [['source' => 'category', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => '']]);
        $this->assertStringNotContainsString('The tile has no picture and will not be shown.', $screen->html());

        DB::table('catalog_categories')->where('id', 1)->update(['picture_media_asset_id' => null]);
        $screen->set('props.settings.tiles', [['source' => 'category', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => '']]);
        $this->assertStringContainsString('The tile has no picture and will not be shown.', $screen->html());
    }

    public function test_the_size_hint_has_the_numbers_of_the_geometry(): void
    {
        $screen = $this->screen();
        $screen->set('props.settings.columns', '5');
        $screen->set('props.settings.gap', '32');
        $screen->set('props.settings.shape', 'portrait');
        $html = $screen->html();

        $desktop = TileGeometry::tile(5, 32, 'portrait', TileGeometry::REFERENCE_WIDTH);
        $phone = TileGeometry::tile(2, 32, 'portrait', TileGeometry::PHONE_REFERENCE_WIDTH);

        $this->assertStringContainsString("Recommended size: {$desktop['rounded_width']} × {$desktop['rounded_height']} px (for a 1440px screen), 2× for sharp screens: {$desktop['double_width']} × {$desktop['double_height']}", $html);
        $this->assertStringContainsString("On a phone: {$phone['rounded_width']} × {$phone['rounded_height']} px (for a 390px screen)", $html);
    }

    public function test_apply_and_publish_make_the_section_live(): void
    {
        $asset = $this->pickerAsset();
        DB::table('catalog_categories')->where('id', 1)->update(['picture_media_asset_id' => $asset->id]);

        $screen = $this->screen();
        $screen->set('props.settings.columns', '3');
        $screen->set('props.settings.shape', 'landscape');
        $screen->set('props.settings.tiles', [
            ['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => ''],
            ['source' => 'custom', 'entity' => null, 'label' => 'Sale', 'picture' => (string) $asset->id, 'link' => '/sale'],
        ]);
        $screen->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $stored = (array) json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), true);
        $s = $stored['sections'][0]['settings'];

        $this->assertSame(['3', 'landscape'], [$s['columns'], $s['shape']]);
        $this->assertSame([['source' => 'category', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => null], ['source' => 'custom', 'entity' => null, 'label' => 'Sale', 'picture' => $asset->id, 'link' => '/sale']], $s['tiles']);

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('sf-hp-tc-3', $page);
        $this->assertStringContainsString('>Clothing<', $page);
        $this->assertStringContainsString('>Sale<', $page);
        $screen->assertSet('dirty', false);
    }

    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_published(): void
    {
        foreach (['columns' => '7', 'columns_phone' => '3', 'gap' => '10', 'shape' => 'round', 'radius' => '70', 'caption' => 'xl'] as $key => $value) {
            $screen = $this->screen()->set('props.settings.tiles', [['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => '']])->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
            $screen->call('publish');
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), $key);
        }
    }

    public function test_a_tile_without_what_it_needs_is_refused(): void
    {
        foreach ([
            [['source' => 'season', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => '']],
            [['source' => 'custom', 'entity' => null, 'label' => '', 'picture' => null, 'link' => '/x']],
            [['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => 'javascript:alert(1)']],
        ] as $tiles) {
            $screen = $this->screen()->set('props.settings.tiles', $tiles)->call('apply');
            $screen->call('publish');
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), json_encode($tiles));
        }
    }

    public function test_the_chip_says_what_the_row_is(): void
    {
        $screen = $this->screen();
        $screen->set('props.settings.columns', '5');
        $screen->set('props.settings.tiles', [['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => ''], ['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => '']]);
        $screen->call('apply');

        $screen->assertSee('Tiles: 2, 5 columns');
    }

    /** DRIBNI-2: every tile field shows its OWN explanation in its icon. `gap` and `radius` are also names of the section's and of the image grid's, and their texts must not be shown here. */
    public function test_every_tiles_field_shows_its_own_explanation_in_its_icon(): void
    {
        $html = $this->screen()->html();

        foreach (['columns', 'columns_phone', 'gap', 'shape', 'radius', 'caption'] as $name) {
            $this->assertStringContainsString(e((string) __('design.home.tiles.hints.'.$name)), $html, $name);
        }

        $this->assertStringNotContainsString(e((string) __('design.home.hints.gap')), $html, 'the section gap explanation is not the tiles gap');
        $this->assertStringNotContainsString(e((string) __('design.home.grid.hints.radius')), $html, 'the grid radius explanation is not the tiles radius');
    }

    /** DRIBNI-2: a tile that does not validate gets its message at ITS OWN row (the picture of tile #2 is `tiles.1`), and a valid tile before it gets none. */
    public function test_an_invalid_tile_gets_its_message_at_its_own_row(): void
    {
        // the items are keyed by id, as Filament keys a repeater's items in the browser (a uuid); the validator counts them by position
        $screen = $this->screen()->set('props.settings.tiles', [
            (string) Str::uuid() => ['source' => 'category', 'entity' => '1', 'label' => '', 'picture' => null, 'link' => ''],
            (string) Str::uuid() => ['source' => 'custom', 'entity' => null, 'label' => 'Free', 'picture' => null, 'link' => '/x'],
        ])->call('apply');

        // Filament re-keys the items while the request runs: the rendered item keys are the ones after it (tile #2 is the second)
        [$first, $second] = array_keys($screen->get('props')['settings']['tiles']);

        $screen->assertHasErrors(["props.settings.tiles.{$second}.picture"]);
        $screen->assertHasNoErrors(["props.settings.tiles.{$first}.picture"]);

        // the message is printed in the page, and the tile's own row is where the picture field is (the field's error wrapper)
        $message = (string) $screen->errors()->first("props.settings.tiles.{$second}.picture");
        $this->assertNotSame('', $message);
        $this->assertStringContainsString(e($message), $screen->html(), 'the message of tile #2 is in the page');
    }

    public function test_every_label_hint_option_and_error_exists_in_both_languages(): void
    {
        $keys = ['design.home.types.tiles', 'design.home.tiles.summary', 'design.home.tiles.add', 'design.home.tiles.no_picture', 'design.home.tiles.hint_title', 'design.home.tiles.hint_desktop', 'design.home.tiles.hint_phone'];

        foreach (['columns', 'columns_phone', 'gap', 'shape', 'radius', 'caption', 'tiles', 'source', 'entity', 'label', 'picture', 'link'] as $k) {
            $keys[] = 'design.home.tiles.settings.'.$k;
            $keys[] = 'design.home.tiles.hints.'.$k;
        }

        foreach (['1', '2', '3', '4', '5'] as $k) {
            $keys[] = 'design.home.tiles.columns.'.$k;
        }

        foreach (TileGeometry::GAPS as $k) {
            $keys[] = 'design.home.tiles.gap.'.$k;
        }

        foreach (array_keys(TileGeometry::SHAPES) as $k) {
            $keys[] = 'design.home.tiles.shapes.'.$k;
        }

        foreach (TileGeometry::RADII as $k) {
            $keys[] = 'design.home.tiles.radius.'.$k;
        }

        foreach (array_keys(TileGeometry::CAPTIONS) as $k) {
            $keys[] = 'design.home.tiles.captions.'.$k;
        }

        foreach (HomeCatalog::TILE_SOURCES as $k) {
            $keys[] = 'design.home.tiles.sources.'.$k;
        }

        foreach (['too_few_tiles', 'too_many_tiles', 'bad_link', 'link_required'] as $k) {
            $keys[] = 'design.home.errors.'.$k;
        }

        foreach (['bg', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, ['max' => 12], $locale), "{$locale}: {$key}");
            }
        }
    }
}
