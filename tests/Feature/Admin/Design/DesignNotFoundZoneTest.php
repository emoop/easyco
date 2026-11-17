<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * NF-1: the «Страница 404» zone of the «Дизайн» screen: in the menu right after «Съдържание», opens with the default page, the fields in the order of the owner's list, «Приложи» writes a draft, «Публикувай» the set and
 * its compiled copy, a bad value is refused and nothing is written, the signed preview is the 404 page with the working copy.
 */
class DesignNotFoundZoneTest extends TestCase
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
        $this->seedPricing();
        $this->makeCategory(1, 'Dresses', 'dresses');
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [1], 'price' => '10.00', 'stock' => 3]);
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'notfound');
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    public function test_the_zone_is_in_the_menu_right_after_the_content_zone_and_opens_with_the_defaults(): void
    {
        $screen = $this->screen();

        $screen->assertSet('zone', 'notfound');
        $this->assertSame('404 page', __('design.zones.notfound'));
        $this->assertStringContainsString('404 page', $screen->html());

        $zones = array_keys(DesignScreen::ZONES);
        $this->assertSame(array_search('content', $zones, true) + 1, array_search('notfound', $zones, true), 'right after «Съдържание»');

        $this->assertSame('Page not found', $screen->get('props.title'));
        $this->assertTrue($screen->get('props.search'));
        $this->assertSame('tiles', $screen->get('props.bottom'));
        $this->assertSame('8', $screen->get('props.tiles'));
        $this->assertTrue($screen->get('props.button'));

        // the menu of the screen lists it after «Съдържание»
        $html = $screen->html();
        $this->assertGreaterThan(strpos($html, 'selectZone(\'content\')'), strpos($html, 'selectZone(\'notfound\')'));
    }

    /** the fields are the owner's list, in its order, one per row, every explanation an info hint */
    public function test_the_fields_are_in_the_owners_order_with_info_hints(): void
    {
        $zone = new \App\Filament\Design\Zones\NotFoundZone;
        $names = array_map(static fn ($component): string => (string) (method_exists($component, 'getName') ? $component->getName() : $component->getStatePath(false)), $zone->propsComponents([], '', null));

        $this->assertSame(['title', 'text', 'search', 'bottom', 'tiles', 'products', 'block', 'button'], $names);

        $html = $this->screen()->html();
        $this->assertStringContainsString('Title', $html);
        $this->assertStringContainsString('Content below', $html);
        $this->assertStringContainsString('ds-hint', $html, 'explanations are info hints');
    }

    /** ★ «Приложи» writes the draft only; «Публикувай» makes the page live */
    public function test_apply_writes_the_draft_and_publish_makes_it_live(): void
    {
        $screen = $this->screen();

        $screen->set('props.title', 'Gone fishing')->set('props.text', 'A line')->set('props.bottom', 'none')->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::NOTFOUND_PUBLISHED_KEY), 'the shop is not touched by «Apply»');
        $this->assertSame('Gone fishing', $this->stored(AppearanceSettings::NOTFOUND_DRAFT_KEY)['title']);

        $this->app->forgetScopedInstances();
        $this->assertStringNotContainsString('Gone fishing', $this->get('/abvgd')->getContent());

        $screen->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::NOTFOUND_PUBLISHED_KEY);
        $this->assertSame('Gone fishing', $published['title']);
        $this->assertSame('none', $published['bottom']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::NOTFOUND_DRAFT_KEY));

        $this->app->forgetScopedInstances();
        $this->get('/abvgd')->assertNotFound()->assertSee('Gone fishing');
    }

    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        $screen = $this->screen();

        $screen->set('props.title', '')->call('publish');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::NOTFOUND_PUBLISHED_KEY));
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::NOTFOUND_DRAFT_KEY));

        $screen->set('props.title', 'Ok')->set('props.bottom', 'block')->set('props.block', null)->call('apply');
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::NOTFOUND_DRAFT_KEY), 'an HTML block must be chosen');
    }

    public function test_the_product_grid_and_the_html_block_are_saved_and_read_back(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'Promo', 'body' => '<p>promo</p>']);
        $screen = $this->screen();

        $screen->set('props.bottom', 'products')->set('props.products', ['source' => 'newest', 'entity' => null, 'count' => '8', 'sort' => 'name_asc'])->call('apply')->call('publish')->assertHasNoErrors();
        $published = $this->stored(AppearanceSettings::NOTFOUND_PUBLISHED_KEY);
        $this->assertSame('products', $published['bottom']);
        $this->assertSame(['newest', '8', 'name_asc'], [$published['products']['source'], $published['products']['count'], $published['products']['sort']]);
        $this->assertSame('4', $published['products']['columns'], 'the home grid\'s own default stays');

        $screen = $this->screen();
        $screen->set('props.bottom', 'block')->set('props.block', $block->id)->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertSame($block->id, $this->stored(AppearanceSettings::NOTFOUND_PUBLISHED_KEY)['block']);
    }

    public function test_the_zone_is_blocked_for_a_role_without_the_content_permission(): void
    {
        $this->actingAsStaff('Product Entry');

        $this->get(DesignScreen::getUrl())->assertForbidden();
    }
}
