<?php

namespace Tests\Feature\Storefront\Design;

use App\Filament\Design\FieldForms;
use App\Filament\Design\Zones\HeaderZone;
use App\Filament\Forms\Components\MediaPicker;
use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Livewire\MediaPickerBrowser;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Design\LogoImage;
use App\Storefront\Design\ZoneStore\HeaderAdapter;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderDrafts;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Media\BuildsImageFixtures;
use Tests\Feature\Storefront\AssertsSafeHtml;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * LG-1 §1: the picture of the header's «Лого» element. PNG / WebP only (the stored mime decides), the rendition is chosen at compile and rides in the compiled layout (no storefront query), an empty
 * field changes nothing (byte-identical markup), draft / preview / publish / revert work, and a picture that cannot be a logo falls back to the static file.
 */
class LogoPictureTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    private const STATIC_DESKTOP = '<a class="sf-logo sf-logo-desktop" href="/">'."\n".'    <img src="http://localhost:8000/images/logo.svg" alt="Raf Shop" width="596" height="150">'."\n".'</a>';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop', 'filesystems.disks.public.url' => 'https://cdn.test/storage']);
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

    /** A ready PNG logo row (a real picture would be 800x200): the three WebP sizes are 400, 900 and 1600 wide, proportions 4:1. */
    private function logo(array $override = []): MediaAssetModel
    {
        $uuid = uniqid('', true);
        $sizes = ['thumbnail' => [400, 100], 'medium' => [900, 225], 'large' => [1600, 400]];
        $variants = [];

        foreach ($sizes as $tier => [$w, $h]) {
            $variants[] = ['tier' => $tier, 'width' => $w, 'height' => $h, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"];
        }

        $variants[] = ['tier' => 'admin_grid', 'width' => 42, 'height' => 42, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-admin_grid.webp"];

        return $this->pickerAsset($override + ['mime' => 'image/png', 'path' => "uploads/2026/10/{$uuid}.png", 'original_name' => 'logo.png', 'variants' => $variants, 'width' => 2000, 'height' => 500]);
    }

    /** The default layout with the logo picture and heights set on both devices. */
    private function layoutWith(?int $image, int $desktopHeight = 40, int $mobileHeight = 28): array
    {
        $layout = HeaderLayout::default();

        foreach (['desktop' => $desktopHeight, 'mobile' => $mobileHeight] as $device => $height) {
            foreach ($layout[$device]['rows'] as $row => $data) {
                foreach (HeaderLayout::SLOTS as $slot) {
                    foreach ($data[$slot] as $i => $element) {
                        if ($element['type'] === 'logo') {
                            $layout[$device]['rows'][$row][$slot][$i]['settings']['height'] = $height;
                            $image !== null && $layout[$device]['rows'][$row][$slot][$i]['settings']['image'] = $image;
                        }
                    }
                }
            }
        }

        return $layout;
    }

    private function publish(array $layout): void
    {
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    // ---- the rendition rule -----------------------------------------------------------------------------------------------------

    public function test_the_smallest_webp_variant_twice_as_wide_as_shown_is_chosen_else_the_largest(): void
    {
        $logo = $this->logo();

        // 4:1 logo shown 40px high = 160px wide: needs 320, the 400px one is enough
        $this->assertSame(['url' => $this->url($logo, 'thumbnail'), 'width' => 400, 'height' => 100], LogoImage::rendition($logo->id, 40));
        // 60px high = 240 wide: needs 480, the 900px one
        $this->assertSame(900, LogoImage::rendition($logo->id, 60)['width']);
        // 120px high = 480 wide: needs 960, only the 1600px one is big enough
        $this->assertSame(1600, LogoImage::rendition($logo->id, 120)['width']);
        // 16px high = 64 wide: needs 128, still the smallest
        $this->assertSame(400, LogoImage::rendition($logo->id, 16)['width']);

        // nothing is big enough (a 1600px bar is not enough for 2x of 480px... made up by a taller logo): the largest
        $this->assertSame(1600, LogoImage::rendition($logo->id, 200)['width']);
        $this->assertNotContains(LogoImage::rendition($logo->id, 40)['width'], [42], 'the admin crop is never printed');
    }

    /** The address the adapter gives (a public-disk path: /storage/...). */
    private function url(MediaAssetModel $row, string $tier): string
    {
        return app(\EasyCo\Media\Contracts\MediaStorageAdapter::class)->url('public', $this->variantPath($row, $tier));
    }

    private function variantPath(MediaAssetModel $row, string $tier): string
    {
        foreach ($row->variants as $variant) {
            if ($variant['tier'] === $tier) {
                return $variant['path'];
            }
        }

        $this->fail('no such variant');
    }

    public function test_the_url_goes_through_the_storage_adapter_never_a_raw_path(): void
    {
        $url = LogoImage::rendition($this->logo()->id, 40)['url'];

        $this->assertStringStartsWith('/storage/uploads/', $url, 'what MediaStorageAdapter::url() says, not the raw path');
        $this->assertStringEndsWith('-thumbnail.webp', $url);
    }

    // ---- what may be a logo -----------------------------------------------------------------------------------------------------

    public function test_only_a_ready_png_or_webp_picture_can_be_a_logo(): void
    {
        $this->assertNull(LogoImage::problem($this->logo()->id));
        $this->assertNull(LogoImage::problem($this->logo(['mime' => 'image/webp'])->id));

        $refused = [
            'video' => $this->pickerVideo(),
            'social image' => $this->logo(['type' => 'social_image']),
            'jpeg' => $this->logo(['mime' => 'image/jpeg']),
            'gif' => $this->logo(['mime' => 'image/gif']),
            'unknown mime' => $this->logo(['mime' => null]),
            'still processing' => $this->logo(['processing_status' => 'pending', 'variants' => null]),
            'failed' => $this->logo(['processing_status' => 'failed', 'variants' => null]),
            'file missing' => $this->logo(['missing_at' => now()]),
        ];

        foreach ($refused as $what => $row) {
            $this->assertNotNull(LogoImage::problem($row->id), $what.' is refused');
            $this->assertNull(LogoImage::rendition($row->id, 40), $what.' gets no rendition');
        }

        $this->assertNotNull(LogoImage::problem(987654), 'a missing id');
        $this->assertNull(LogoImage::rendition(987654, 40));
        $this->assertStringContainsString('media:backfill-meta', (string) LogoImage::problem($refused['unknown mime']->id));
    }

    public function test_the_name_never_decides_the_type(): void
    {
        $liar = $this->logo(['mime' => 'image/jpeg', 'original_name' => 'logo.png', 'path' => 'uploads/2026/10/logo.png']);

        $this->assertNotNull(LogoImage::problem($liar->id));
    }

    // ---- the Design screen's save ----------------------------------------------------------------------------------------------

    public function test_saving_refuses_a_picture_that_cannot_be_a_logo_on_the_logo_field(): void
    {
        $zone = new HeaderZone;

        foreach ([$this->pickerVideo(), $this->logo(['type' => 'social_image']), $this->logo(['mime' => 'image/jpeg']), $this->logo(['mime' => null]), $this->logo(['processing_status' => 'pending', 'variants' => null])] as $row) {
            [$layout, $errors] = $zone->validate(HeaderAdapter::toTree($this->layoutWith($row->id)));

            $this->assertNotEmpty(array_filter(array_keys($errors), fn (string $path): bool => str_ends_with($path, '.settings.image')), 'refused: '.($row->mime ?? 'null').' / '.$row->type);
        }

        [$layout, $errors] = $zone->validate(HeaderAdapter::toTree($this->layoutWith(987654)));
        $this->assertNotEmpty(array_filter(array_keys($errors), fn (string $path): bool => str_ends_with($path, '.settings.image')), 'a missing id');

        [$layout, $errors] = $zone->validate(HeaderAdapter::toTree($this->layoutWith($this->logo()->id)));
        $this->assertSame([], $errors, 'a PNG logo is accepted');
    }

    public function test_the_refusal_is_located_on_the_logo_node_and_its_field(): void
    {
        $zone = new HeaderZone;
        $tree = HeaderAdapter::toTree($this->layoutWith($this->pickerVideo()->id));
        [, $errors] = $zone->validate($tree);
        $path = array_key_first(array_filter($errors, fn ($m, $p): bool => str_ends_with($p, '.settings.image'), ARRAY_FILTER_USE_BOTH));
        $at = $zone->locate($tree, $path);

        $this->assertSame('node', $at['kind']);
        $this->assertSame('settings.image', $zone->fieldPath($at));
    }

    public function test_the_validator_stays_pure_and_does_not_query_the_library(): void
    {
        $layout = $this->layoutWith(987654);

        \DB::enableQueryLog();
        $validation = HeaderValidator::validate($layout);
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame([], $validation->errors, 'only the Design screen and the compiler look into the library');
        $this->assertSame([], $queries, 'the storefront validates on every read: no query');
    }

    // ---- an empty field changes nothing -----------------------------------------------------------------------------------------

    public function test_an_empty_picture_is_not_stored_and_the_default_layout_is_unchanged(): void
    {
        $validation = HeaderValidator::validate(HeaderLayout::default());

        $this->assertSame(HeaderLayout::default(), $validation->layout);
        $this->assertSame(['height'], array_keys($validation->layout['desktop']['rows']['main']['center'][0]['settings']), 'no `image` key');

        $layout = $this->layoutWith(null);
        $layout['desktop']['rows']['main']['center'][0]['settings']['image'] = null;
        $this->assertSame(['height'], array_keys(HeaderValidator::validate($layout)->layout['desktop']['rows']['main']['center'][0]['settings']));

        $this->assertArrayNotHasKey('logo_images', HeaderCompiler::compiled(HeaderLayout::default())['layout'], 'nothing is added to a layout without a picture');
    }

    public function test_without_a_picture_the_markup_is_byte_for_byte_the_static_logo(): void
    {
        $this->publish($this->layoutWith(null));

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(self::STATIC_DESKTOP, $html);
        $this->assertStringContainsString('<a class="sf-logo sf-logo-mobile" href="/">'."\n".'    <img src="http://localhost:8000/images/logo.svg" alt="Raf Shop" width="596" height="150">'."\n".'</a>', $html);
    }

    // ---- the compile ------------------------------------------------------------------------------------------------------------

    public function test_the_compiled_layout_carries_the_rendition_of_the_chosen_picture(): void
    {
        $logo = $this->logo();
        $compiled = HeaderCompiler::compiled($this->layoutWith($logo->id, desktopHeight: 40, mobileHeight: 28));

        $this->assertSame([$logo->id], array_map('intval', array_keys($compiled['layout']['logo_images'])));
        $this->assertSame(400, $compiled['layout']['logo_images'][(string) $logo->id]['width'], '40px high -> 160 wide -> 320 needed -> the 400px one');
        $this->assertSame($logo->id, $compiled['layout']['desktop']['rows']['main']['center'][0]['settings']['image']);
    }

    public function test_one_picture_on_both_devices_is_printed_from_the_size_the_larger_logo_needs(): void
    {
        $logo = $this->logo();
        // desktop 56px (shown 224 wide, needs 448 -> the 900px one), phone 28px (needs 224 -> 400px): one entry, sharp enough for both
        $compiled = HeaderCompiler::compiled($this->layoutWith($logo->id, desktopHeight: 56, mobileHeight: 28));

        $this->assertSame(900, $compiled['layout']['logo_images'][(string) $logo->id]['width']);
    }

    public function test_a_deleted_not_ready_or_wrong_type_picture_compiles_to_the_static_logo_without_an_error(): void
    {
        foreach ([$this->logo(['processing_status' => 'pending', 'variants' => null]), $this->logo(['mime' => 'image/jpeg']), $this->pickerVideo(), $this->logo(['type' => 'social_image'])] as $row) {
            $compiled = HeaderCompiler::compiled($this->layoutWith($row->id));

            $this->assertArrayNotHasKey('logo_images', $compiled['layout']);
        }

        $gone = $this->logo();
        $id = $gone->id;
        $gone->delete();
        $this->assertArrayNotHasKey('logo_images', HeaderCompiler::compiled($this->layoutWith($id))['layout']);

        $this->publish($this->layoutWith($id));
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(self::STATIC_DESKTOP, $html, 'no 500, the static logo');
    }

    public function test_a_compiled_row_without_the_entry_prints_the_static_logo(): void
    {
        $logo = $this->logo();
        $this->publish($this->layoutWith($logo->id));
        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        unset($stored['layout']['logo_images']);
        $this->settings()->set(AppearanceSettings::HEADER_COMPILED_KEY, json_encode($stored));
        $this->fresh();

        $this->assertStringContainsString(self::STATIC_DESKTOP, $this->get('/')->assertOk()->getContent());
    }

    public function test_a_hand_edited_rendition_that_is_not_valid_shows_the_default_header_not_a_500(): void
    {
        $logo = $this->logo();
        $this->publish($this->layoutWith($logo->id));
        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $stored['layout']['logo_images'][(string) $logo->id]['url'] = 'javascript:alert(1)';
        $this->settings()->set(AppearanceSettings::HEADER_COMPILED_KEY, json_encode($stored));
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString(self::STATIC_DESKTOP, $html);
    }

    // ---- the page ---------------------------------------------------------------------------------------------------------------

    public function test_a_published_logo_picture_is_printed_with_its_size_and_nothing_inline_or_lazy(): void
    {
        $logo = $this->logo();
        $this->publish($this->layoutWith($logo->id));
        $url = $this->url($logo, 'thumbnail');

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['desktop', 'mobile'] as $device) {
            $this->assertStringContainsString('<a class="sf-logo sf-logo-'.$device.'" href="/">'."\n".'    <img src="'.$url.'" alt="Raf Shop" width="400" height="100" decoding="async">'."\n".'</a>', $html, $device);
        }

        $this->assertStringNotContainsString('images/logo.svg', substr($html, (int) strpos($html, 'data-sf-header'), 6000), 'the static file is not printed beside it');
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*sf-logo|<a class="sf-logo[^>]*>\s*<img[^>]*loading=/', $html);
        $logoMarkup = substr($html, (int) strpos($html, 'class="sf-logo sf-logo-desktop"'), 400);
        $this->assertStringNotContainsString('style=', $logoMarkup);
        $this->assertStringNotContainsString('loading=', $logoMarkup, 'above the fold: never lazy');
    }

    public function test_the_logo_height_stays_a_css_variable_and_the_page_has_no_inline_style_because_of_it(): void
    {
        $logo = $this->logo();
        $this->publish($this->layoutWith($logo->id, 56, 30));

        $html = $this->get('/')->assertOk()->getContent();
        $css = Storage::disk('public')->allFiles('appearance');

        $this->assertNotEmpty($css);
        $this->assertStringNotContainsString('style="', substr($html, (int) strpos($html, 'data-sf-header'), 3000));
    }

    public function test_the_page_asks_the_database_for_nothing_extra_with_a_logo_picture(): void
    {
        $logo = $this->logo();
        $this->publish($this->layoutWith($logo->id));
        $this->get('/')->assertOk();   // the first request warms the cached appearance payload
        $this->app->forgetScopedInstances();

        \DB::enableQueryLog();
        $this->get('/')->assertOk();
        $withPicture = array_filter(array_column(\DB::getQueryLog(), 'query'), fn (string $q): bool => str_contains($q, 'catalog_media'));
        \DB::disableQueryLog();

        $this->assertSame([], array_values($withPicture), 'the storefront never touches catalog_media for the logo');
    }

    // ---- draft, preview, publish, revert ---------------------------------------------------------------------------------------

    public function test_draft_preview_publish_and_revert_of_the_logo_picture(): void
    {
        $old = $this->logo();
        $new = $this->logo();
        $oldUrl = $this->url($old, 'thumbnail');
        $newUrl = $this->url($new, 'thumbnail');

        // published: the old one
        $this->publish($this->layoutWith($old->id));
        $this->assertStringContainsString($oldUrl, $this->get('/')->getContent());

        // a draft with the new one: the public page keeps the old logo, the signed preview shows the new one
        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($new->id))->layout);
        $this->fresh();
        $public = $this->get('/')->getContent();
        $this->assertStringContainsString($oldUrl, $public);
        $this->assertStringNotContainsString($newUrl, $public, 'a draft is never public');

        $preview = $this->get(HeaderPreviewController::previewUrl())->assertOk()->getContent();
        $this->assertStringContainsString($newUrl, $preview, 'the preview shows the draft logo');
        $this->assertStringNotContainsString($oldUrl, substr($preview, (int) strpos($preview, 'data-sf-header'), 4000));

        // publish: now it is live
        $this->publish(HeaderDrafts::draft());
        $this->assertStringContainsString($newUrl, $this->get('/')->getContent());
        $this->assertStringNotContainsString($oldUrl, $this->get('/')->getContent());

        // «Върни публикуваното»: discard a later draft, the preview and the page show the published logo again
        HeaderDrafts::saveDraft(HeaderValidator::validate($this->layoutWith($old->id))->layout);
        $this->fresh();
        $this->assertStringContainsString($oldUrl, $this->get(HeaderPreviewController::previewUrl())->getContent());
        HeaderDrafts::discard();
        $this->fresh();
        $this->assertNull(HeaderDrafts::draft() === null ? null : 'draft still there');
        $this->assertStringContainsString($newUrl, $this->get(HeaderPreviewController::previewUrl())->getContent());
        $this->assertStringContainsString($newUrl, $this->get('/')->getContent());
    }

    public function test_replacing_the_logo_leaves_the_old_picture_in_the_library_as_unused(): void
    {
        $old = $this->logo();
        $new = $this->logo();
        $this->publish($this->layoutWith($old->id));
        $this->publish($this->layoutWith($new->id));

        $this->assertNotNull(MediaAssetModel::find($old->id), 'never deleted automatically');
        $this->assertSame([], (new \App\Media\Usage\MediaUsageRegistry)->usagesOf($old->id));
    }

    // ---- alpha ------------------------------------------------------------------------------------------------------------------

    public function test_a_transparent_png_keeps_its_alpha_in_every_webp_variant(): void
    {
        $png = $this->transparentPng(1200, 300);
        $this->assertSame(127, $this->cornerAlpha(imagecreatefromstring($png)), 'the fixture itself is transparent in its corner');

        $variants = app(\EasyCo\Media\Contracts\MediaImageProcessor::class)->generateVariants($png, 'public', 'uploads/2026/10/alpha.png');
        $checked = 0;

        foreach ($variants as $variant) {
            if (! in_array($variant->tier, ['thumbnail', 'medium', 'large'], true)) {
                continue;
            }

            $image = imagecreatefromstring(Storage::disk('public')->get($variant->path));
            $this->assertNotFalse($image, $variant->tier.' decodes');
            $this->assertGreaterThan(100, $this->cornerAlpha($image), $variant->tier.' keeps the transparent corner (GD alpha 127 = fully transparent)');
            $checked++;
        }

        $this->assertGreaterThanOrEqual(1, $checked);
    }

    /** A logo-like PNG: a coloured box in the middle, FULLY transparent everywhere else. */
    private function transparentPng(int $w, int $h): string
    {
        return $this->capture(function () use ($w, $h): void {
            $image = imagecreatetruecolor($w, $h);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledrectangle($image, (int) ($w * 0.3), (int) ($h * 0.3), (int) ($w * 0.7), (int) ($h * 0.7), imagecolorallocatealpha($image, 200, 30, 30, 0));
            imagepng($image);
        });
    }

    /** The GD alpha (0 opaque .. 127 fully transparent) of the pixel 2px from the top-left corner. */
    private function cornerAlpha(\GdImage $image): int
    {
        return (imagecolorat($image, 2, 2) >> 24) & 0x7F;
    }

    // ---- the field in the panel -------------------------------------------------------------------------------------------------

    public function test_the_field_is_a_picker_for_png_and_webp_with_the_owners_texts(): void
    {
        $field = FieldForms::make('image', HeaderCatalog::LOGO_IMAGE);

        $this->assertInstanceOf(MediaPicker::class, $field);
        $reflection = new \ReflectionClass($field);
        $this->assertSame(['image/png', 'image/webp'], $reflection->getProperty('mimes')->getValue($field));
        $this->assertTrue($reflection->getProperty('plainImagesOnly')->getValue($field));
        $this->assertSame('Ползва се стандартното лого', $field->getEmptyText());
        $this->assertTrue($field->hasCheckerBackground());
        $this->assertSame('Лого (снимка)', (string) $field->getLabel());
    }

    public function test_the_logo_element_has_that_field_and_only_that_one_besides_the_height(): void
    {
        $fields = \App\Storefront\Design\ComponentRegistry::for('header')['logo']['fields'];

        $this->assertSame(['height', 'image'], array_keys($fields));
        $this->assertSame('media', $fields['image']['kind']);
    }

    public function test_the_picker_of_the_logo_refuses_a_user_without_the_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        Livewire::test(MediaPickerBrowser::class, ['field' => 'data.settings.image', 'mimes' => ['image/png', 'image/webp'], 'plain' => true])->assertForbidden();
    }
}
