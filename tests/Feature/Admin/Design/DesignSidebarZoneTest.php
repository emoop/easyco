<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Filters\FilterCatalog;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P1: the «Сайдбар» zone of the «Дизайн» screen (it replaces the three «Филтър по марка / сезон / етикет» controls of «Каталог», CT-4): it opens at today's filters in today's order, each row has «Показвай» (and, for a
 * built-in, its kind), «Приложи» writes a draft, «Публикувай» writes the list and the three kinds into the «Каталог» JSON where they have always lived, a bad row is refused, and «Каталог» carries the kinds through
 * untouched.
 */
class DesignSidebarZoneTest extends TestCase
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
        Storage::fake('public');
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue']);
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['is_filterable' => true, 'filter_display' => 'list']);
        FilterCatalog::forget();
    }

    private function screen(string $zone = 'sidebar'): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    /** the rows of the panel as `key => [show, kind]` in order @return array<string, array{0: bool, 1: ?string}> */
    private function rows(Testable $screen): array
    {
        $out = [];

        foreach (array_values((array) $screen->get('props.filters')) as $row) {
            $out[$row['key']] = [$row['show'], $row['kind'] ?? null];
        }

        return $out;
    }

    public function test_the_zone_is_in_the_list_and_opens_at_todays_filters_in_todays_order(): void
    {
        $screen = $this->screen();

        $this->assertArrayHasKey('sidebar', DesignScreen::ZONES);
        $this->assertSame(['brand' => [true, 'list'], 'season' => [true, 'list'], '1' => [true, null], 'tag' => [false, 'list']], $this->rows($screen), 'brand, season, the attributes, tag (off by default)');
        $this->assertStringContainsString('Colour', $screen->html());
        $this->assertStringContainsString('Show', $screen->html());
    }

    public function test_catalog_no_longer_shows_the_three_kinds_and_still_carries_them(): void
    {
        $html = $this->screen('catalog')->html();

        foreach (['Brand filter', 'Season filter', 'Tag filter'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }

        // a stored (older) choice survives an apply and a publish of «Каталог»
        $this->screen()->set('props.filters', $this->screen()->get('props.filters'))->call('apply')->assertHasNoErrors();
        PageDesignStore::publish(PageDesign::CATALOG, ['filterBrand' => 'links'] + PageDesignStore::values(PageDesign::CATALOG));

        $catalog = $this->screen('catalog')->set('props.cols', '3')->call('apply')->assertHasNoErrors();
        $this->assertSame('links', $this->stored(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY)['filterBrand'], 'the draft keeps the kind it was given');

        $catalog->call('publish')->assertHasNoErrors();
        $this->assertSame(['3', 'links'], [$this->stored(AppearanceSettings::CATALOG_DESIGN_KEY)['cols'], $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY)['filterBrand']]);
    }

    public function test_apply_writes_the_draft_and_publish_makes_the_list_and_the_kinds_live(): void
    {
        $screen = $this->screen();
        $rows = array_values((array) $screen->get('props.filters'));
        // Colour first, the tag shown as buttons, season hidden, brand as links
        $new = [
            ['key' => '1', 'show' => true],
            ['key' => 'tag', 'show' => true, 'kind' => 'buttons'],
            ['key' => 'season', 'show' => false, 'kind' => 'list'],
            ['key' => 'brand', 'show' => true, 'kind' => 'links'],
        ];
        $this->assertCount(4, $rows);

        $screen->set('props.filters', $new)->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY);
        $this->assertSame(['1', 'tag', 'season', 'brand'], array_column($draft['filters'], 'key'));
        $this->assertSame(['brand' => 'links', 'season' => 'list', 'tag' => 'buttons'], $draft['kinds']);

        $screen->call('publish')->assertHasNoErrors();

        $this->assertSame([['key' => '1', 'show' => true], ['key' => 'tag', 'show' => true], ['key' => 'season', 'show' => false], ['key' => 'brand', 'show' => true]], $this->stored(AppearanceSettings::SIDEBAR_FILTERS_KEY)['filters']);
        $live = $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY);
        $this->assertSame(['links', 'list', 'buttons'], [$live['filterBrand'], $live['filterSeason'], $live['filterTag']], 'the kinds are in the «Каталог» JSON, where they have always been');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY), 'the draft is forgotten');
        $this->assertFalse($this->screen()->get('dirty'));
    }

    public function test_an_older_off_is_shown_as_hidden_and_a_publish_does_not_disturb_the_other_catalog_choices(): void
    {
        PageDesignStore::publish(PageDesign::CATALOG, ['filterSeason' => 'off', 'cols' => '3', 'filterTag' => 'buttons'] + PageDesignStore::values(PageDesign::CATALOG));

        $screen = $this->screen();
        $this->assertSame(['brand' => [true, 'list'], 'season' => [false, 'list'], '1' => [true, null], 'tag' => [true, 'buttons']], $this->rows($screen));

        $screen->set('props.filters', array_values((array) $screen->get('props.filters')))->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertSame('3', $this->stored(AppearanceSettings::CATALOG_DESIGN_KEY)['cols']);
    }

    public function test_a_bad_row_is_refused_under_the_list_and_nothing_is_written(): void
    {
        foreach ([
            [['key' => 'zzz', 'show' => true]],
            [['key' => 'brand', 'show' => true], ['key' => 'brand', 'show' => false]],
            [['key' => 'brand', 'show' => true, 'kind' => 'chips']],
            [['key' => 'brand', 'show' => 'yes']],
        ] as $rows) {
            $screen = $this->screen()->set('props.filters', $rows)->call('apply');

            $screen->assertHasErrors(['props.filters']);
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SIDEBAR_FILTERS_DRAFT_KEY), json_encode($rows));
        }
    }

    public function test_the_defaults_button_puts_todays_filters_into_the_working_copy(): void
    {
        $screen = $this->screen();
        $screen->set('props.filters', [['key' => 'tag', 'show' => true, 'kind' => 'buttons'], ['key' => 'brand', 'show' => false, 'kind' => 'list']])->call('apply');

        $screen->call('resetToDefault');

        $this->assertSame(['brand' => [true, 'list'], 'season' => [true, 'list'], '1' => [true, null], 'tag' => [false, 'list']], $this->rows($screen));
    }

    public function test_the_info_icons_are_there(): void
    {
        $html = $this->screen()->html();

        $this->assertStringContainsString('aria-describedby="ds-hint-show-', $html);
        $this->assertStringContainsString('aria-describedby="ds-hint-filters-', $html);
    }
}
