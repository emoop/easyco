<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\PageDesign\PageDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * DS-5: the working copy (draft) of every zone. «Приложи» writes the DRAFT and never the published value; «Публикувай» turns the draft into the published value (and the shop changes only then); «Върни
 * публикуваното» drops the draft; «Начални настройки» puts the initial values into the working copy (a draft), not into the shop; a structural change in a tree zone is stored as the draft at once; the
 * panel's un-applied edits are not stored; with nothing changed nothing is stored at all (the storefront output is what it was).
 */
class DesignDraftModelTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const BANNER = ['enabled' => true, 'text' => 'Free delivery over 100', 'url' => '/sale', 'link_label' => 'Shop now', 'text_color' => '#ffffff', 'bg_color' => '#aa0000', 'starts_at' => null, 'ends_at' => null];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function screen(string $zone): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    /** @param  array<string, mixed>  $values */
    private function fill(Testable $screen, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $screen->set('props.'.$key, $value);
        }

        return $screen;
    }

    /** @return list<string> every key the shop reads from the plain sections and the banner (published storage) */
    private function publishedKeys(): array
    {
        return [
            AppearanceSettings::PAGE_DESIGN_KEY, AppearanceSettings::PRODUCT_DESIGN_KEY, AppearanceSettings::COMPILED_KEY, ...array_values(PageDesign::GALLERY_KEYS),
            AppearanceSettings::BANNER_ENABLED, AppearanceSettings::BANNER_TEXT, AppearanceSettings::BANNER_URL, AppearanceSettings::BANNER_LINK_LABEL, AppearanceSettings::BANNER_COLOR_TEXT, AppearanceSettings::BANNER_COLOR_BG,
        ];
    }

    /** @return array<string, ?string> */
    private function published(): array
    {
        return $this->settings()->getMany($this->publishedKeys());
    }

    /** Nothing the shop reads is stored; `$neverCompiled` also says no stylesheet file exists (after a return to the defaults the compiler keeps ONE previous file for pages cached a moment earlier: its own rule). */
    private function assertShopUntouched(bool $neverCompiled = true): void
    {
        $this->assertSame(array_fill_keys($this->publishedKeys(), null), $this->published());
        $neverCompiled && $this->assertSame([], Storage::disk('public')->files('appearance'), 'no stylesheet file');
    }

    private function draftOf(string $key): ?string
    {
        return $this->settings()->get($key);
    }

    // ---- «Приложи» writes the draft, never the published value ----------------------------------------------------------------------------------

    public function test_apply_on_the_page_section_writes_the_draft_only(): void
    {
        $screen = $this->fill($this->screen('page'), ['card_radius' => '12', 'pad_min' => '24'])->call('apply')->assertHasNoErrors();

        $this->assertNotNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY));
        $this->assertSame(12, json_decode((string) $this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY), true)['card_radius']);
        $this->assertShopUntouched();
        $screen->assertSet('dirty', false);
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());
    }

    public function test_apply_on_the_product_section_writes_the_draft_only_the_gallery_keys_included(): void
    {
        $this->fill($this->screen('product'), ['gallery_desktop_mode' => 'thumbs-left', 'gallery_width' => '70', 'col_gap' => '48', 'sticky' => false])->call('apply')->assertHasNoErrors();

        $draft = json_decode((string) $this->draftOf(AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY), true);
        $this->assertSame('thumbs-left', $draft['gallery_desktop_mode']);
        $this->assertSame('70', $draft['gallery_width']);
        $this->assertShopUntouched();
        $this->assertSame('grid', GallerySettings::fromRepository($this->settings())->desktopMode, 'the page keeps reading the published gallery choice');
    }

    public function test_apply_on_the_top_banner_writes_the_draft_and_the_home_page_does_not_show_it(): void
    {
        $this->fill($this->screen('topbar'), self::BANNER)->call('apply')->assertHasNoErrors();

        $this->assertNotNull($this->draftOf(AppearanceSettings::BANNER_DRAFT_KEY));
        $this->assertShopUntouched();
        $this->assertStringNotContainsString('Free delivery over 100', $this->get('/')->assertOk()->getContent());
    }

    public function test_apply_on_the_cart_header_and_the_header_write_their_drafts_only(): void
    {
        $this->fill($this->screen('checkout'), ['enabled' => true, 'back_label' => 'Keep shopping'])->call('apply')->assertHasNoErrors();
        $this->assertNotNull($this->draftOf(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::DESIGN_COMPILED_KEY));

        $this->screen('header')->call('apply')->assertHasNoErrors();
        $this->assertNotNull($this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_PUBLISHED_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_COMPILED_KEY));
    }

    // ---- «Публикувай» ---------------------------------------------------------------------------------------------------------------------------

    public function test_publish_makes_the_draft_the_published_value_and_forgets_the_draft(): void
    {
        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $screen = $this->fill($this->screen('page'), ['card_radius' => '12'])->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY), 'the draft equals the published value now: it is gone');
        $this->assertSame(['card_radius' => 12], array_intersect_key(json_decode((string) $this->draftOf(AppearanceSettings::PAGE_DESIGN_KEY), true), ['card_radius' => 0]));
        $compiled = json_decode((string) $this->draftOf(AppearanceSettings::COMPILED_KEY), true);
        $this->assertStringContainsString('--sf-card-radius: 0.75rem;', Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css'));
        $this->assertArrayNotHasKey('stale', Cache::get(StorefrontAppearance::CACHE_KEY), 'the reader\'s cache entry is replaced');
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());
    }

    public function test_publish_of_the_product_section_writes_the_gallery_keys_and_the_page_shows_them(): void
    {
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeProduct(1, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '10.00', 'stock' => 1]);
        $this->makeImage(1, 1, 1);

        $this->fill($this->screen('product'), ['gallery_desktop_mode' => 'thumbs-left', 'gallery_hover_zoom' => 'off', 'gal_gap' => '12'])->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertSame('thumbs-left', $this->draftOf(GallerySettings::DESKTOP_MODE_KEY));
        $this->assertSame('off', $this->draftOf(GallerySettings::HOVER_ZOOM_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY));

        $this->app->forgetScopedInstances();
        $html = $this->get('/product/summer-dress')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-gal-desktop="thumbs-left"\s+data-gal-mobile="carousel"\s+data-gal-odd="same"\s+data-gal-zoom="off"/', $html);
    }

    public function test_publish_of_the_top_banner_shows_it_in_the_shop_and_forgets_the_draft(): void
    {
        $this->fill($this->screen('topbar'), self::BANNER)->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertNull($this->draftOf(AppearanceSettings::BANNER_DRAFT_KEY));
        $this->assertSame('Free delivery over 100', $this->draftOf(AppearanceSettings::BANNER_TEXT));
        $this->assertStringContainsString('Free delivery over 100', $this->get('/')->assertOk()->getContent());
    }

    public function test_publish_with_nothing_to_publish_changes_nothing_and_recompiles_nothing(): void
    {
        foreach (['page', 'product', 'topbar', 'header', 'footer', 'checkout'] as $zone) {
            Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

            $this->screen($zone)->call('publish');

            $this->assertSame(['stale' => true], Cache::get(StorefrontAppearance::CACHE_KEY), "{$zone}: a refused publish recompiles nothing and replaces no cache entry");
        }

        $this->assertShopUntouched();
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    // ---- «Върни публикуваното» ----------------------------------------------------------------------------------------------------------------

    public function test_discard_drops_the_draft_and_the_working_copy_is_the_published_one_again(): void
    {
        $this->fill($this->screen('page'), ['card_radius' => '12'])->call('apply')->call('publish');
        $screen = $this->fill($this->screen('page'), ['card_radius' => '20'])->call('apply');
        $this->assertNotNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY));

        $screen->call('discard')->assertSet('props.card_radius', 12);

        $this->assertNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY));
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());
    }

    // ---- «Начални настройки» --------------------------------------------------------------------------------------------------------------------

    public function test_the_initial_settings_go_into_the_working_copy_and_reach_the_shop_only_after_publish(): void
    {
        $this->fill($this->screen('page'), ['card_radius' => '12', 'pad_min' => '24'])->call('apply')->call('publish');
        $this->fill($this->screen('product'), ['gal_gap' => '12', 'gallery_desktop_mode' => 'thumbs-left'])->call('apply')->call('publish');
        $before = $this->published();

        $page = $this->screen('page')->call('resetToDefault')->assertSet('props.card_radius', 8)->assertSet('props.pad_min', 16);
        $product = $this->screen('product')->call('resetToDefault')->assertSet('props.gal_gap', 8)->assertSet('props.gallery_desktop_mode', 'grid');

        $this->assertSame($before, $this->published(), 'the shop is exactly as it was');
        $this->assertNotNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY), 'the working copy is stored');
        $this->assertNotNull($this->draftOf(AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY));

        $page->call('publish');
        $product->call('publish');

        $this->assertShopUntouched(false);
        $this->assertNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY));
        $this->assertNull($this->draftOf(AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY));
    }

    // ---- autosave in the tree zones ----------------------------------------------------------------------------------------------------------

    public function test_a_structural_change_in_the_header_is_stored_as_the_draft_at_once(): void
    {
        $screen = $this->screen('header')->call('selectCell', 'h-d-top-left')->call('addNode', 'spacer');

        $draft = json_decode((string) $this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY), true);
        $this->assertCount(1, $draft['desktop']['rows']['top']['left'], 'the new component is in the stored draft without any other click');
        $screen->assertSet('dirty', false);
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_PUBLISHED_KEY));

        $id = $screen->get('selected')['id'];
        $screen->call('removeNode', $id);
        $this->assertCount(0, json_decode((string) $this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY), true)['desktop']['rows']['top']['left'], 'removing it is stored at once too');
    }

    public function test_publishing_a_tree_zone_forgets_its_draft_and_the_screen_says_published(): void
    {
        $header = $this->screen('header')->call('selectCell', 'h-d-top-left')->call('addNode', 'spacer')->call('publish');
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY), 'a draft that equals the published layout is no draft');
        $this->assertNotNull($this->draftOf(AppearanceSettings::HEADER_PUBLISHED_KEY));
        $this->assertStringContainsString('data-ds-state="published"', $header->html());

        $footer = $this->screen('footer')->call('addRow')->call('publish');
        $this->assertNull($this->draftOf(AppearanceSettings::FOOTER_DRAFT_KEY));
        $this->assertNotNull($this->draftOf(AppearanceSettings::FOOTER_PUBLISHED_KEY));
        $this->assertStringContainsString('data-ds-state="published"', $footer->html());
    }

    public function test_a_structural_change_in_the_footer_is_stored_as_the_draft_at_once(): void
    {
        $this->screen('footer')->call('addRow');

        $this->assertCount(2, json_decode((string) $this->draftOf(AppearanceSettings::FOOTER_DRAFT_KEY), true)['upper']['rows']);
        $this->assertNull($this->draftOf(AppearanceSettings::FOOTER_PUBLISHED_KEY));
    }

    public function test_the_panels_unapplied_edits_are_not_stored(): void
    {
        // an edit in the panel that no action has taken: the browser would set the flag; the server stores nothing
        $screen = $this->screen('header')->call('selectRow', 'h-d-main')->set('props.height', 90)->set('panelDirty', true);

        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY));
        $this->assertStringContainsString('data-ds-state="panel"', $screen->html());

        $plain = $this->fill($this->screen('page'), ['card_radius' => '12'])->set('panelDirty', true);
        $this->assertNull($this->draftOf(AppearanceSettings::PAGE_DESIGN_DRAFT_KEY));
        $this->assertTrue($plain->get('panelDirty'));
    }

    public function test_a_working_copy_that_does_not_validate_yet_is_kept_in_the_page_and_not_stored(): void
    {
        // a footer html block that still has no block chosen is a working copy the validator refuses: it stays on screen (dirty) until «Приложи» takes it
        $screen = $this->screen('footer')->call('selectCell', 'f-u-r0-c0')->call('addNode', 'html_block');

        $this->assertNull($this->draftOf(AppearanceSettings::FOOTER_DRAFT_KEY));
        $screen->assertSet('dirty', true);
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());
    }

    // ---- nothing changed, nothing stored -----------------------------------------------------------------------------------------------------

    public function test_with_nothing_changed_the_plain_sections_and_the_banner_store_nothing_at_all(): void
    {
        foreach (['page', 'product', 'topbar'] as $zone) {
            $this->screen($zone)->call('apply')->call('publish')->assertHasNoErrors();
        }

        $this->assertShopUntouched();

        foreach ([AppearanceSettings::PAGE_DESIGN_DRAFT_KEY, AppearanceSettings::PRODUCT_DESIGN_DRAFT_KEY, AppearanceSettings::BANNER_DRAFT_KEY] as $key) {
            $this->assertNull($this->draftOf($key), $key.': a draft equal to the published value is no draft');
        }
    }

    public function test_the_banner_published_through_the_appearance_page_is_the_zones_published_version(): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), ['banner_enabled' => true, 'banner_text' => 'From Облик', 'c_banner_bg' => '#112233']));

        $screen = $this->screen('topbar')->assertSet('props.text', 'From Облик')->assertSet('props.bg_color', '#112233');
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());

        $screen->set('props.text', 'A new text')->call('apply');
        $this->assertSame('From Облик', $this->draftOf(AppearanceSettings::BANNER_TEXT), 'still the published banner');
        $this->assertStringContainsString('data-ds-state="draft"', $screen->html());
    }

    // ---- the signed preview of the banner draft ----------------------------------------------------------------------------------------------

    public function test_the_banner_preview_shows_the_draft_through_a_signed_url_only(): void
    {
        $screen = $this->fill($this->screen('topbar'), self::BANNER)->call('apply');
        $url = $screen->get('previewUrl');

        $this->assertStringContainsString('/design-preview?', $url);
        $html = $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->getContent();
        $this->assertStringContainsString('Free delivery over 100', $html);
        $this->assertStringContainsString('Shop now', $html);

        $this->get('/design-preview?zone=topbar')->assertNotFound();
        // a request of its own (in the browser every request is): the preview's override lives in that one request
        $this->app->forgetScopedInstances();
        $this->assertStringNotContainsString('Free delivery over 100', $this->get('/')->assertOk()->getContent(), 'the shop itself is untouched');
    }

    // ---- authorisation -----------------------------------------------------------------------------------------------------------------------

    public function test_every_action_of_every_zone_is_refused_without_the_permission(): void
    {
        foreach (['topbar', 'header', 'footer', 'checkout', 'page', 'product'] as $zone) {
            foreach (['apply', 'publish', 'discard', 'resetToDefault'] as $call) {
                $this->actingAsStaff('Administrator');
                $screen = $this->screen($zone);

                $this->app->forgetScopedInstances();
                $this->actingAsStaff('Product Entry');
                $screen->call($call)->assertForbidden();
            }
        }

        $this->assertShopUntouched();
        $this->assertNull($this->draftOf(AppearanceSettings::HEADER_DRAFT_KEY));
    }
}
