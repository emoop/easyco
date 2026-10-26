<?php

namespace Tests\Feature\Storefront\Design;

use App\Filament\Design\Zones\CheckoutHeaderZone;
use App\Filament\Pages\Design\DesignScreen;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Media\MediaDeleter;
use App\Media\Usage\MediaUsageRegistry;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\CheckoutHeader\CheckoutHeaderDrafts;
use App\Storefront\CheckoutHeader\CheckoutHeaderValidator;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderDrafts;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * LG-1 §2 and §3: the logo picture of the simplified checkout header (same descriptor, same compile, same fallback, independent from the header), and the usage / delete guard for both zones, draft
 * and published.
 */
class LogoCheckoutAndUsageTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    private const STATIC_CHECKOUT = '<a class="sf-logo sf-chdr-logo" href="/">'."\n".'            <img src="http://localhost:8000/images/logo.svg" alt="Raf Shop" width="596" height="150">'."\n".'        </a>';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        $this->seedPricing();
        $this->giveProductsAShippingClass();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    private function logo(array $override = []): MediaAssetModel
    {
        $uuid = uniqid('', true);
        $variants = [];

        foreach (['thumbnail' => [400, 100], 'medium' => [900, 225], 'large' => [1600, 400]] as $tier => [$w, $h]) {
            $variants[] = ['tier' => $tier, 'width' => $w, 'height' => $h, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"];
        }

        return $this->pickerAsset($override + ['mime' => 'image/png', 'path' => "uploads/2026/10/{$uuid}.png", 'variants' => $variants, 'width' => 2000, 'height' => 500]);
    }

    private function url(MediaAssetModel $row, string $tier = 'thumbnail'): string
    {
        foreach ($row->variants as $variant) {
            if ($variant['tier'] === $tier) {
                return app(\EasyCo\Media\Contracts\MediaStorageAdapter::class)->url('public', $variant['path']);
            }
        }

        $this->fail('no such variant');
    }

    /** @param  array<string, mixed>  $change */
    private function values(array $change = []): array
    {
        return array_replace(CheckoutHeaderCatalog::defaults(), ['enabled' => true, 'logo_height_d' => 32, 'logo_height_m' => 24], $change);
    }

    private function publishCheckout(array $values): void
    {
        app(CheckoutHeaderCompiler::class)->publish($values, $this->settings());
        $this->fresh();
    }

    private function layoutWith(?int $image): array
    {
        $layout = HeaderLayout::default();

        foreach (['desktop', 'mobile'] as $device) {
            $image !== null && $layout[$device]['rows']['main']['center'][0]['settings']['image'] = $image;
        }

        return $layout;
    }

    // ---- the values -------------------------------------------------------------------------------------------------------------

    public function test_the_catalog_has_the_field_but_the_stored_defaults_do_not_list_it(): void
    {
        $this->assertArrayHasKey('logo_image', CheckoutHeaderCatalog::fields());
        $this->assertSame('media', CheckoutHeaderCatalog::fields()['logo_image']['kind']);
        $this->assertArrayNotHasKey('logo_image', CheckoutHeaderCatalog::defaults());

        [$clean, $errors] = CheckoutHeaderValidator::validate(CheckoutHeaderCatalog::defaults());
        $this->assertSame([], $errors);
        $this->assertSame(CheckoutHeaderCatalog::defaults(), $clean, 'an empty picture is not stored');

        [$clean] = CheckoutHeaderValidator::validate($this->values(['logo_image' => 7]));
        $this->assertSame(7, $clean['logo_image']);

        [, $errors] = CheckoutHeaderValidator::validate($this->values(['logo_image' => 'abc']));
        $this->assertArrayHasKey('logo_image', $errors);
    }

    public function test_the_validator_does_not_query_the_library(): void
    {
        \DB::enableQueryLog();
        CheckoutHeaderValidator::validate($this->values(['logo_image' => 987654]));
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_saving_refuses_what_cannot_be_a_logo_on_the_field(): void
    {
        $zone = new CheckoutHeaderZone;

        foreach ([$this->pickerVideo(), $this->logo(['type' => 'social_image']), $this->logo(['mime' => 'image/jpeg']), $this->logo(['mime' => null]), $this->logo(['processing_status' => 'pending', 'variants' => null])] as $row) {
            [, $errors] = $zone->validate(['values' => $this->values(['logo_image' => $row->id])]);
            $this->assertArrayHasKey('logo_image', $errors, ($row->mime ?? 'null').' / '.$row->type);
        }

        [, $errors] = $zone->validate(['values' => $this->values(['logo_image' => 987654])]);
        $this->assertArrayHasKey('logo_image', $errors, 'a missing id');

        [, $errors] = $zone->validate(['values' => $this->values(['logo_image' => $this->logo()->id])]);
        $this->assertSame([], $errors);
        $this->assertSame(['kind' => 'form', 'id' => 'checkout', 'field' => 'logo_image'], $zone->locate([], 'logo_image'));
    }

    // ---- the page ---------------------------------------------------------------------------------------------------------------

    public function test_without_a_picture_the_checkout_header_is_byte_for_byte_what_it_was(): void
    {
        $this->publishCheckout($this->values());

        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringContainsString(self::STATIC_CHECKOUT, $html);
        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $this->assertArrayNotHasKey('logo', $stored['zones']['checkout_header'], 'nothing compiled for a header without a picture');
        $this->assertArrayNotHasKey('logo_image', $stored['zones']['checkout_header']['global']);
    }

    public function test_with_a_picture_the_simplified_header_prints_the_rendition_with_its_size(): void
    {
        $logo = $this->logo();
        $this->publishCheckout($this->values(['logo_image' => $logo->id]));

        $html = $this->get('/cart')->assertOk()->getContent();
        $block = substr($html, (int) strpos($html, 'sf-chdr-logo') - 40, 600);

        // heights 32 (desktop) and 24 (phone): the larger one decides -> 128 wide shown -> 256 needed -> the 400px size
        $this->assertStringContainsString('<img src="'.$this->url($logo).'" alt="Raf Shop" width="400" height="100" decoding="async">', $html);
        $this->assertStringNotContainsString('loading=', $block);
        $this->assertStringNotContainsString('style=', $block);
        $this->assertStringNotContainsString('images/logo.svg', $block);
    }

    public function test_the_picture_is_compiled_beside_the_values_and_read_without_a_query(): void
    {
        $logo = $this->logo();
        $this->publishCheckout($this->values(['logo_image' => $logo->id]));
        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);

        $this->assertSame($logo->id, $stored['zones']['checkout_header']['logo']['id']);
        $this->assertSame(400, $stored['zones']['checkout_header']['logo']['width']);
        $this->get('/cart')->assertOk();   // warm
        $this->app->forgetScopedInstances();

        \DB::enableQueryLog();
        $this->get('/cart')->assertOk();
        $media = array_filter(array_column(\DB::getQueryLog(), 'query'), fn (string $q): bool => str_contains($q, 'catalog_media'));
        \DB::disableQueryLog();

        $this->assertSame([], array_values($media));
    }

    public function test_a_missing_not_ready_or_wrong_type_picture_falls_back_to_the_static_logo_without_an_error(): void
    {
        foreach ([$this->logo(['processing_status' => 'pending', 'variants' => null]), $this->logo(['mime' => 'image/jpeg']), $this->pickerVideo()] as $row) {
            $this->publishCheckout($this->values(['logo_image' => $row->id]));

            $this->assertStringContainsString(self::STATIC_CHECKOUT, $this->get('/cart')->assertOk()->getContent());
        }

        $this->publishCheckout($this->values(['logo_image' => 987654]));
        $this->assertStringContainsString(self::STATIC_CHECKOUT, $this->get('/cart')->assertOk()->getContent());
    }

    public function test_a_hand_edited_compiled_entry_for_another_picture_or_with_a_bad_address_is_ignored(): void
    {
        $logo = $this->logo();
        $this->publishCheckout($this->values(['logo_image' => $logo->id]));
        $document = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);

        foreach ([['id' => $logo->id + 1], ['url' => 'javascript:alert(1)']] as $change) {
            $edited = $document;
            $edited['zones']['checkout_header']['logo'] = $change + $edited['zones']['checkout_header']['logo'];
            $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode($edited));
            $this->fresh();

            $html = $this->get('/cart')->assertOk()->getContent();
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringContainsString(self::STATIC_CHECKOUT, $html);
        }
    }

    public function test_the_header_and_the_checkout_header_are_independent(): void
    {
        $headerLogo = $this->logo();
        $checkoutLogo = $this->logo();
        app(HeaderCompiler::class)->publish($this->layoutWith($headerLogo->id), $this->settings());
        $this->publishCheckout($this->values(['logo_image' => $checkoutLogo->id]));

        $home = $this->get('/')->assertOk()->getContent();
        $cart = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringContainsString($this->url($headerLogo), $home);
        $this->assertStringNotContainsString($this->url($checkoutLogo), $home);
        $this->assertStringContainsString($this->url($checkoutLogo), $cart);
        $this->assertStringNotContainsString($this->url($headerLogo), $cart);

        // only the checkout header has a picture: the page keeps the static logo
        app(HeaderCompiler::class)->unpublish($this->settings());
        $this->fresh();
        $this->assertStringContainsString('images/logo.svg', substr($this->get('/')->getContent(), (int) strpos($this->get('/')->getContent(), 'data-sf-header'), 4000));
        $this->assertStringContainsString($this->url($checkoutLogo), $this->get('/cart')->getContent());
    }

    public function test_draft_preview_publish_and_revert_of_the_checkout_logo(): void
    {
        $old = $this->logo();
        $new = $this->logo();
        $this->publishCheckout($this->values(['logo_image' => $old->id]));

        CheckoutHeaderDrafts::saveDraft(CheckoutHeaderValidator::validate($this->values(['logo_image' => $new->id]))[0]);
        $this->fresh();

        $preview = $this->get(DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE))->assertOk()->getContent();
        $this->assertStringContainsString($this->url($new), $preview, 'the preview shows the draft picture');
        $this->fresh();   // a preview request leaves its override on the (test) application: the next request starts clean, as a real one does
        $public = $this->get('/cart')->getContent();
        $this->assertStringContainsString($this->url($old), $public, 'public keeps the old one');
        $this->assertStringNotContainsString($this->url($new), $public, 'a draft is never public');

        CheckoutHeaderDrafts::publish(CheckoutHeaderDrafts::draft());
        $this->fresh();
        $this->assertStringContainsString($this->url($new), $this->get('/cart')->getContent(), 'published: the new one is live');

        CheckoutHeaderDrafts::saveDraft(CheckoutHeaderValidator::validate($this->values(['logo_image' => $old->id]))[0]);
        CheckoutHeaderDrafts::discard();
        $this->fresh();
        $this->assertNull(CheckoutHeaderDrafts::draft());
        $this->assertStringContainsString($this->url($new), $this->get(DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE))->getContent(), '«Върни публикуваното»: the published picture again');
    }

    // ---- the Design screen --------------------------------------------------------------------------------------------------------

    public function test_the_checkout_panel_has_the_field_with_the_owners_texts_and_apply_stores_the_id(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->logo();
        $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'checkout');
        $html = $screen->html();

        $this->assertStringContainsString('Лого (снимка)', $html);
        $this->assertStringContainsString('Ползва се стандартното лого', $html);
        $this->assertStringContainsString('Добави изображение', $html);
        $this->assertStringContainsString('PNG или WebP, най-добре с прозрачен фон. Качете го поне 2 пъти по-голямо от показаното (напр. за височина 40 px — около 80–100 px), за да е остро на телефони.', $html);

        $screen->set('props.logo_image', $logo->id)->call('apply')->assertHasNoErrors();
        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY), true);
        $this->assertSame($logo->id, $draft['logo_image']);
    }

    public function test_the_checkout_panel_refuses_a_jpeg_a_video_and_an_unknown_type_on_the_field(): void
    {
        $this->actingAsStaff('Administrator');

        foreach ([$this->logo(['mime' => 'image/jpeg']), $this->pickerVideo(), $this->logo(['mime' => null])] as $row) {
            $screen = Livewire::test(DesignScreen::class)->call('selectZone', 'checkout')->set('props.logo_image', $row->id)->call('apply');
            $screen->assertHasErrors(['props.logo_image']);
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY), 'nothing was saved');
    }

    public function test_the_header_panel_shows_the_field_on_the_logo_and_clearing_it_removes_the_stored_id(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->logo();
        $screen = Livewire::test(DesignScreen::class)->call('selectNode', 'h-d-main-center-0');
        $html = $screen->html();

        $this->assertStringContainsString('Лого (снимка)', $html);
        $this->assertStringContainsString('Ползва се стандартното лого', $html);
        $this->assertSame(1, substr_count($html, 'Добави изображение'), 'ONE button');

        $screen->set('props.settings.image', $logo->id)->call('apply')->assertHasNoErrors();
        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
        $this->assertSame($logo->id, $draft['desktop']['rows']['main']['center'][0]['settings']['image']);

        $screen->set('props.settings.image', null)->call('apply')->assertHasNoErrors();
        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
        $this->assertArrayNotHasKey('image', $draft['desktop']['rows']['main']['center'][0]['settings']);
    }

    public function test_applying_and_publishing_from_the_screen_puts_the_logo_on_the_page(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->logo();

        Livewire::test(DesignScreen::class)->call('selectNode', 'h-d-main-center-0')->set('props.settings.image', $logo->id)->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $published = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), true);
        $this->assertSame($logo->id, $published['desktop']['rows']['main']['center'][0]['settings']['image']);
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $this->assertArrayHasKey((string) $logo->id, $compiled['layout']['logo_images']);

        $this->fresh();
        $this->assertStringContainsString($this->url($logo), $this->get('/')->assertOk()->getContent());

        // the checkout zone the same way
        $other = $this->logo();
        Livewire::test(DesignScreen::class)->call('selectZone', 'checkout')->set('props.enabled', true)->set('props.logo_image', $other->id)->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();
        $this->fresh();
        $this->assertStringContainsString($this->url($other), $this->get('/cart')->assertOk()->getContent());
    }
    public function test_a_save_from_the_old_header_page_keeps_the_logo_picture_chosen_in_the_design_screen(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->logo();
        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($logo->id))->layout);

        Livewire::test(\App\Filament\Pages\Settings\HeaderSettings::class)->callAction('save_draft')->assertHasNoFormErrors();

        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
        foreach (['desktop', 'mobile'] as $device) {
            $this->assertSame($logo->id, $draft[$device]['rows']['main']['center'][0]['settings']['image'], $device.': the old page never throws the picture away');
        }
    }
    public function test_the_header_panel_refuses_a_jpeg_on_the_logo_field(): void
    {
        $this->actingAsStaff('Administrator');
        $jpeg = $this->logo(['mime' => 'image/jpeg']);

        Livewire::test(DesignScreen::class)->call('selectNode', 'h-d-main-center-0')->set('props.settings.image', $jpeg->id)->call('apply')->assertHasErrors(['props.settings.image']);

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
    }

    // ---- usage and the delete guard ----------------------------------------------------------------------------------------------

    /** @return list<string> the labels «Къде се ползва» shows for a picture */
    private function labels(int $id): array
    {
        return array_map(fn ($usage): string => $usage->label, (new MediaUsageRegistry)->usagesOf($id));
    }

    public function test_the_registry_sees_the_logo_in_the_header_and_the_checkout_header_published_and_draft(): void
    {
        $logo = $this->logo();
        $this->assertSame([], $this->labels($logo->id));

        app(HeaderCompiler::class)->publish($this->layoutWith($logo->id), $this->settings());
        $this->assertSame(['Дизайн — Хедър, лого'], $this->labels($logo->id), 'published header: ONE line although the desktop and the phone logo both name it');

        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($logo->id))->layout);
        $this->assertEqualsCanonicalizing(['Дизайн — Хедър, лого', 'Дизайн — Хедър, лого (чернова)'], $this->labels($logo->id), 'and its draft, marked');

        $this->publishCheckout($this->values(['logo_image' => $logo->id]));
        $this->assertContains('Дизайн — Хедър на поръчката, лого', $this->labels($logo->id), 'published checkout header');

        CheckoutHeaderDrafts::saveDraft(CheckoutHeaderValidator::validate($this->values(['logo_image' => $logo->id]))[0]);
        $this->assertContains('Дизайн — Хедър на поръчката, лого (чернова)', $this->labels($logo->id), 'and its draft');
        $this->assertCount(4, $this->labels($logo->id));
        $this->assertSame(4, (new MediaUsageRegistry)->counts([$logo->id])[$logo->id]);
    }

    public function test_a_draft_only_use_counts_in_either_zone(): void
    {
        $header = $this->logo();
        $checkout = $this->logo();
        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($header->id))->layout);
        CheckoutHeaderDrafts::saveDraft(CheckoutHeaderValidator::validate($this->values(['logo_image' => $checkout->id]))[0]);

        $this->assertSame(['Дизайн — Хедър, лого (чернова)'], $this->labels($header->id));
        $this->assertSame(['Дизайн — Хедър на поръчката, лого (чернова)'], $this->labels($checkout->id));
    }

    public function test_the_delete_guard_refuses_a_picture_used_as_a_logo_even_by_a_draft_only(): void
    {
        $header = $this->logo();
        $checkout = $this->logo();
        $free = $this->logo();
        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($header->id))->layout);
        $this->publishCheckout($this->values(['logo_image' => $checkout->id]));

        $deleter = app(MediaDeleter::class);

        $this->assertSame('in_use', $deleter->delete($header->id)->status, 'a draft use of the header logo');
        $this->assertSame('in_use', $deleter->delete($checkout->id)->status, 'a published use of the checkout logo');
        $this->assertNotNull(MediaAssetModel::find($header->id));
        $this->assertNotNull(MediaAssetModel::find($checkout->id));
        $this->assertSame('deleted', $deleter->delete($free->id)->status);

        HeaderDrafts::discard();
        $this->assertSame('deleted', $deleter->delete($header->id)->status, 'free again once the draft is gone');
    }

    public function test_replacing_a_logo_leaves_the_old_picture_in_the_library_unused(): void
    {
        $old = $this->logo();
        $new = $this->logo();
        $this->publishCheckout($this->values(['logo_image' => $old->id]));
        $this->publishCheckout($this->values(['logo_image' => $new->id]));

        $this->assertNotNull(MediaAssetModel::find($old->id));
        $this->assertSame([], $this->labels($old->id));
        $this->assertSame(['Дизайн — Хедър на поръчката, лого'], $this->labels($new->id));
    }
}
