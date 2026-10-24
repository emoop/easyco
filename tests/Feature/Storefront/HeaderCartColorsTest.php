<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Design\DesignScreen;
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
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-2c: the header's cart element has its own colours: the icon and label (`color`), the count badge's background (`badge_bg`) and its number (`badge_fg`). Validated like every colour,
 * null = today's look, written to the token stylesheet as `--sf-cart-{d|m}-*` only when set, kept by later saves, shown by the preview and never an inline style.
 */
class HeaderCartColorsTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$this->makeCategory(1, 'Clothing', 'clothing')], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
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

    /** The default layout whose desktop and phone cart elements carry these settings. @param array<string, mixed> $desktop @param array<string, mixed> $phone @return array<string, mixed> */
    private function layout(array $desktop = [], array $phone = []): array
    {
        $layout = HeaderLayout::default();

        foreach ($layout['desktop']['rows']['main']['right'] as $i => $element) {
            $element['type'] === 'cart' && $layout['desktop']['rows']['main']['right'][$i]['settings'] = $desktop + $element['settings'];
        }

        foreach ($layout['mobile']['rows']['main']['right'] as $i => $element) {
            $element['type'] === 'cart' && $layout['mobile']['rows']['main']['right'][$i]['settings'] = $phone + $element['settings'];
        }

        return $layout;
    }

    private function publish(array $layout): void
    {
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();
    }

    private function tokens(): ?string
    {
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);

        return $compiled === null ? null : Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');
    }

    // ---- the validator -----------------------------------------------------------------------------------------------------------------

    public function test_the_defaults_are_null_and_a_good_colour_is_lowercased_and_expanded(): void
    {
        $settings = fn (array $v) => HeaderValidator::validate($this->layout($v))->layout['desktop']['rows']['main']['right'][2]['settings'];

        $this->assertSame(['style' => 'icon', 'color' => null, 'badge_bg' => null, 'badge_fg' => null], $settings([]));
        $this->assertSame(['color' => '#aabbcc', 'badge_bg' => '#ff0000', 'badge_fg' => '#ffffff'], array_intersect_key($settings(['color' => '#AABBCC', 'badge_bg' => '#F00', 'badge_fg' => '#FFFFFF']), array_flip(['color', 'badge_bg', 'badge_fg'])));
        $this->assertSame(['color' => null, 'badge_bg' => null], array_intersect_key($settings(['color' => '', 'badge_bg' => null]), array_flip(['color', 'badge_bg'])), 'empty and null are "inherit"');
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function badColours(): array
    {
        return ['badge background not hex' => ['badge_bg', 'red'], 'number with CSS in it' => ['badge_fg', '#fff;color:red'], 'icon colour of five digits' => ['color', '#12345'], 'a list' => ['badge_bg', ['#fff']], 'javascript' => ['color', 'javascript:1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badColours')]
    public function test_a_bad_colour_is_refused_on_its_own_field(string $setting, mixed $value): void
    {
        $errors = HeaderValidator::validate($this->layout([$setting => $value]))->errors;

        $this->assertArrayHasKey("desktop.rows.main.right.2.settings.{$setting}", $errors);
        $this->assertSame('A colour like #1a2b3c.', $errors["desktop.rows.main.right.2.settings.{$setting}"]);
    }

    // ---- the stylesheet ----------------------------------------------------------------------------------------------------------------

    public function test_the_variables_exist_only_when_set_and_per_device(): void
    {
        $this->publish($this->layout(['badge_bg' => '#cc0000', 'badge_fg' => '#ffffff'], ['color' => '#0a0a0a', 'badge_bg' => '#00aa00']));
        $css = (string) $this->tokens();

        foreach (['--sf-cart-d-badge-bg: #cc0000;', '--sf-cart-d-badge-fg: #ffffff;', '--sf-cart-m-fg: #0a0a0a;', '--sf-cart-m-badge-bg: #00aa00;'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }

        foreach (['--sf-cart-d-fg', '--sf-cart-m-badge-fg'] as $absent) {
            $this->assertStringNotContainsString($absent, $css, "{$absent} was not set");
        }
    }

    public function test_nothing_set_writes_no_variable_and_no_stylesheet(): void
    {
        $this->assertSame([], HeaderLayout::colorVars(HeaderValidator::validate($this->layout())->layout));

        $this->publish($this->layout());
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY));
    }

    public function test_when_a_layout_has_two_cart_elements_the_first_in_reading_order_decides(): void
    {
        $layout = $this->layout(['badge_bg' => '#111111']);
        $layout['desktop']['rows']['top']['left'] = [['type' => 'cart', 'settings' => ['style' => 'icon', 'color' => null, 'badge_bg' => '#222222', 'badge_fg' => null]]];
        $validated = HeaderValidator::validate($layout);

        $this->assertSame([], $validated->errors, 'two carts are allowed');
        $this->assertSame('#222222', HeaderLayout::colorVars($validated->layout)['--sf-cart-d-badge-bg'], 'the top row comes before the main row');
    }

    public function test_an_appearance_save_keeps_the_published_cart_colours(): void
    {
        $this->publish($this->layout(['badge_bg' => '#cc0000']));

        $compiled = app(AppearanceCompiler::class)->compile(['appearance.color.primary' => '#123456']);
        $css = Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');

        $this->assertStringContainsString('--sf-primary: #123456;', $css);
        $this->assertStringContainsString('--sf-cart-d-badge-bg: #cc0000;', $css);
    }

    public function test_the_compiler_refuses_a_cart_variable_that_is_not_one(): void
    {
        foreach ([['--sf-cart-d-badge-bg' => 'red'], ['--sf-cart-x-fg' => '#112233'], ['--sf-cart-d-evil' => '#112233'], ['--sf-cart-d-fg' => '#12345']] as $vars) {
            try {
                app(AppearanceCompiler::class)->css([], [], [], [], $vars);
                $this->fail('refused: '.json_encode($vars));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the page ----------------------------------------------------------------------------------------------------------------------

    public function test_the_home_page_has_no_style_attribute_and_the_cart_markup_is_unchanged(): void
    {
        $this->publish($this->layout(['color' => '#0a0a0a', 'badge_bg' => '#cc0000', 'badge_fg' => '#ffffff']));
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertStringNotContainsString('#cc0000', $html, 'the colour is in the token stylesheet only');
        $this->assertStringContainsString('<span class="sf-cart-count" data-sf-cart-count hidden></span>', $html);
    }

    public function test_the_stylesheet_rules_have_todays_values_as_fallbacks_and_come_after_the_row_look(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        $this->assertStringContainsString('background-color: var(--sf-cart-d-badge-bg, var(--sf-primary, oklch(20.5% 0 none)));', $css);
        $this->assertStringContainsString('color: var(--sf-cart-m-badge-fg, var(--sf-on-primary, #fff));', $css);
        $this->assertStringContainsString('color: var(--sf-cart-d-fg, var(--sf-hdr-d-main-fg, var(--sf-header-text, #404040)));', $css);
        $this->assertGreaterThan((int) strpos($css, '.sf-hdr-row.sf-hdr-has-fg :is('), (int) strpos($css, '.sf-hdr-desktop .sf-hdr-row-main .sf-cart {'), 'the cart rule is after the row rule');
        $this->assertGreaterThan((int) strpos($css, '.sf-hdr .sf-cart:hover'), (int) strpos($css, '.sf-hdr-desktop .sf-hdr-row .sf-cart:hover'));
    }

    // ---- preview and the Design screen -------------------------------------------------------------------------------------------------

    public function test_the_preview_stylesheet_carries_the_draft_cart_colours(): void
    {
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(HeaderValidator::validate($this->layout(['badge_bg' => '#336699']))->layout));
        $this->fresh();

        $html = $this->get(HeaderPreviewController::previewUrl())->assertOk()->getContent();
        preg_match('#href="(/storage/appearance/header-preview-[0-9a-f]{12}\.css)"#', $html, $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('--sf-cart-d-badge-bg: #336699;', Storage::disk('public')->get(substr($m[1], strlen('/storage/'))));
    }

    public function test_the_design_screen_sets_the_colours_and_apply_stores_them_with_a_contrast_hint(): void
    {
        $this->actingAsStaff('Administrator');
        $screen = Livewire::test(DesignScreen::class)->call('selectNode', 'h-d-main-right-2');

        $this->assertStringContainsString('Count badge background', $screen->html());
        $this->assertStringContainsString('Icon and label colour', $screen->html());
        $screen->assertSet('props.settings.badge_bg', null);

        $screen->set('props.settings.badge_bg', '#cc0000')->set('props.settings.badge_fg', '#cc0001');
        $this->assertStringContainsString('Low contrast between the number', $screen->html());
        $screen->call('apply')->assertHasNoErrors();

        $cart = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true)['desktop']['rows']['main']['right'][2]['settings'];
        $this->assertSame(['#cc0000', '#cc0001', null], [$cart['badge_bg'], $cart['badge_fg'], $cart['color']]);

        $screen->set('props.settings.badge_bg', 'red')->call('apply')->assertHasErrors(['props.settings.badge_bg']);
    }
}
