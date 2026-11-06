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
 * P4: the «Под галерията» zone of the «Дизайн» screen: it opens with no slot (every product page as it always was), a repeater of at most 6 slots (a carousel, «Подобни продукти», an HTML block), each with an optional
 * heading; «Приложи» writes a draft, «Публикувай» the set and its compiled copy; a bad value is refused and nothing is written; the signed preview is a real product page with the working copy.
 */
class DesignBelowZoneTest extends TestCase
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

        foreach ([1, 2, 3] as $id) {
            $this->makeProduct($id, ['name' => "P{$id}", 'slug' => "p{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => 3, 'timeline' => "2026-01-0{$id} 10:00:00"]);
        }
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'below');
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    private function block(string $marker): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $marker, 'body' => "<p>{$marker}</p>"]);
    }

    /** @return array<string, mixed> a slot of the panel */
    private function form(string $type, string $heading = '', ?int $block = null, array $carousel = []): array
    {
        return ['type' => $type, 'heading' => $heading, 'block' => $block, 'carousel' => $carousel + ['kind' => 'products', 'source' => 'newest', 'entity' => null, 'count' => '12', 'sort' => 'newest', 'arrows' => true, 'label' => '', 'block_items' => []]];
    }

    public function test_the_zone_is_in_the_menu_next_to_the_product_page_and_opens_with_no_slot(): void
    {
        $screen = $this->screen();

        $screen->assertSet('zone', 'below');
        $this->assertSame([], (array) $screen->get('props.slots'));
        $this->assertSame('Below the gallery', __('design.zones.below'));
        $this->assertStringContainsString('Below the gallery', $screen->html());

        $zones = array_keys(DesignScreen::ZONES);
        $this->assertSame(array_search('product', $zones, true) + 1, array_search('below', $zones, true), 'right after «Продуктова страница»');
    }

    /** ★ «Приложи» writes the draft only; «Публикувай» makes the slots live, in the saved order, and the shortcut «Подобни продукти» is a carousel with the source `similar` */
    public function test_apply_writes_the_draft_and_publish_makes_the_slots_live_in_order(): void
    {
        $block = $this->block('BLOCKSLOTTEXT');
        $screen = $this->screen();

        $screen->set('props.slots', [
            'a' => $this->form('html_block', 'First heading', $block->id),
            'b' => $this->form('similar', 'Similar heading', null, ['count' => '8']),
        ])->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_PUBLISHED_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::BELOW_DRAFT_KEY);
        $this->assertSame(['html_block', 'carousel'], array_column($draft['slots'], 'type'));
        $this->assertSame('similar', $draft['slots'][1]['settings']['source']);
        $this->assertSame('products', $draft['slots'][1]['settings']['kind']);
        $this->assertSame('8', $draft['slots'][1]['settings']['count']);

        $screen->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::BELOW_PUBLISHED_KEY);
        $this->assertSame('First heading', $published['slots'][0]['heading']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_DRAFT_KEY), 'the draft is forgotten');
        $this->assertFalse($this->screen()->get('dirty'));

        $again = array_values((array) $this->screen()->get('props.slots'));
        $this->assertSame(['html_block', 'similar'], array_column($again, 'type'), 'the panel reads the set back, «Подобни продукти» as its shortcut');

        $this->app->forgetScopedInstances();
        $html = $this->get('/product/p1')->assertOk()->getContent();
        $this->assertStringContainsString('BLOCKSLOTTEXT', $html);
        $this->assertStringContainsString('Similar heading', $html);
        $this->assertTrue(strpos($html, 'First heading') < strpos($html, 'Similar heading'));
    }

    /** ★ more than 6 slots, a heading that is too long and an HTML block slot with no block are refused: nothing is written */
    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        $screen = $this->screen();
        $seven = [];

        foreach (range(1, 7) as $n) {
            $seven["s{$n}"] = $this->form('similar');
        }

        foreach ([$seven, ['x' => $this->form('similar', str_repeat('я', 81))], ['x' => $this->form('html_block')]] as $bad) {
            $screen->set('props.slots', $bad)->call('apply')->call('publish');

            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_PUBLISHED_KEY));
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_DRAFT_KEY), 'a refused «Apply» writes no draft either');
        }

        // six are fine
        $screen->set('props.slots', array_slice($seven, 0, 6))->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertCount(6, $this->stored(AppearanceSettings::BELOW_PUBLISHED_KEY)['slots']);
    }

    /** removing every slot publishes «nothing»: the product page is the page it always was */
    public function test_removing_all_the_slots_brings_the_page_back(): void
    {
        $screen = $this->screen();
        $screen->set('props.slots', ['a' => $this->form('similar', 'SIMILARHEADING')])->call('apply')->call('publish')->assertHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('SIMILARHEADING', $this->get('/product/p1')->getContent());

        $screen->set('props.slots', [])->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BELOW_PUBLISHED_KEY));
        $this->app->forgetScopedInstances();
        $this->assertStringNotContainsString('sf-below', $this->get('/product/p1')->getContent());
    }

    /** the signed preview: a real product page with the DRAFT applied; without the signature it is the storefront's 404 */
    public function test_the_signed_preview_shows_the_working_copy(): void
    {
        $screen = $this->screen();
        $screen->set('props.slots', ['a' => $this->form('similar', 'DRAFTHEADING')])->call('apply');

        $url = \App\Http\Controllers\Storefront\DesignPreviewController::previewUrl('below');
        $this->app->forgetScopedInstances();
        $page = $this->get($url)->assertOk();

        $page->assertSee('DRAFTHEADING', false);
        $page->assertHeader('X-Robots-Tag', 'noindex');

        // not published: the public page has nothing
        $this->app->forgetScopedInstances();
        $this->assertStringNotContainsString('DRAFTHEADING', $this->get('/product/p1')->getContent());

        $this->app->forgetScopedInstances();
        $this->get(route('storefront.design.preview', ['zone' => 'below'], false))->assertNotFound();
    }
}
