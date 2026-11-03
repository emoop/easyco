<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\CarouselGeometry;
use App\Storefront\Home\HomeCatalog;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HP-5: the «Карусел» component in the «Начална» zone of the Design screen: the fields the chosen kind does not use are hidden, the recommended size comes from CarouselGeometry (the numbers the tests pin), the
 * settings are two per row, apply → publish makes it live, a bad value is refused under its field, the third carousel cannot be added, and every label, hint and option exists in both languages.
 */
class DesignHomeCarouselZoneTest extends TestCase
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
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'price' => '10.00', 'stock' => 3]);
        $this->actingAsStaff('Administrator');
    }

    private function screen(): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', 'carousel');
    }

    private function labelShown(string $html, string $label): bool
    {
        return preg_match('/>\s*'.preg_quote($label, '/').'\s*</', $html) === 1;
    }

    public function test_the_component_is_offered_and_a_new_section_has_the_defaults(): void
    {
        $screen = $this->screen();
        $s = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0]['settings'];

        $this->assertSame(['products', 'newest', '12', '160', '120', '3:2', '16', '0', true], [$s['kind'], $s['source'], $s['count'], $s['height'], $s['height_phone'], $s['ratio'], $s['gap'], $s['radius'], $s['arrows']]);
        $screen->assertSee('Carousel');
        $this->assertContains('carousel', array_column($screen->instance()->palette(), 'type'));
    }

    public function test_the_fields_the_chosen_kind_does_not_use_are_hidden(): void
    {
        $screen = $this->screen();
        $html = $screen->html();

        // products: the source, the count and the order; no height, no ratio, no radius, no lists
        foreach (['Source', 'Number of products', 'Order'] as $shown) {
            $this->assertTrue($this->labelShown($html, $shown), "products: {$shown}");
        }

        foreach (['Height on a computer', 'Height on a phone', 'Box proportion', 'Corner radius', 'Gap between the items'] as $hidden) {
            $this->assertFalse($this->labelShown($html, $hidden), "products: no {$hidden}");
        }

        $this->assertTrue($this->labelShown($html, 'Arrows'));

        foreach (['blocks', 'images', 'brands'] as $kind) {
            $html = $screen->set('props.settings.kind', $kind)->html();

            foreach (['Height on a computer', 'Height on a phone', 'Box proportion', 'Corner radius', 'Gap between the items', 'Arrows'] as $shown) {
                $this->assertTrue($this->labelShown($html, $shown), "{$kind}: {$shown}");
            }

            foreach (['Source', 'Number of products', 'Order'] as $hidden) {
                $this->assertFalse($this->labelShown($html, $hidden), "{$kind}: no {$hidden}");
            }

            $this->assertSame($kind === 'blocks', $this->labelShown($html, 'HTML blocks'), "{$kind}: the block list");
            $this->assertSame($kind === 'images', $this->labelShown($html, 'Pictures'), "{$kind}: the picture list");
        }
    }

    public function test_the_item_select_follows_the_source_of_a_product_carousel(): void
    {
        BrandModel::query()->create(['name' => 'Acme Brand', 'slug' => 'acme', 'logo_media_asset_id' => null]);
        $screen = $this->screen();

        $this->assertFalse($this->labelShown($screen->html(), 'Item'), 'newest has no item select');
        $screen->set('props.settings.source', 'brand');
        $this->assertTrue($this->labelShown($screen->html(), 'Item'));
        $this->assertStringContainsString('Acme Brand', $screen->html());
    }

    public function test_the_size_hint_numbers_equal_carousel_geometry(): void
    {
        $screen = $this->screen()->set('props.settings.kind', 'images')->set('props.settings.height', '240')->set('props.settings.height_phone', '100')->set('props.settings.ratio', '16:9');
        $d = CarouselGeometry::hint(240, '16:9');
        $p = CarouselGeometry::hint(100, '16:9');

        $this->assertStringContainsString(e((string) __('design.home.carousel.hint_box', [
            'w' => $d['rounded_width'], 'h' => $d['rounded_height'], 'w2' => $d['double_width'], 'h2' => $d['double_height'],
            'pw' => $p['rounded_width'], 'ph' => $p['rounded_height'], 'pw2' => $p['double_width'], 'ph2' => $p['double_height'],
        ])), $screen->html());
        $this->assertSame(430, $d['rounded_width'], '240 × 16/9 = 426.7, to the nearest 10');
        $this->assertSame(850, $d['double_width']);

        $screen->set('props.settings.kind', 'products');
        $card = CarouselGeometry::card(4, 16);
        $this->assertStringContainsString(e((string) __('design.home.carousel.hint_card', ['w' => $card['rounded_width'], 'h' => $card['rounded_image_height'], 'w2' => $card['double_width'], 'h2' => $card['double_image_height']])), $screen->html());
    }

    public function test_the_live_line_counts_the_products_of_a_product_carousel(): void
    {
        $this->assertStringContainsString('The carousel shows 1 products.', $this->screen()->html());
    }

    public function test_the_settings_are_two_per_row_and_the_hints_are_info_icons(): void
    {
        $html = $this->screen()->html();

        $this->assertStringContainsString('--cols-md: repeat(2, minmax(0, 1fr))', substr($html, (int) strpos($html, 'Carousel')), 'a grid of two columns from the medium width');

        foreach (['kind', 'source', 'count', 'sort', 'arrows', 'label'] as $k) {
            $this->assertStringContainsString(e((string) __('design.home.carousel.hints.'.$k)), $html, $k);
        }
    }

    public function test_apply_and_publish_make_a_brands_carousel_live_and_the_chip_says_what_it_is(): void
    {
        $asset = $this->pickerAsset();
        BrandModel::query()->create(['name' => 'Acme', 'slug' => 'acme', 'logo_media_asset_id' => $asset->id]);
        $screen = $this->screen();
        $screen->set('props.settings.kind', 'brands')->set('props.settings.height', '120')->set('props.settings.ratio', '2:1')->set('props.settings.gap', '8')->set('props.settings.radius', '4')->set('props.settings.label', 'Our brands');
        $screen->call('apply')->assertHasNoErrors()->assertSee('Carousel: Brands, 120px')->call('publish')->assertHasNoErrors();

        $s = (array) json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), true)['sections'][0]['settings'];
        $this->assertSame(['brands', '120', '2:1', '8', '4', 'Our brands'], [$s['kind'], $s['height'], $s['ratio'], $s['gap'], $s['radius'], $s['label']]);

        $page = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('sf-car sf-car-k-brands sf-car-box sf-car-h-120 sf-car-hp-120 sf-car-r-2-1 sf-car-g-8 sf-car-cr-4', $page);
        $this->assertStringContainsString('alt="Acme"', $page);
        $screen->assertSet('dirty', false);
    }

    public function test_a_blocks_carousel_is_applied_as_a_list_of_ids_in_order_and_images_with_their_alt(): void
    {
        $a = HtmlBlockModel::query()->create(['title' => 'A block', 'body' => '<p>a</p>']);
        $b = HtmlBlockModel::query()->create(['title' => 'B block', 'body' => '<p>b</p>']);
        $screen = $this->screen()->set('props.settings.kind', 'blocks')->set('props.settings.block_items', [['block' => (string) $b->id], ['block' => (string) $a->id]]);
        $screen->call('apply')->assertHasNoErrors();

        $stored = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0]['settings'];
        $this->assertSame([$b->id, $a->id], $stored['blocks']);

        $asset = $this->pickerAsset();
        $screen->set('props.settings.kind', 'images')->set('props.settings.images', [['picture' => (string) $asset->id, 'link' => ' ', 'alt' => ' Hello ']]);
        $screen->call('apply')->assertHasNoErrors();
        $stored = $screen->get('tree')['main']['rows'][0]['cells'][0]['items'][0]['settings'];
        $this->assertSame([['picture' => $asset->id, 'link' => null, 'alt' => 'Hello']], $stored['images']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function badValues(): array
    {
        return [
            'height' => ['brands', 'height', '7'],
            'height above the maximum' => ['brands', 'height', '540'],
            'ratio' => ['brands', 'ratio', '5:1'],
            'gap' => ['brands', 'gap', '10'],
            'radius' => ['brands', 'radius', '7'],
            'count' => ['products', 'count', '7'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_a_bad_value_is_refused_under_its_field_and_nothing_is_published(string $kind, string $key, string $value): void
    {
        $screen = $this->screen()->set('props.settings.kind', $kind)->set('props.settings.'.$key, $value)->call('apply')->assertHasErrors(['props.settings.'.$key]);
        $screen->call('publish');

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), $key);
    }

    public function test_an_empty_blocks_list_and_a_link_without_alt_are_refused(): void
    {
        $screen = $this->screen()->set('props.settings.kind', 'blocks')->call('apply');
        $screen->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'no block');

        $asset = $this->pickerAsset();
        $screen = $this->screen()->set('props.settings.kind', 'images')->set('props.settings.images', [['picture' => (string) $asset->id, 'link' => '/sale', 'alt' => '']])->call('apply');
        $screen->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::HOME_PUBLISHED_KEY), 'a link and no alt');
    }

    public function test_a_third_carousel_cannot_be_added_and_the_palette_says_why(): void
    {
        $screen = $this->screen()->call('addNode', 'carousel');
        $this->assertCount(2, $screen->get('tree')['main']['rows'][0]['cells'][0]['items']);

        $palette = array_column($screen->instance()->palette(), null, 'type');
        $this->assertSame('At most 2 carousels on the home page.', $palette['carousel']['reason']);
        $this->assertNull($palette['heading']['reason']);
        $this->assertNull($palette['product_grid']['reason'], 'a product carousel does not count against the product grid limit');

        $screen->call('addNode', 'carousel');
        $this->assertCount(2, $screen->get('tree')['main']['rows'][0]['cells'][0]['items'], 'the third one is refused');
    }

    public function test_every_label_hint_option_and_error_exists_in_both_languages(): void
    {
        $keys = ['design.home.types.carousel', 'design.home.carousel.summary', 'design.home.carousel.summary_products', 'design.home.carousel.count_line', 'design.home.carousel.none', 'design.home.carousel.hint_box', 'design.home.carousel.hint_card', 'design.home.carousel.block_add', 'design.home.carousel.image_add',
            'storefront.carousel.label', 'storefront.carousel.previous', 'storefront.carousel.next'];

        foreach (['too_many_carousels', 'too_few_blocks', 'too_many_blocks', 'too_few_images', 'too_many_images', 'alt_required'] as $k) {
            $keys[] = 'design.home.errors.'.$k;
        }

        foreach (['kind', 'source', 'entity', 'count', 'sort', 'height', 'height_phone', 'ratio', 'gap', 'radius', 'arrows', 'label', 'blocks', 'block', 'images', 'picture', 'link', 'alt'] as $k) {
            $keys[] = 'design.home.carousel.settings.'.$k;
        }

        foreach (['kind', 'source', 'entity', 'count', 'sort', 'height', 'height_phone', 'ratio', 'gap', 'radius', 'arrows', 'label', 'blocks', 'images'] as $k) {
            $keys[] = 'design.home.carousel.hints.'.$k;
        }

        foreach (['kinds' => CarouselGeometry::KINDS, 'counts' => CarouselGeometry::COUNTS, 'heights' => CarouselGeometry::heights(), 'ratios' => CarouselGeometry::RATIOS] as $group => $list) {
            foreach ($list as $k) {
                $keys[] = 'design.home.carousel.'.$group.'.'.$k;
            }
        }

        foreach (CarouselGeometry::GAPS as $k) {
            $keys[] = 'design.home.tiles.gap.'.$k;
        }

        foreach (CarouselGeometry::RADII as $k) {
            $keys[] = 'design.home.grid.radius.'.$k;
        }

        foreach (HomeCatalog::PRODUCT_SOURCES as $k) {
            $keys[] = 'design.home.products.sources.'.$k;
        }

        foreach (['bg', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, ['max' => 2], $locale), "{$locale}: {$key}");
            }
        }
    }
}
