<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\HeaderPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * DS-2a: the optional LOOK of a header row: `bg`, `fg` (hex) and `text_size` (s | m | l). Validated like every other value, absent by default (an untouched layout normalises and renders as it always
 * did), written to the token stylesheet as CSS variables only for the rows that set them, kept by later appearance saves, printed as classes (never a style attribute) and shown by the preview.
 */
class HeaderRowLookTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $parent = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$parent], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
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

    /** @param  array<string, mixed>  $look  @return array<string, mixed> */
    private function layoutWith(string $device, string $row, array $look): array
    {
        $layout = HeaderLayout::default();
        $layout[$device]['rows'][$row] = array_merge($layout[$device]['rows'][$row], $look);

        return $layout;
    }

    private function publish(array $layout): void
    {
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    private function tokens(): string
    {
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);

        return Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');
    }

    // ---- the validator -------------------------------------------------------------------------------------------------------------------

    public function test_a_layout_without_a_look_normalises_exactly_as_before(): void
    {
        $validation = HeaderValidator::validate(HeaderLayout::default());

        $this->assertTrue($validation->passes());
        $this->assertSame(HeaderLayout::default(), $validation->layout);

        foreach (['desktop', 'mobile'] as $device) {
            foreach ($validation->layout[$device]['rows'] as $row) {
                $this->assertSame(['enabled', 'height', 'left', 'center', 'right'], array_keys($row));
            }
        }
    }

    public function test_the_look_is_accepted_and_null_or_empty_values_are_not_stored(): void
    {
        $layout = $this->layoutWith('desktop', 'top', ['bg' => '#112233', 'fg' => '#FFF', 'text_size' => 'l']);
        $layout['desktop']['rows']['main'] += ['bg' => null, 'fg' => '', 'text_size' => null];
        $validation = HeaderValidator::validate($layout);

        $this->assertSame([], $validation->errors);
        $this->assertSame(['enabled', 'height', 'bg', 'fg', 'text_size', 'left', 'center', 'right'], array_keys($validation->layout['desktop']['rows']['top']));
        $this->assertSame('#112233', $validation->layout['desktop']['rows']['top']['bg']);
        $this->assertSame('#ffffff', $validation->layout['desktop']['rows']['top']['fg'], '#rgb is stored as #rrggbb, lower case');
        $this->assertSame(['enabled', 'height', 'left', 'center', 'right'], array_keys($validation->layout['desktop']['rows']['main']), 'null and empty are absent');
    }

    public function test_a_bad_colour_or_size_is_refused_on_its_own_field(): void
    {
        foreach (['bg' => 'red', 'fg' => '#12345', 'text_size' => 'xl'] as $key => $value) {
            $validation = HeaderValidator::validate($this->layoutWith('desktop', 'top', [$key => $value]));

            $this->assertArrayHasKey("desktop.rows.top.{$key}", $validation->errors, $key);
        }

        $this->assertSame('A colour like #1a2b3c.', HeaderValidator::validate($this->layoutWith('mobile', 'main', ['bg' => 'javascript:1']))->errors['mobile.rows.main.bg']);
        $this->assertArrayHasKey('desktop.rows.top.bg', HeaderValidator::validate($this->layoutWith('desktop', 'top', ['bg' => ['#fff']]))->errors);
        $this->assertArrayHasKey('desktop.rows.top.bg', HeaderValidator::validate($this->layoutWith('desktop', 'top', ['bg' => '#fff;color:red']))->errors, 'no way to inject CSS');
        $this->assertSame([], array_diff(array_keys(HeaderValidator::validate($this->layoutWith('desktop', 'top', ['colour' => '#fff']))->errors), ['desktop.rows.top']), 'an unknown key is still an unknown key');
    }

    // ---- the stylesheet ------------------------------------------------------------------------------------------------------------------

    public function test_the_variables_exist_only_for_the_rows_that_set_them(): void
    {
        $layout = $this->layoutWith('desktop', 'main', ['bg' => '#112233', 'fg' => '#EEEEEE']);
        $layout['mobile']['rows']['top'] = array_merge($layout['mobile']['rows']['top'], ['bg' => '#abc']);
        $this->publish($layout);
        $css = $this->tokens();

        foreach (['--sf-hdr-d-main-bg: #112233;', '--sf-hdr-d-main-fg: #eeeeee;', '--sf-hdr-m-top-bg: #aabbcc;'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }

        foreach (['--sf-hdr-d-main-h', '--sf-hdr-d-top-bg', '--sf-hdr-d-top-fg', '--sf-hdr-d-bottom-bg', '--sf-hdr-m-main-bg', '--sf-hdr-m-top-fg'] as $absent) {
            $this->assertStringNotContainsString($absent, $css, "{$absent} was not set");
        }
    }

    public function test_a_layout_without_a_look_writes_no_colour_variable_and_no_stylesheet(): void
    {
        $this->publish(HeaderLayout::default());

        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY));
    }

    public function test_an_appearance_save_keeps_the_published_header_colours(): void
    {
        $this->publish($this->layoutWith('desktop', 'top', ['bg' => '#0a0b0c']));

        $compiled = app(AppearanceCompiler::class)->compile(['appearance.color.primary' => '#123456']);
        $css = Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');

        $this->assertStringContainsString('--sf-primary: #123456;', $css);
        $this->assertStringContainsString('--sf-hdr-d-top-bg: #0a0b0c;', $css, 'the colour save did not drop the header colour');
    }

    public function test_the_compiler_refuses_a_colour_variable_that_is_not_a_header_colour(): void
    {
        $compiler = app(AppearanceCompiler::class);

        foreach ([['--sf-hdr-d-main-bg' => 'red'], ['--sf-hdr-d-main-bg' => '#12345'], ['--evil' => '#112233'], ['--sf-hdr-d-main-h' => '#112233'], ['--sf-hdr-x-main-bg' => '#112233']] as $vars) {
            try {
                $compiler->css([], [], [], [], $vars);
                $this->fail('refused: '.json_encode($vars));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the markup ----------------------------------------------------------------------------------------------------------------------

    public function test_an_untouched_row_prints_the_class_it_always_printed(): void
    {
        $this->publish(HeaderLayout::default());
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="sf-hdr-row sf-hdr-row-main">', $html);
        $this->assertStringNotContainsString('sf-ts-', $html);
        $this->assertStringNotContainsString('sf-hdr-has-fg', $html);
    }

    public function test_the_text_size_and_the_colour_marker_are_classes_and_the_page_has_no_style_attribute(): void
    {
        $layout = $this->layoutWith('desktop', 'main', ['bg' => '#112233', 'fg' => '#eeeeee', 'text_size' => 'l']);
        $layout['mobile']['rows']['main'] = array_merge($layout['mobile']['rows']['main'], ['text_size' => 's']);
        $this->publish($layout);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="sf-hdr-row sf-hdr-row-main sf-ts-l sf-hdr-has-fg">', $html);
        $this->assertStringContainsString('<div class="sf-hdr-row sf-hdr-row-main sf-ts-s">', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'no style attribute anywhere in the page');
        $this->assertStringNotContainsString('#112233', $html, 'the colour is in the token stylesheet only');
        $this->assertNoActiveContent($html, "row look");
    }

    public function test_the_stylesheet_rules_have_the_fallbacks_that_keep_an_untouched_shop_the_same(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        foreach (['d-top', 'd-main', 'd-bottom', 'm-top', 'm-main'] as $row) {
            $this->assertStringContainsString("background-color: var(--sf-hdr-{$row}-bg, transparent);", $css);
            $this->assertStringContainsString("color: var(--sf-hdr-{$row}-fg, inherit);", $css);
        }

        foreach (['s' => '0.8125rem', 'm' => '0.9375rem', 'l' => '1.0625rem'] as $size => $rem) {
            $this->assertMatchesRegularExpression('/\.sf-hdr-row\.sf-ts-'.$size.'\s*\{\s*font-size: '.preg_quote($rem, '/').';/', $css);
        }

        $this->assertMatchesRegularExpression('/\.sf-hdr-row\.sf-hdr-has-fg :is\([^)]*\.sf-cart[^)]*\)\s*\{\s*color: inherit;/', $css);
    }

    // ---- the preview and framing ---------------------------------------------------------------------------------------------------------

    public function test_the_preview_stylesheet_carries_the_draft_colours(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layoutWith('desktop', 'main', ['bg' => '#336699', 'text_size' => 'l']))->layout));
        $this->fresh();

        $html = $this->get(HeaderPreviewController::previewUrl())->assertOk()->getContent();
        preg_match('#href="(/storage/appearance/header-preview-[0-9a-f]{12}\.css)"#', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('--sf-hdr-d-main-bg: #336699;', Storage::disk('public')->get(substr($m[1], strlen('/storage/'))));
        $this->assertStringContainsString('sf-ts-l', $html);
    }

    public function test_the_preview_can_be_framed_by_the_admin_and_no_page_forbids_framing(): void
    {
        $preview = $this->get(HeaderPreviewController::previewUrl())->assertOk();
        $home = $this->get('/')->assertOk();

        foreach ([$preview, $home] as $response) {
            $this->assertNull($response->headers->get('X-Frame-Options'));
            $this->assertStringNotContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));
        }

        $this->assertSame('noindex', $preview->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $home->headers->get('X-Content-Type-Options'), 'the home page keeps its headers');
        $this->assertNull($home->headers->get('X-Robots-Tag'));
    }
}
