<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\ProductSummary\ProductSummaryValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * SC-1: the «Дясна колона» zone of the Design screen: the product page's right column as an ordered list of components. The editor (add, move, remove, the locked `title` and `purchase`), the
 * validation, the draft / publish life under the DS-5 button model, the initial settings and the authorisation. What the storefront prints is ProductSummaryRenderTest.
 */
class DesignSummaryZoneTest extends TestCase
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
        return Livewire::test(DesignScreen::class)->call('selectZone', 'summary');
    }

    /** @return list<array<string, mixed>> the components of the working copy, in order */
    private function items(Testable $screen): array
    {
        return $screen->get('tree')['main']['rows'][0]['cells'][0]['items'];
    }

    /** @return list<string> */
    private function types(Testable $screen): array
    {
        return array_column($this->items($screen), 'type');
    }

    private function idOf(Testable $screen, string $type): string
    {
        foreach ($this->items($screen) as $item) {
            if ($item['type'] === $type) {
                return $item['id'];
            }
        }

        $this->fail("no {$type} component");
    }

    /** @return list<string> the types of a stored layout */
    private function storedTypes(string $key): array
    {
        return array_column(json_decode((string) $this->settings()->get($key), true)['components'], 'type');
    }

    // ---- the editor ---------------------------------------------------------------------------------------------------------------------

    public function test_the_zone_starts_from_the_default_layout_with_its_one_cell_selected(): void
    {
        $screen = $this->screen();

        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase'], $this->types($screen));
        $screen->assertSet('selected', ['kind' => 'cell', 'id' => 's-r0-c0'])->assertSet('dirty', false);
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
    }

    public function test_the_palette_offers_only_the_components_that_can_still_be_added(): void
    {
        $screen = $this->screen();
        $offered = array_column($screen->instance()->palette(), 'type');

        $this->assertSame(['sku', 'html_block'], $offered, 'the six that are in the column are at their most (one each)');

        $screen->call('removeNode', $this->idOf($screen, 'brand'));
        $this->assertSame(['brand', 'sku', 'html_block'], array_column($screen->instance()->palette(), 'type'));
    }

    public function test_adding_a_component_is_stored_as_the_draft_at_once_and_the_shop_does_not_change(): void
    {
        $screen = $this->screen()->call('addNode', 'sku');

        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase', 'sku'], $this->types($screen));
        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase', 'sku'], $this->storedTypes(AppearanceSettings::SUMMARY_DRAFT_KEY), 'autosave: the draft equals the working copy');
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());
    }

    public function test_a_component_moves_up_and_down_and_the_new_order_is_stored(): void
    {
        $screen = $this->screen();
        $screen->call('moveNode', $this->idOf($screen, 'price'), 'up')->call('moveNode', $this->idOf($screen, 'price'), 'up');

        $this->assertSame(['brand', 'price', 'title', 'short_description', 'stock', 'purchase'], $this->types($screen));
        $this->assertSame(['brand', 'price', 'title', 'short_description', 'stock', 'purchase'], $this->storedTypes(AppearanceSettings::SUMMARY_DRAFT_KEY));

        $screen->call('moveNode', $this->idOf($screen, 'price'), 'left');
        $this->assertSame(['brand', 'price', 'title', 'short_description', 'stock', 'purchase'], $this->types($screen), 'there is no left or right in a single list');
    }

    // ---- the locked components -----------------------------------------------------------------------------------------------------------

    public function test_the_locked_components_cannot_be_removed_or_duplicated_but_can_be_moved(): void
    {
        $screen = $this->screen();

        foreach (['title', 'purchase'] as $type) {
            $screen->call('removeNode', $this->idOf($screen, $type));
            $this->assertContains($type, $this->types($screen), "{$type} is still there");
            $screen->call('addNode', $type);
            $this->assertSame(1, array_count_values($this->types($screen))[$type], "{$type} is not duplicated");
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), 'nothing was stored by the refusals');

        $screen->call('moveNode', $this->idOf($screen, 'purchase'), 'up');
        $this->assertSame(['brand', 'title', 'short_description', 'price', 'purchase', 'stock'], $this->types($screen));
    }

    public function test_the_refusals_say_why(): void
    {
        $screen = $this->screen()->call('removeNode', $this->idOf($this->screen(), 'title'));
        $screen->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Not allowed')->body('This component is required and cannot be removed.'));

        $this->screen()->call('addNode', 'purchase')->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Not allowed')->body('This component can only be one.'));

        app()->setLocale('bg');
        $this->assertSame('Този компонент е задължителен и не може да се премахне.', __('design.summary.errors.locked_missing'));
        $this->assertSame('Дясна колона', __('design.zones.summary'));
    }

    // ---- validation ----------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badLayouts(): array
    {
        $good = ProductSummaryLayout::default()['components'];
        $c = static fn (string $type, array $settings = []): array => ['type' => $type, 'settings' => $settings];
        $block = static fn (int $id): array => $c('html_block', ['html_block' => $id]);

        return [
            'an unknown type' => [['components' => [...$good, $c('carousel')]], 'components.6'],
            'a second title' => [['components' => [...$good, $c('title')]], 'components.6'],
            'no title' => [['components' => array_values(array_filter($good, static fn (array $x): bool => $x['type'] !== 'title'))], 'components'],
            'no purchase' => [['components' => array_values(array_filter($good, static fn (array $x): bool => $x['type'] !== 'purchase'))], 'components'],
            'two purchase' => [['components' => [...$good, $c('purchase')]], 'components.6'],
            'six html blocks' => [['components' => [...$good, $block(1), $block(2), $block(3), $block(4), $block(5), $block(6)]], 'components.11'],
            'sixteen components' => [['components' => [...$good, $c('sku'), $block(1), $block(2), $block(3), $block(4), $block(5), $c('brand'), $c('brand'), $c('brand'), $c('brand')]], 'components'],
            'an html block with no block chosen' => [['components' => [...$good, $c('html_block', ['html_block' => null])]], 'components.6.settings.html_block'],
            'an html block id out of range' => [['components' => [...$good, $c('html_block', ['html_block' => -3])]], 'components.6.settings.html_block'],
            'a setting that does not exist' => [['components' => [...$good, $c('sku', ['colour' => 'red'])]], 'components.6.settings.colour'],
            'a key that does not exist' => [['components' => $good, 'extra' => 1], 'components'],
            'a list that is not a list' => [['components' => ['a' => $c('title')]], 'components'],
        ];
    }

    /** @param  array<string, mixed>  $layout */
    #[\PHPUnit\Framework\Attributes\DataProvider('badLayouts')]
    public function test_a_layout_that_breaks_a_rule_is_refused_and_nothing_is_saved(array $layout, string $path): void
    {
        [, $errors] = ProductSummaryValidator::validate($layout);

        $this->assertArrayHasKey($path, $errors, json_encode($errors));

        try {
            app(ProductSummaryCompiler::class)->publish($layout, $this->settings());
            $this->fail('the compiler published a layout that does not validate');
        } catch (\InvalidArgumentException) {
            $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
            $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        }
    }

    public function test_the_default_layout_and_the_limits_validate(): void
    {
        $this->assertSame([], ProductSummaryValidator::validate(ProductSummaryLayout::default())[1]);

        $c = static fn (string $type, array $settings = []): array => ['type' => $type, 'settings' => $settings];
        $five = array_map(static fn (int $id): array => $c('html_block', ['html_block' => $id]), [1, 2, 3, 4, 5]);

        // 6 default + sku + 5 blocks = 12: allowed
        $this->assertSame([], ProductSummaryValidator::validate(['components' => [...ProductSummaryLayout::default()['components'], $c('sku'), ...$five]])[1]);
    }

    public function test_a_new_html_block_that_still_needs_a_value_stays_in_the_page_and_is_not_stored(): void
    {
        $screen = $this->screen()->call('addNode', 'html_block');

        $this->assertContains('html_block', $this->types($screen));
        $screen->assertSet('dirty', true);
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));

        $screen->call('apply');
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), 'a refused «Приложи» stores nothing');
    }

    public function test_an_html_block_is_chosen_in_the_panel_and_applied(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Club', 'body' => '<p>Club members save 10%</p>']);
        $screen = $this->screen()->call('addNode', 'html_block');
        $id = $this->idOf($screen, 'html_block');

        $screen->call('selectNode', $id)->set('props.settings.html_block', (string) $block->id)->call('apply')->assertHasNoErrors();

        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), true)['components'];
        $this->assertSame(['type' => 'html_block', 'settings' => ['html_block' => $block->id]], end($stored));
    }

    // ---- the DS-5 button model ----------------------------------------------------------------------------------------------------------

    public function test_publish_makes_the_working_copy_the_published_layout_and_the_draft_is_forgotten(): void
    {
        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);
        $screen = $this->screen()->call('addNode', 'sku');

        $this->assertSame(['stale' => true], Cache::get(StorefrontAppearance::CACHE_KEY), 'autosave publishes nothing and replaces no cache entry');

        $screen->call('publish');

        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase', 'sku'], $this->storedTypes(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY), 'the draft equals the published layout: it is gone');
        $document = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $this->assertCount(7, $document['zones']['product_summary']['global']['layout']['components']);
        $this->assertArrayNotHasKey('stale', Cache::get(StorefrontAppearance::CACHE_KEY), 'the reader\'s cache entry is replaced');
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());
    }

    public function test_publish_with_nothing_to_publish_recompiles_nothing(): void
    {
        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $this->screen()->call('publish');

        $this->assertSame(['stale' => true], Cache::get(StorefrontAppearance::CACHE_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
    }

    public function test_discard_drops_the_draft_and_the_working_copy_is_the_published_layout_again(): void
    {
        $screen = $this->screen()->call('moveNode', $this->idOf($this->screen(), 'stock'), 'up');
        $screen = $this->screen();
        $screen->call('addNode', 'sku');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));

        $screen->call('discard');

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));
        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase'], $this->types($screen));
        $screen->assertSet('selected', ['kind' => 'cell', 'id' => 's-r0-c0']);
    }

    public function test_the_initial_settings_put_the_default_into_the_working_copy_and_after_publishing_the_settings_table_is_as_it_was(): void
    {
        $screen = $this->screen()->call('addNode', 'sku');
        $screen->call('moveNode', $this->idOf($screen, 'sku'), 'up')->call('publish');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
        $this->assertNotNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));

        $screen = $this->screen()->call('resetToDefault');

        $this->assertSame(['brand', 'title', 'short_description', 'price', 'stock', 'purchase'], $this->types($screen));
        $this->assertNotNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY), 'the shop still has the published layout until «Публикувай»');
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());

        $screen->call('publish');

        foreach ([AppearanceSettings::SUMMARY_PUBLISHED_KEY, AppearanceSettings::SUMMARY_DRAFT_KEY, AppearanceSettings::DESIGN_COMPILED_KEY] as $key) {
            $this->assertNull($this->settings()->get($key), "{$key} is forgotten: everything is at the default");
        }
    }

    public function test_the_other_zones_entries_of_the_compiled_document_are_kept(): void
    {
        $this->screen()->call('addNode', 'sku')->call('publish');
        $before = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $before['zones']['checkout_header'] = ['global' => ['enabled' => false]];
        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, (string) json_encode($before));

        $this->screen()->call('resetToDefault')->call('publish');

        $after = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $this->assertSame(['checkout_header'], array_keys($after['zones']), 'only this zone\'s entry went away');
    }

    // ---- authorisation -------------------------------------------------------------------------------------------------------------------

    public function test_every_action_is_refused_without_the_permission(): void
    {
        $screen = $this->screen();
        $title = $this->idOf($screen, 'title');

        foreach ([['addNode', 'sku'], ['moveNode', $title, 'down'], ['removeNode', $this->idOf($screen, 'brand')], ['selectNode', $title], ['apply'], ['publish'], ['discard'], ['resetToDefault']] as $call) {
            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Administrator');
            $screen = $this->screen();
            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call(...$call)->assertForbidden();
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_DRAFT_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY));
    }
}
