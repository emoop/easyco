<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Below\BelowSlots;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P4b: the «Секции на продукта» zone of the «Дизайн» screen (the definitions of the product's sections): it opens with no section, a repeater of at most 8 sections (title, source, the general text), each with a stable
 * key made when it is added; «Приложи» writes a draft, «Публикувай» the set and its compiled copy; a bad value is refused and nothing is written; the «Табове» slot of «Под галерията» offers the defined sections;
 * the «Акордеон» component is in the right column's list.
 */
class DesignSectionsZoneTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const KEY = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $this->makeCategory(1, 'Dresses', 'dresses');
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    private function screen(string $zone = 'sections'): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    /** @return array<string, mixed> a section of the panel */
    private function form(string $title, string $source = 'product_or_global', string $text = '', ?string $key = self::KEY): array
    {
        return ['key' => $key, 'title' => $title, 'source' => $source, 'text' => $text];
    }

    public function test_the_zone_is_in_the_menu_after_below_the_gallery_and_opens_with_no_section(): void
    {
        $screen = $this->screen();

        $screen->assertSet('zone', 'sections');
        $this->assertSame([], (array) $screen->get('props.sections'));
        $this->assertSame('Product sections', __('design.zones.sections'));
        $this->assertStringContainsString('Product sections', $screen->html());

        $zones = array_keys(DesignScreen::ZONES);
        $this->assertSame(array_search('below', $zones, true) + 1, array_search('sections', $zones, true));
    }

    /** ★ «Приложи» writes the draft only; «Публикувай» makes the sections live; a section added in the panel has a key */
    public function test_apply_writes_the_draft_and_publish_makes_the_sections_live(): void
    {
        $screen = $this->screen();
        $screen->set('props.sections', [
            'a' => $this->form('Delivery', 'global', '<p>GLOBALDELIVERY</p>'),
            'b' => $this->form('Size', 'product', '', '22222222-2222-4222-8222-222222222222'),
            'c' => $this->form('Composition', 'product_or_global', '<p>GLOBALCOMPOSITION</p>', null),
        ])->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::SECTIONS_DRAFT_KEY);
        $this->assertSame(['Delivery', 'Size', 'Composition'], array_column($draft['sections'], 'title'));
        $this->assertSame('', $draft['sections'][1]['text'], 'the general text means nothing for a product-only source');
        $this->assertTrue(SectionSet::isKey($draft['sections'][2]['key']), 'a section with no key gets one');

        $screen->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::SECTIONS_PUBLISHED_KEY);
        $this->assertCount(3, $published['sections']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_DRAFT_KEY));

        $again = array_values((array) $this->screen()->get('props.sections'));
        $this->assertSame(['Delivery', 'Size', 'Composition'], array_column($again, 'title'));
        $this->assertSame($published['sections'][1]['key'], $again[1]['key'], 'the key is stable across loads');
    }

    /** ★ more than 8 sections, an empty or too long title, a bad source: refused, nothing is written */
    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        $screen = $this->screen();
        $nine = [];

        foreach (range(1, 9) as $n) {
            $nine["s{$n}"] = $this->form("T{$n}", 'global', '', sprintf('%08d-0000-4000-8000-000000000000', $n));
        }

        foreach ([$nine, ['x' => $this->form('')], ['x' => $this->form(str_repeat('я', 61))], ['x' => $this->form('T', 'nope')]] as $bad) {
            $screen->set('props.sections', $bad)->call('apply')->call('publish');

            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY));
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_DRAFT_KEY));
        }

        $screen->set('props.sections', array_slice($nine, 0, 8))->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertCount(8, $this->stored(AppearanceSettings::SECTIONS_PUBLISHED_KEY)['sections']);
    }

    /** the signed preview: a real product page with the DRAFT definitions (the right column of the shop decides where they show) */
    public function test_the_signed_preview_is_a_product_page(): void
    {
        $screen = $this->screen();
        $screen->set('props.sections', ['a' => $this->form('Delivery', 'global', '<p>PREVIEWTEXT</p>')])->call('apply');

        $this->app->forgetScopedInstances();
        $this->get(\App\Http\Controllers\Storefront\DesignPreviewController::previewUrl('sections'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex');

        $this->app->forgetScopedInstances();
        $this->get(route('storefront.design.preview', ['zone' => 'sections'], false))->assertNotFound();
    }

    /** ★ the «Табове» slot of «Под галерията» takes the defined sections (a multi-select), is saved and refused without a section */
    public function test_the_tabs_slot_in_below_the_gallery(): void
    {
        [$clean] = SectionSet::validate(['sections' => [['key' => self::KEY, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>x</p>']]]);
        SectionStore::publish($clean);

        $screen = $this->screen('below');
        $this->assertStringContainsString('Tabs', $screen->html().__('design.below.types.tabs'));

        $form = ['type' => 'tabs', 'heading' => 'TABSHEADING', 'block' => null, 'sections' => [self::KEY], 'carousel' => ['kind' => 'products', 'source' => 'newest', 'entity' => null, 'count' => '12', 'sort' => 'newest', 'arrows' => true, 'label' => '', 'block_items' => []]];
        $screen->set('props.slots', ['a' => $form])->call('apply')->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::BELOW_PUBLISHED_KEY);
        $this->assertSame(['type' => 'tabs', 'heading' => 'TABSHEADING', 'sections' => [self::KEY]], $published['slots'][0]);

        $back = array_values((array) $this->screen('below')->get('props.slots'));
        $this->assertSame('tabs', $back[0]['type']);
        $this->assertSame([self::KEY], $back[0]['sections']);

        // no section chosen: refused, the published set is what it was
        $before = app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_PUBLISHED_KEY);
        $screen->set('props.slots', ['a' => ['sections' => []] + $form])->call('apply')->call('publish');
        $this->assertSame($before, app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_PUBLISHED_KEY));
        $this->assertSame([], BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => '', 'sections' => []]]])[0]['slots']);
    }

    /** the «Акордеон» component is in the right column's catalogue (one at most), with its «first open» setting, and is not in the default layout */
    public function test_the_accordion_component_is_offered_by_the_right_column(): void
    {
        $catalog = \App\Storefront\ProductSummary\ProductSummaryCatalog::all();

        $this->assertArrayHasKey('accordion', $catalog);
        $this->assertSame(1, $catalog['accordion']['max']);
        $this->assertSame(['first_open' => ['kind' => 'bool', 'default' => false]], $catalog['accordion']['schema']);
        $this->assertNotContains('accordion', array_column(\App\Storefront\ProductSummary\ProductSummaryLayout::default()['components'], 'type'), 'not in the default layout: the page is what it was');

        $this->assertSame('Accordion', __('design.summary.types.accordion'));
        $this->assertSame('Акордеон', __('design.summary.types.accordion', [], 'bg'));
    }
    /** ★ P4c: the «Мета информация» switches and the «HTML блокове» list are in the panel, applied, published and loaded back; another source keeps its old shape; 11 blocks or none are refused */
    public function test_the_meta_and_blocks_sources_in_the_panel(): void
    {
        $a = (int) \App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'Sizes A', 'body' => '<p>A</p>'])->id;
        $b = (int) \App\Content\HtmlBlocks\HtmlBlockModel::query()->create(['title' => 'Sizes B', 'body' => '<p>B</p>'])->id;

        $screen = $this->screen();
        $this->assertStringContainsString('Meta information', $screen->html().__('design.sections.sources.meta'));
        $screen->set('props.sections', [
            'm' => ['key' => self::KEY, 'title' => 'Meta', 'source' => 'meta', 'text' => '', 'show_seasons' => false, 'show_tags' => true, 'block_items' => []],
            'b' => ['key' => '22222222-2222-4222-8222-222222222222', 'title' => 'Sizes', 'source' => 'blocks', 'text' => '', 'show_seasons' => true, 'show_tags' => true, 'block_items' => [['block' => $b], ['block' => $a]]],
            'g' => ['key' => '33333333-3333-4333-8333-333333333333', 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>', 'show_seasons' => false, 'show_tags' => false, 'block_items' => [['block' => $a]]],
        ])->call('apply')->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::SECTIONS_PUBLISHED_KEY)['sections'];
        $this->assertSame(['seasons' => false, 'tags' => true], $published[0]['meta']);
        $this->assertSame([$b, $a], $published[1]['blocks'], 'the chosen order');
        $this->assertSame(['key', 'title', 'source', 'text'], array_keys($published[2]), 'another source: no new keys');

        $back = array_values((array) $this->screen()->get('props.sections'));
        $this->assertFalse($back[0]['show_seasons']);
        $this->assertSame([(string) $b, (string) $a], array_map(static fn (array $row): string => (string) $row['block'], array_values($back[1]['block_items'])), 'the select shows the ids as text');

        // none and eleven blocks are refused, nothing changes
        $before = app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY);
        $many = array_map(static fn (int $n): array => ['block' => $a + $n], range(0, 10));

        foreach ([[], $many] as $items) {
            $screen->set('props.sections', ['b' => ['key' => self::KEY, 'title' => 'Sizes', 'source' => 'blocks', 'text' => '', 'block_items' => $items]])->call('apply')->call('publish');
            $this->assertSame($before, app(SiteSettingsRepository::class)->get(AppearanceSettings::SECTIONS_PUBLISHED_KEY));
        }
    }
}