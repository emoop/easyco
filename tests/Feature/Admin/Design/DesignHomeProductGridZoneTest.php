<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeCatalog;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HP-4: the «Продуктова решетка» component in the «Начална» zone of the Design screen: it is offered by the palette, the item select follows the source, the live line says how many products the grid
 * resolves to, apply → publish makes it live, a bad value is refused under its field, the settings are two per row, and every label, hint and option exists in both languages.
 */
class DesignHomeProductGridZoneTest extends TestCase
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
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
        $this->actingAsStaff('Administrator');
    }

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', 'product_grid');
    }

    public function test_the_component_is_offered_and_a_new_section_has_the_defaults(): void
    {
        $screen = $this->screen();
        $s = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0]['settings'];

        $this->assertSame(['newest', null, '12', 'newest', '4', '2', '16'], [$s['source'], $s['entity'], $s['count'], $s['sort'], $s['columns'], $s['columns_phone'], $s['gap']]);
        $screen->assertSee('Product grid');
        $this->assertContains('product_grid', array_column($screen->instance()->palette(), 'type'));
    }

    public function test_the_item_select_follows_the_source(): void
    {
        SeasonModel::query()->create(['name' => 'Winter Edition', 'slug' => 'winter']);
        $screen = $this->screen();

        $item = static fn (string $html): bool => preg_match('/>\s*Item\s*</', $html) === 1;
        $this->assertFalse($item($screen->html()), 'newest has no item select');

        $screen->set('props.settings.source', 'season');
        $this->assertTrue($item($screen->html()), 'a season has one');
        $this->assertStringContainsString('Winter Edition', $screen->html());
        $this->assertStringNotContainsString('Clothing', substr($screen->html(), (int) strpos($screen->html(), 'Winter Edition') - 600), 'a season grid offers seasons, not categories');

        $screen->set('props.settings.source', 'category');
        $this->assertStringContainsString('Clothing', $screen->html());
    }

    public function test_the_live_line_counts_the_products_the_grid_resolves_to(): void
    {
        $this->makeCategory(2, 'Empty', 'empty');
        $screen = $this->screen();

        $this->assertStringContainsString('The grid shows 1 products.', $screen->html());

        $screen->set('props.settings.source', 'category')->set('props.settings.entity', '1');
        $this->assertStringContainsString('The grid shows 1 products.', $screen->html());

        $screen->set('props.settings.entity', '2');
        $this->assertStringContainsString('No products for this choice', $screen->html());
    }

    public function test_the_settings_are_two_per_row_and_the_hints_are_info_icons(): void
    {
        $html = $this->screen()->html();

        $this->assertStringContainsString('--cols-md: repeat(2, minmax(0, 1fr))', substr($html, (int) strpos($html, 'Product grid')), 'a grid of two columns from the medium width');

        foreach (['source', 'count', 'sort', 'columns', 'columns_phone', 'gap'] as $k) {
            $this->assertStringContainsString(e((string) __('design.home.products.hints.'.$k)), $html, $k);
        }
    }

    public function test_apply_and_publish_make_the_section_live(): void
    {
        $screen = $this->screen();
        $screen->set('props.settings.source', 'category')->set('props.settings.entity', '1')->set('props.settings.count', '8')->set('props.settings.sort', 'name_asc')
            ->set('props.settings.columns', '3')->set('props.settings.columns_phone', '1')->set('props.settings.gap', '24');
        $screen->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $stored = (array) json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), true);
        $s = $stored['sections'][0]['settings'];

        $this->assertSame(['category', 1, '8', 'name_asc', '3', '1', '24'], [$s['source'], $s['entity'], $s['count'], $s['sort'], $s['columns'], $s['columns_phone'], $s['gap']]);

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('sf-hp-products sf-hp-prc-3 sf-hp-prp-1 sf-hp-prg-24', $page);
        $this->assertStringContainsString('>P1<', $page);
        $screen->assertSet('dirty', false);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badValues(): array
    {
        return ['count' => ['count', '7'], 'sort' => ['sort', 'price_asc'], 'columns' => ['columns', '7'], 'columns_phone' => ['columns_phone', '3'], 'gap' => ['gap', '10']];
    }

    #[DataProvider('badValues')]
    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_published(string $key, string $value): void
    {
        $screen = $this->screen()->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
        $screen->call('publish');

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), $key);
    }

    public function test_a_source_that_needs_an_item_is_refused_without_one(): void
    {
        $screen = $this->screen()->set('props.settings.source', 'brand')->call('apply');
        $screen->call('publish');

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY));
    }

    public function test_a_third_grid_cannot_be_added_and_the_palette_says_why(): void
    {
        $screen = $this->screen()->call('addNode', 'product_grid');
        $items = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'];
        $this->assertCount(2, $items);

        $palette = array_column($screen->instance()->palette(), null, 'type');
        $this->assertSame('At most 2 product grids on the home page.', $palette['product_grid']['reason']);
        $this->assertNull($palette['heading']['reason']);

        $screen->call('addNode', 'product_grid');
        $this->assertCount(2, $screen->get('tree')['main']['rows'][0]['cells'][0]['items'], 'the third one is refused');
    }

    public function test_the_chip_says_what_the_section_is(): void
    {
        $screen = $this->screen();
        $screen->set('props.settings.count', '20')->call('apply');

        $screen->assertSee('Products: Newest, 20');
    }

    public function test_every_label_hint_option_and_error_exists_in_both_languages(): void
    {
        $keys = ['design.home.types.product_grid', 'design.home.products.summary', 'design.home.products.count_line', 'design.home.products.none', 'design.home.errors.too_many_product_grids'];

        foreach (['source', 'entity', 'count', 'sort', 'columns', 'columns_phone', 'gap'] as $k) {
            $keys[] = 'design.home.products.settings.'.$k;
            $keys[] = 'design.home.products.hints.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_SOURCES as $k) {
            $keys[] = 'design.home.products.sources.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_COUNTS as $k) {
            $keys[] = 'design.home.products.counts.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_SORTS as $k) {
            $keys[] = 'design.home.products.sorts.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_COLUMNS as $k) {
            $keys[] = 'design.home.tiles.columns.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_PHONE_COLUMNS as $k) {
            $keys[] = 'design.home.tiles.columns.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_GAPS as $k) {
            $keys[] = 'design.home.tiles.gap.'.$k;
        }

        foreach (['bg', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, ['max' => 2], $locale), "{$locale}: {$key}");
            }
        }
    }
}
