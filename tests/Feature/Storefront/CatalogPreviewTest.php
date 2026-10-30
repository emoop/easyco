<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Design\DesignScreen;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CD-1a.1: the preview frame of «Каталог» and of «Страница и карти» shows the APPLIED working copy (the draft), through the signed /design-preview; a public request never reads a draft, and no parameter
 * of a visitor reaches one.
 */
class CatalogPreviewTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();

        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');

        for ($i = 1; $i <= 30; $i++) {
            $this->makeProduct($i, ['name' => "Item {$i}", 'slug' => "item-{$i}", 'categories' => [$category], 'price' => '10.00', 'stock' => 1, 'timeline' => sprintf('2026-01-01 10:00:%02d', $i)]);
        }
    }

    /** @param  array<string, string>  $values */
    private function draft(string $section, array $values): void
    {
        [$clean, $errors] = PageDesign::validate($section, $values + PageDesign::defaults($section), false);
        $this->assertSame([], $errors);

        PageDesignStore::saveDraft($section, $clean);
    }

    /** @param  array<string, string>  $values */
    private function publish(string $section, array $values): void
    {
        [$clean] = PageDesign::validate($section, $values + PageDesign::defaults($section), false);

        PageDesignStore::publish($section, $clean);
    }

    /** A public request: the scoped appearance reader of the previous request in this test process is dropped, as a real request starts fresh. */
    private function visit(string $url): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    private function cards(string $html): int
    {
        return substr_count($html, 'class="sf-card"');
    }

    /** The text of the preview stylesheet the page links, or null. */
    private function previewCss(string $html): ?string
    {
        return preg_match('#<link rel="stylesheet" href="(/storage/appearance/design-preview-[0-9a-f]{12}\.css)">#', $html, $m) === 1 ? Storage::disk('public')->get(substr($m[1], strlen('/storage/'))) : null;
    }

    private function preview(string $zone): string
    {
        return $this->get(DesignPreviewController::previewUrl($zone))->assertOk()->getContent();
    }

    // ---- the preview shows the applied draft ------------------------------------------------------------------------------------------

    public function test_the_preview_of_catalog_shows_the_applied_draft_and_the_public_page_does_not(): void
    {
        $this->draft(PageDesign::CATALOG, ['cols' => '5', 'cols_phone' => '1', 'per_page' => '12']);

        $preview = $this->preview(PageDesign::CATALOG);
        $css = (string) $this->previewCss($preview);

        $this->assertSame(12, $this->cards($preview), 'the draft\'s page size');
        $this->assertStringContainsString('--sf-cat-cols: 5;', $css);
        $this->assertStringContainsString('--sf-cat-cols-phone: 1;', $css);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $preview);
        $this->assertStringContainsString('sf-category', $preview, 'the first shown category');

        $public = $this->visit('/product-category/clothing')->assertOk()->getContent();
        $this->assertSame(24, $this->cards($public));
        $this->assertNull($this->previewCss($public));
        $this->assertStringNotContainsString('design-preview', $public);
        $this->assertStringNotContainsString('tokens-', $public, 'no stylesheet for the public page: nothing is published');
        $this->assertSame([], array_filter(Storage::disk('public')->files('appearance'), fn (string $f): bool => ! str_contains($f, 'design-preview-')), 'the draft is not in a public stylesheet');
    }

    public function test_after_publishing_the_draft_and_the_published_values_are_the_same(): void
    {
        $this->draft(PageDesign::CATALOG, ['cols' => '5', 'per_page' => '12']);
        $this->publish(PageDesign::CATALOG, ['cols' => '5', 'per_page' => '12']);

        $preview = $this->preview(PageDesign::CATALOG);

        $this->assertSame(12, $this->cards($preview));
        $this->assertSame(12, $this->cards($this->visit('/product-category/clothing')->getContent()));
        $this->assertStringContainsString('--sf-cat-cols: 5;', (string) $this->previewCss($preview));
    }

    public function test_a_draft_back_at_the_defaults_shows_the_defaults_over_a_published_non_default(): void
    {
        $this->publish(PageDesign::CATALOG, ['cols' => '5', 'cols_phone' => '1', 'per_page' => '12']);
        $this->draft(PageDesign::CATALOG, []);

        $preview = $this->preview(PageDesign::CATALOG);
        $css = (string) $this->previewCss($preview);

        $this->assertSame(24, $this->cards($preview), 'the draft is the defaults: 24');
        $this->assertStringContainsString('--sf-cat-cols: 4;', $css, 'an explicit default beats the published 5 that the token stylesheet still carries');
        $this->assertStringContainsString('--sf-cat-cols-phone: 2;', $css);

        $this->assertSame(12, $this->cards($this->visit('/product-category/clothing')->getContent()), 'the shop still shows the published values');
    }

    public function test_without_a_draft_the_preview_is_the_published_page(): void
    {
        $this->publish(PageDesign::CATALOG, ['cols' => '3', 'per_page' => '36']);

        $preview = $this->preview(PageDesign::CATALOG);

        $this->assertSame(30, $this->cards($preview), '30 products fit on the published 36');
        $this->assertStringContainsString('--sf-cat-cols: 3;', (string) $this->previewCss($preview), 'the draft is the published values');
    }

    public function test_the_preview_of_the_page_and_cards_shows_the_applied_draft_on_the_home_page(): void
    {
        $this->draft(PageDesign::PAGE, ['card_radius' => '12', 'pad_min' => '24']);

        $preview = $this->preview(PageDesign::PAGE);
        $css = (string) $this->previewCss($preview);

        $this->assertStringContainsString('--sf-card-radius: 0.75rem;', $css);
        $this->assertStringContainsString('--sf-page-pad: clamp(1.5rem, 4vw, 10rem);', $css);
        $this->assertStringContainsString('sf-home', $preview);

        $public = $this->visit('/')->assertOk()->getContent();
        $this->assertNull($this->previewCss($public));
        $this->assertStringNotContainsString('design-preview', $public);
    }

    public function test_the_product_page_zone_is_not_changed_by_this_task(): void
    {
        $this->assertFalse(in_array(PageDesign::PRODUCT, ['page', 'catalog'], true));
        $this->get(DesignPreviewController::previewUrl(PageDesign::PRODUCT))->assertNotFound();
    }

    // ---- a visitor never reaches a draft ----------------------------------------------------------------------------------------------

    public function test_no_parameter_of_a_visitor_reaches_the_draft(): void
    {
        $this->draft(PageDesign::CATALOG, ['cols' => '5', 'cols_phone' => '1', 'per_page' => '12']);

        foreach (['?preview=1', '?draft=1', '?zone=catalog', '?ds=1', '?preview=catalog&token=x', '?signature=abc&zone=catalog&expires=9999999999', '?per_page=12x'] as $query) {
            $response = $this->visit('/product-category/clothing'.$query);

            if ($response->status() === 404) {
                continue; // an invalid size is the storefront 404, never a draft
            }

            $html = $response->assertOk()->getContent();
            $this->assertSame(24, $this->cards($html), $query);
            $this->assertStringNotContainsString('design-preview', $html, $query);
            $this->assertStringNotContainsString('--sf-cat-cols', $html, $query);
        }

        $home = $this->visit('/?zone=catalog&preview=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('design-preview', $home);
    }

    public function test_a_missing_forged_expired_or_swapped_signature_is_the_storefront_404(): void
    {
        $this->draft(PageDesign::CATALOG, ['cols' => '5', 'per_page' => '12']);

        $this->get('/design-preview?zone=catalog')->assertNotFound();
        $this->get('/design-preview?zone=catalog&expires=9999999999&signature=deadbeef')->assertNotFound();

        $signed = DesignPreviewController::previewUrl(PageDesign::CATALOG);
        $this->get(str_replace('zone=catalog', 'zone=page', $signed))->assertNotFound();
        $this->get($signed.'&per_page=48')->assertNotFound();
        $this->get(str_replace('zone=catalog', 'zone=topbar', DesignPreviewController::previewUrl(PageDesign::CATALOG)))->assertNotFound();

        $this->travel(DesignPreviewController::MINUTES + 1)->minutes();
        $this->get($signed)->assertNotFound();
    }

    public function test_a_signed_preview_is_private_noindex_and_carries_no_cookie(): void
    {
        $this->draft(PageDesign::CATALOG, ['per_page' => '12']);

        $response = $this->get(DesignPreviewController::previewUrl(PageDesign::CATALOG))->assertOk();

        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
    }

    // ---- the screen ---------------------------------------------------------------------------------------------------------------------

    public function test_the_zone_frames_a_signed_preview_that_changes_after_apply_and_says_what_it_shows(): void
    {
        $this->withoutVite();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');

        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'catalog');
        $first = $screen->get('previewUrl');

        $this->assertStringContainsString('/design-preview?', $first);
        $this->assertStringContainsString('zone=catalog', $first);
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($first)), 'signed');
        $this->assertStringContainsString('The preview shows what is applied', $screen->html());

        $this->travel(2)->seconds();
        $screen->set('props.cols', '5')->call('apply');

        $this->assertNotSame($first, $screen->get('previewUrl'), 'a new signature makes the frame load the page again');
        $this->assertStringContainsString('zone=catalog', $screen->get('previewUrl'));

        $page = Livewire::test(DesignScreen::class)->call('selectZone', 'page');
        $this->assertStringContainsString('zone=page', $page->get('previewUrl'));
    }
}
