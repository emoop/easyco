<?php

namespace Tests\Feature\Logo;

use App\Filament\Pages\Settings\LocaleSettings;
use App\Media\Usage\MediaUsageRegistry;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\LogoGenerator;
use App\Settings\SiteLogo;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Cache\CachePurgeCollector;
use App\Storefront\Cache\CachePurger;
use App\Storefront\Cache\RecordingPurger;
use App\Storefront\Header\AppearanceRecompiler;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesLogoFixtures;
use Tests\TestCase;

/**
 * LOGO-1 (site-logo.md): the «Лого» tab of «Настройки»: the permission, the content MIME, the sizes and the oversized source (with the numbers), generation ONLY when a picture changed, the files of a
 * replaced picture deleted (and only ours), the same refresh as a «Дизайн» publish, and the usage registry.
 */
class SiteLogoTabTest extends TestCase
{
    use BuildsShippingZones;
    use MakesLogoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(LocaleSettings::class);
    }

    private function icon(int $w = 640, int $h = 640): MediaAssetModel
    {
        return $this->logoAsset($this->pngBytes($w, $h));
    }

    /** a PNG whose HEADER announces a huge picture: nothing is decoded before it is refused */
    private function hugePng(): string
    {
        $ihdr = pack('NN', 20000, 20000)."\x08\x06\x00\x00\x00";
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IEND', '');
    }

    // ---- the permission --------------------------------------------------------------------------------------------------------

    /**
     * ★ SETTINGS_MANAGE on open, and again in the public save().
     *
     * The test judges the EFFECT, never a thrown exception: when the whole Livewire request is refused (Filament's own access check runs before save()), the harness shows it as an EXCEPTION with
     * APP_DEBUG on (a developer's .env) and as a 4xx RESPONSE with it off (CI has no .env: the LOGO-1 CI failure), so "it threw" is not a fact about the permission. The second layer, save()'s own
     * `abort_unless`, is asserted by calling it directly, which does not go through the harness at all.
     */
    public function test_the_save_needs_settings_manage(): void
    {
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        $this->get(LocaleSettings::getUrl())->assertForbidden();

        $icon = $this->icon();
        $page = $this->page(); // mounted as the Administrator
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]); // then the user loses the permission
        $this->assertFalse(LocaleSettings::canAccess());

        try {
            $page->fillForm(['site_name' => 'Hacked', 'locale' => 'en', 'site_icon' => $icon->id])->call('save');
        } catch (\Throwable) {
            // APP_DEBUG on: the refusal is an exception; off: a 4xx response. Either way, only the effect matters
        }

        $this->assertNull($this->settings()->get(SiteLogo::NAME), 'the name was not written');
        $this->assertNull($this->settings()->get(SiteLogo::ICON), 'the icon was not written');
        $this->assertNull($this->settings()->get(SiteLogo::COMPILED));
        $this->assertSame('bg', $this->settings()->get('site.locale'), 'nothing else of the page was saved either');
        $this->assertSame([], $this->generatedFiles(), 'no file was generated');

        // the second layer: save() itself refuses a user without SETTINGS_MANAGE (a direct call: no harness, no APP_DEBUG)
        try {
            (new LocaleSettings)->save();
            $this->fail('save() ran for a user without SETTINGS_MANAGE');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ---- what is accepted -------------------------------------------------------------------------------------------------------

    /** ★ PNG and WebP are accepted */
    public function test_png_and_webp_are_accepted_and_generate_their_files(): void
    {
        $icon = $this->logoAsset($this->webpBytes(600, 600), 'image/webp');
        $logo = $this->logoAsset($this->pngBytes(800, 200));

        $this->page()->fillForm(['site_icon' => $icon->id, 'logo_main' => $logo->id])->call('save')->assertHasNoFormErrors();

        $files = $this->generatedFiles();
        $this->assertCount(4, $files, 'icon 32, icon 180, the .ico and the logo');
        $payload = app(StorefrontAppearance::class)->siteLogo();
        $this->assertSame([400, 100], [$payload['main']['png']['w'], $payload['main']['png']['h']]);
        $this->assertSame((string) $icon->id, $this->settings()->get(SiteLogo::ICON));
        $this->assertSame((string) $logo->id, $this->settings()->get(SiteLogo::MAIN));
    }

    /** ★ SVG, JPEG and GIF are refused, and nothing is written */
    public function test_svg_jpeg_and_gif_are_refused(): void
    {
        $svg = $this->logoAsset('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"></svg>', 'image/svg+xml');
        $jpeg = $this->logoAsset($this->pngBytes(600, 600), 'image/jpeg');
        $gif = $this->logoAsset($this->pngBytes(600, 600), 'image/gif');

        foreach ([$svg, $jpeg, $gif] as $asset) {
            $this->page()->fillForm(['site_icon' => $asset->id, 'site_name' => 'Refused'])->call('save')->assertHasFormErrors(['site_icon']);
        }

        $this->assertSame([], $this->generatedFiles());
        $this->assertNull($this->settings()->get(SiteLogo::ICON));
        $this->assertNull($this->settings()->get(SiteLogo::NAME), 'a refused picture writes nothing, the name included');
    }

    /** ★ the content decides: a row that says PNG over JPEG bytes is refused */
    public function test_the_type_is_checked_by_the_content_never_the_stored_name(): void
    {
        $image = imagecreatetruecolor(600, 600);
        ob_start();
        imagejpeg($image);
        $jpegBytes = (string) ob_get_clean();
        $liar = $this->logoAsset($jpegBytes, 'image/png', ['original_name' => 'logo.png']);

        $this->page()->fillForm(['site_icon' => $liar->id])->call('save')->assertHasFormErrors(['site_icon']);
        $this->assertSame([], $this->generatedFiles());
    }

    /** ★ the icon must be square and 512 x 512 at least, and the message has the numbers */
    public function test_the_icon_must_be_square_and_big_enough_with_the_numbers(): void
    {
        $notSquare = $this->icon(640, 480);
        $component = $this->page()->fillForm(['site_icon' => $notSquare->id])->call('save')->assertHasFormErrors(['site_icon']);
        $message = (string) collect($component->errors()->get('data.site_icon'))->first();
        $this->assertStringContainsString('640×480', $message);
        $this->assertStringContainsString('512×512', $message);
        $this->assertSame(__('logo_admin.errors.icon_square', ['w' => 640, 'h' => 480, 'min' => 512]), $message);

        $small = $this->icon(400, 400);
        $component = $this->page()->fillForm(['site_icon' => $small->id])->call('save')->assertHasFormErrors(['site_icon']);
        $message = (string) collect($component->errors()->get('data.site_icon'))->first();
        $this->assertStringContainsString('400×400', $message);
        $this->assertStringContainsString('512', $message);

        $this->assertSame([], $this->generatedFiles());

        $ok = $this->icon(512, 512);
        $this->page()->fillForm(['site_icon' => $ok->id])->call('save')->assertHasNoFormErrors();
        $this->assertNotSame([], $this->generatedFiles());
    }

    /** ★ a logo under 200 px wide is refused (the number is in the message) */
    public function test_a_logo_under_200_px_wide_is_refused(): void
    {
        $narrow = $this->logoAsset($this->pngBytes(150, 60));
        $component = $this->page()->fillForm(['logo_main' => $narrow->id])->call('save')->assertHasFormErrors(['logo_main']);
        $message = (string) collect($component->errors()->get('data.logo_main'))->first();
        $this->assertStringContainsString('150', $message);
        $this->assertStringContainsString('200', $message);

        $this->page()->fillForm(['logo_dark' => $narrow->id])->call('save')->assertHasFormErrors(['logo_dark']);

        $edge = $this->logoAsset($this->pngBytes(200, 50));
        $this->page()->fillForm(['logo_main' => $edge->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame([200, 50], [app(StorefrontAppearance::class)->siteLogo()['main']['png']['w'], app(StorefrontAppearance::class)->siteLogo()['main']['png']['h']], 'never upscaled');
    }

    /** ★ an oversized source is refused BEFORE it is decoded, with the numbers */
    public function test_an_oversized_source_is_refused_before_decoding(): void
    {
        $huge = $this->logoAsset($this->hugePng(), 'image/png', ['width' => 600, 'height' => 600]);

        $component = $this->page()->fillForm(['site_icon' => $huge->id])->call('save')->assertHasFormErrors(['site_icon']);
        $message = (string) collect($component->errors()->get('data.site_icon'))->first();
        $this->assertStringContainsString('20000×20000', $message);
        $this->assertSame([], $this->generatedFiles());
    }

    public function test_the_name_is_validated_and_empty_means_app_name(): void
    {
        $this->page()->fillForm(['site_name' => str_repeat('я', 81)])->call('save')->assertHasFormErrors(['site_name']);
        $this->page()->fillForm(['site_name' => "bad\nname"])->call('save')->assertHasFormErrors(['site_name']);
        $this->assertNull($this->settings()->get(SiteLogo::NAME));

        $this->page()->fillForm(['site_name' => '  Моят магазин '])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Моят магазин', $this->settings()->get(SiteLogo::NAME));

        $this->page()->fillForm(['site_name' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->settings()->get(SiteLogo::NAME), 'empty forgets the name: APP_NAME again');
    }

    /** a refusal on the logo tab does not half-save the other tabs */
    public function test_a_refused_picture_does_not_save_the_locale(): void
    {
        $notSquare = $this->icon(640, 480);
        $this->assertSame('bg', $this->settings()->get('site.locale'));

        $this->page()->fillForm(['locale' => 'en', 'site_icon' => $notSquare->id])->call('save')->assertHasFormErrors(['site_icon']);

        $this->assertSame('bg', $this->settings()->get('site.locale'));
    }

    // ---- generation only when something changed --------------------------------------------------------------------------

    /** ★ an unrelated save (the locale) neither reads nor regenerates a picture, and does not run the refresh */
    public function test_nothing_is_regenerated_when_nothing_changed(): void
    {
        $icon = $this->icon();
        $logo = $this->logoAsset($this->pngBytes(800, 200));
        $this->page()->fillForm(['site_icon' => $icon->id, 'logo_main' => $logo->id])->call('save')->assertHasNoFormErrors();
        $before = $this->generatedFiles();
        sort($before);

        $spy = new class extends LogoGenerator
        {
            public int $calls = 0;

            public function icon(string $source): array
            {
                $this->calls++;

                return parent::icon($source);
            }

            public function wide(string $source): array
            {
                $this->calls++;

                return parent::wide($source);
            }
        };
        $this->app->instance(LogoGenerator::class, $spy);
        // a sentinel in the appearance cache: the refresh of a «Дизайн» publish REPLACES that entry, so it survives an unrelated save only
        \Illuminate\Support\Facades\Cache::forever(StorefrontAppearance::CACHE_KEY, ['sentinel' => true]);
        $mtimes = array_map(fn (string $f): int => Storage::disk('public')->lastModified('appearance/'.$f), $before);

        $this->page()->fillForm(['locale' => 'en'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, $spy->calls, 'no GD call');
        $this->assertSame(['sentinel' => true], \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY), 'no logo refresh for an unrelated save');
        $after = $this->generatedFiles();
        sort($after);
        $this->assertSame($before, $after);
        $this->assertSame($mtimes, array_map(fn (string $f): int => Storage::disk('public')->lastModified('appearance/'.$f), $after));
        $this->assertSame('en', $this->settings()->get('site.locale'), 'the unrelated save itself worked');

        // a changed NAME is a change (the refresh runs) but regenerates no picture
        $this->page()->fillForm(['site_name' => 'Нов'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, $spy->calls);
        $this->assertArrayNotHasKey('sentinel', (array) \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY), 'a changed name runs the refresh');
    }

    /** ★ replacing a picture deletes the old files and drops their hash from the payload */
    public function test_replacing_a_picture_deletes_the_old_files(): void
    {
        $first = $this->icon(600, 600);
        $this->page()->fillForm(['site_icon' => $first->id])->call('save')->assertHasNoFormErrors();
        $oldHash = app(StorefrontAppearance::class)->siteLogo()['icon']['hash'];
        $old = $this->generatedFiles();
        $this->assertCount(3, $old);

        $second = $this->logoAsset($this->pngBytes(700, 700));
        $this->page()->fillForm(['site_icon' => $second->id])->call('save')->assertHasNoFormErrors();

        $new = $this->generatedFiles();
        $this->assertCount(3, $new, 'only the new generation is left');
        $this->assertSame([], array_intersect($old, $new), 'no file of the old generation is left');
        $this->app->forgetScopedInstances();
        $newHash = app(StorefrontAppearance::class)->siteLogo()['icon']['hash'];
        $this->assertNotSame($oldHash, $newHash);
        $this->assertStringNotContainsString($oldHash, (string) $this->settings()->get(SiteLogo::COMPILED));
        foreach ($new as $file) {
            $this->assertStringContainsString($newHash, $file);
        }
    }

    /** removing every picture forgets the compiled value and deletes the files */
    public function test_removing_the_pictures_clears_everything(): void
    {
        $icon = $this->icon();
        $logo = $this->logoAsset($this->pngBytes(800, 200));
        $this->page()->fillForm(['site_icon' => $icon->id, 'logo_main' => $logo->id])->call('save');

        $this->page()->fillForm(['site_icon' => null, 'logo_main' => null])->call('save')->assertHasNoFormErrors();

        $this->assertSame([], $this->generatedFiles());
        $this->assertNull($this->settings()->get(SiteLogo::COMPILED));
        $this->assertNull($this->settings()->get(SiteLogo::ICON));
        $this->assertNull($this->settings()->get(SiteLogo::MAIN));
        $this->app->forgetScopedInstances();
        $this->assertNull(app(StorefrontAppearance::class)->siteLogo());
    }

    /** ★ the cleanup touches ONLY our files: the token stylesheets, the previews and anything else stay */
    public function test_the_cleanup_never_touches_other_files_of_the_directory(): void
    {
        $disk = Storage::disk('public');
        $others = [
            'appearance/tokens-aaaaaaaaaaaa.css', 'appearance/cookie-preview-bbbbbbbbbbbb.css', 'appearance/header-preview-cccccccccccc.css', 'appearance/logo-notes.txt',
            'appearance/logo-short.png', 'appearance/icon-0123456789ab.svg', 'appearance/readme.md',
        ];
        foreach ($others as $path) {
            $disk->put($path, 'keep me');
        }

        // the writer alone (the token stylesheets are pruned by the appearance compiler's own, older rule when a refresh runs: not ours)
        $writer = app(\App\Settings\SiteLogoWriter::class);
        $first = $this->icon();
        $writer->commit($writer->prepare(['site_icon' => $first->id]));
        $second = $this->logoAsset($this->pngBytes(700, 700));
        $writer->commit($writer->prepare(['site_icon' => $second->id]));
        $writer->commit($writer->prepare(['site_icon' => null]));
        $this->assertSame([], $this->generatedFiles());

        foreach ($others as $path) {
            $this->assertTrue($disk->exists($path), "{$path} was touched");
        }
    }

    // ---- the refresh ------------------------------------------------------------------------------------------------------

    /** ★ a save purges through the same path as «Дизайн» (one `all`), and the next render has the new values with no manual clear */
    public function test_a_save_refreshes_and_purges_once(): void
    {
        $purger = new RecordingPurger;
        $this->app->instance(CachePurger::class, $purger);
        app(CachePurgeCollector::class)->reset();

        $icon = $this->icon();
        $this->page()->fillForm(['site_icon' => $icon->id, 'site_name' => 'Лого магазин'])->call('save')->assertHasNoFormErrors();

        $this->app->forgetScopedInstances();
        $this->assertSame('Лого магазин', app(StorefrontAppearance::class)->shopName());
        $this->assertNotNull(app(StorefrontAppearance::class)->siteLogo()['icon']);

        app(CachePurgeCollector::class)->flush();
        $this->assertCount(1, $purger->batches, 'ONE flush');
        $this->assertSame(1, count(array_keys($purger->keys(), 'all', true)), 'the key `all` once');
    }

    // ---- the usage registry -----------------------------------------------------------------------------------------------

    /** ★ the three pictures are in use: «Медия» refuses to delete them, and says where */
    public function test_a_picture_in_use_cannot_be_deleted_from_the_library(): void
    {
        $icon = $this->icon();
        $main = $this->logoAsset($this->pngBytes(800, 200));
        $dark = $this->logoAsset($this->pngBytes(800, 200));
        $free = $this->logoAsset($this->pngBytes(800, 200));
        $this->page()->fillForm(['site_icon' => $icon->id, 'logo_main' => $main->id, 'logo_dark' => $dark->id])->call('save')->assertHasNoFormErrors();

        $registry = new MediaUsageRegistry;
        $this->assertEquals([$icon->id => 1, $main->id => 1, $dark->id => 1], $registry->counts([$icon->id, $main->id, $dark->id, $free->id]));

        foreach ([$icon, $main, $dark] as $asset) {
            $outcome = app(\App\Media\MediaDeleter::class)->delete((int) $asset->id, null);
            $this->assertSame(\App\Media\DeleteOutcome::IN_USE, $outcome->status, 'a used picture is refused');
            $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        }

        $this->assertSame(__('media.usage.site_icon'), $registry->usagesOf((int) $icon->id)[0]->label);
        $this->assertSame(__('media.usage.site_logo'), $registry->usagesOf((int) $main->id)[0]->label);
        $this->assertSame(__('media.usage.site_logo_dark'), $registry->usagesOf((int) $dark->id)[0]->label);
        $this->assertSame(LocaleSettings::getUrl(), $registry->usagesOf((int) $icon->id)[0]->editUrl);

        // replaced: the old picture is free again
        $other = $this->icon(700, 700);
        $this->page()->fillForm(['site_icon' => $other->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame([], (new MediaUsageRegistry)->counts([$icon->id]));
        $this->assertTrue(app(\App\Media\MediaDeleter::class)->delete((int) $icon->id, null)->wasDeleted());
    }
}
