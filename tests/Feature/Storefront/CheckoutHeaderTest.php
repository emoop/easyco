<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\CheckoutHeader\CheckoutHeaderDrafts;
use App\Storefront\CheckoutHeader\CheckoutHeaderValidator;
use App\Storefront\Design\ComponentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * DS-2b: the SIMPLIFIED header of the cart, the checkout and the order-received page (logo, a back link, an optional phone). Off by default (the full header stays, byte for byte); when the merchant
 * publishes it enabled it replaces the top banner and the full header on those three pages only; its values are validated on save, on publish and on every read; it rides the one cached
 * appearance read (no query); colours and logo heights are CSS variables, never a style attribute; the preview is a signed, noindex, never-cached page.
 */
class CheckoutHeaderTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withHeader('Referer', 'http://localhost/');
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

    /** @param  array<string, mixed>  $change  @return array<string, mixed> */
    private function values(array $change = []): array
    {
        return array_replace(CheckoutHeaderCatalog::defaults(), ['enabled' => true, 'phone' => '+359 888 123 456', 'back_label' => 'Keep shopping', 'back_url' => '/product-category/clothing'], $change);
    }

    /** @param  array<string, mixed>  $values */
    private function publish(array $values): void
    {
        app(CheckoutHeaderCompiler::class)->publish($values, $this->settings());
        $this->fresh();
    }

    private const FULL = 'data-sf-header';

    private const SIMPLE = 'data-sf-checkout-header';

    // ---- the validator -------------------------------------------------------------------------------------------------------------------

    public function test_the_defaults_are_valid_and_the_feature_is_off(): void
    {
        [$clean, $errors] = CheckoutHeaderValidator::validate(CheckoutHeaderCatalog::defaults());

        $this->assertSame([], $errors);
        $this->assertSame(CheckoutHeaderCatalog::defaults(), $clean);
        $this->assertFalse($clean['enabled']);
        $this->assertSame(['enabled', 'logo_height_d', 'logo_height_m', 'back_label', 'back_url', 'phone', 'bg', 'fg'], array_keys($clean));
    }

    public function test_the_boundaries_are_accepted_and_the_values_are_normalised(): void
    {
        [$clean, $errors] = CheckoutHeaderValidator::validate($this->values(['logo_height_d' => 80, 'logo_height_m' => 64, 'back_label' => str_repeat('l', 30), 'bg' => '#ABC', 'fg' => '#FFFFFF', 'back_url' => '']));
        $this->assertSame([], $errors);
        $this->assertSame(['#aabbcc', '#ffffff', '/'], [$clean['bg'], $clean['fg'], $clean['back_url']]);

        [, $errors] = CheckoutHeaderValidator::validate($this->values(['logo_height_d' => 16, 'logo_height_m' => 16, 'back_url' => 'https://example.test/shop']));
        $this->assertSame([], $errors);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badValues(): array
    {
        return [
            'a desktop logo of 15px' => [['logo_height_d' => 15], 'logo_height_d'],
            'a desktop logo of 81px' => [['logo_height_d' => 81], 'logo_height_d'],
            'a phone logo of 65px' => [['logo_height_m' => 65], 'logo_height_m'],
            'a link text over 30 characters' => [['back_label' => str_repeat('l', 31)], 'back_label'],
            'a link text with a control character' => [['back_label' => "a\x07"], 'back_label'],
            'an unsafe link' => [['back_url' => 'javascript:alert(1)'], 'back_url'],
            'an http link' => [['back_url' => 'http://example.test'], 'back_url'],
            'a phone that is not a number' => [['phone' => 'call me'], 'phone'],
            'a national number without a country' => [['phone' => '0888 123 456'], 'phone'],
            'a colour that is not hex' => [['bg' => 'red'], 'bg'],
            'a colour with CSS in it' => [['fg' => '#fff;color:red'], 'fg'],
            'an unknown setting' => [['evil' => 'x'], 'evil'],
        ];
    }

    /** @param  array<string, mixed>  $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_a_bad_value_is_refused_on_its_own_field(array $change, string $field): void
    {
        [, $errors] = CheckoutHeaderValidator::validate($this->values($change));

        $this->assertArrayHasKey($field, $errors);
        $this->assertSame([$field], array_keys($errors));
    }

    public function test_a_value_that_is_not_an_array_is_refused(): void
    {
        $this->assertNotSame([], CheckoutHeaderValidator::validate('nope')[1]);
        $this->assertNotSame([], CheckoutHeaderValidator::validate(null)[1]);
    }

    public function test_the_registry_describes_it_as_one_component_with_the_same_fields(): void
    {
        $set = ComponentRegistry::for('checkout_header');

        $this->assertSame(['checkout_header'], array_keys($set));
        $this->assertSame(CheckoutHeaderCatalog::fields(), $set['checkout_header']['fields']);
    }

    // ---- storage -----------------------------------------------------------------------------------------------------------------------

    public function test_publishing_stores_the_published_value_and_the_compiled_document_in_the_one_read(): void
    {
        $this->assertContains(AppearanceSettings::DESIGN_COMPILED_KEY, AppearanceSettings::READ_KEYS);
        $this->assertSame('design.checkout_header.global.published', AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY);
        $this->assertSame('design.checkout_header.global.draft', AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY);

        $this->publish($this->values());
        $first = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);

        $this->assertSame($this->values(), $first['zones']['checkout_header']['global']);
        $this->assertSame($this->values(), json_decode((string) $this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY), true));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first['hash']);
        $this->assertLessThan(CheckoutHeaderCompiler::MAX_BYTES, strlen((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY)));

        $this->publish($this->values(['back_label' => 'Another']));
        $this->assertNotSame($first['hash'], json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true)['hash']);
    }

    public function test_publishing_an_invalid_value_throws_and_stores_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            app(CheckoutHeaderCompiler::class)->publish($this->values(['back_url' => 'javascript:1']), $this->settings());
        } finally {
            $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
            $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY));
        }
    }

    public function test_the_starting_values_are_the_draft_then_the_published_then_the_defaults(): void
    {
        $this->assertSame(CheckoutHeaderCatalog::defaults(), CheckoutHeaderDrafts::startingValues());

        $this->settings()->set(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY, json_encode($this->values(['back_label' => 'Published'])));
        $this->assertSame('Published', CheckoutHeaderDrafts::startingValues()['back_label']);

        $this->settings()->set(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, json_encode($this->values(['back_label' => 'Draft'])));
        $this->assertSame('Draft', CheckoutHeaderDrafts::startingValues()['back_label']);

        $this->settings()->set(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, '{"back_url":"javascript:1"}');
        $this->assertSame('Published', CheckoutHeaderDrafts::startingValues()['back_label'], 'a draft that does not validate is skipped');
    }

    // ---- the stylesheet ----------------------------------------------------------------------------------------------------------------

    private function tokens(): ?string
    {
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);

        return $compiled === null ? null : Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');
    }

    public function test_the_variables_exist_only_for_what_is_set_and_an_appearance_save_keeps_them(): void
    {
        $this->publish($this->values());
        $this->assertNull($this->tokens(), 'no colour and the default heights: no stylesheet at all');

        $this->publish($this->values(['bg' => '#112233', 'logo_height_d' => 40]));
        $css = (string) $this->tokens();
        $this->assertStringContainsString('--sf-chdr-bg: #112233;', $css);
        $this->assertStringContainsString('--sf-chdr-logo-d-h: 40px;', $css);

        foreach (['--sf-chdr-fg', '--sf-chdr-logo-m-h'] as $absent) {
            $this->assertStringNotContainsString($absent, $css, "{$absent} is not set");
        }

        $compiled = app(AppearanceCompiler::class)->compile(['appearance.color.primary' => '#123456']);
        $kept = Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');
        $this->assertStringContainsString('--sf-primary: #123456;', $kept);
        $this->assertStringContainsString('--sf-chdr-bg: #112233;', $kept, 'a colour save did not drop it');
    }

    public function test_a_disabled_published_value_writes_no_variable(): void
    {
        $this->publish($this->values(['enabled' => false, 'bg' => '#112233']));

        $this->assertNull($this->tokens());
    }

    public function test_the_compiler_refuses_a_variable_that_is_not_a_checkout_header_one(): void
    {
        $compiler = app(AppearanceCompiler::class);

        foreach ([['--sf-chdr-bg' => 'red'], ['--sf-chdr-logo-d-h' => '5px'], ['--sf-chdr-logo-d-h' => '40em'], ['--evil' => '#112233'], ['--sf-chdr-x' => '#112233']] as $vars) {
            try {
                $compiler->css([], [], [], [], [], $vars);
                $this->fail('refused: '.json_encode($vars));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the pages ---------------------------------------------------------------------------------------------------------------------

    public function test_off_by_default_the_cart_and_the_checkout_keep_the_full_header_unchanged(): void
    {
        $cart = $this->get('/cart')->assertOk()->getContent();
        $checkout = $this->get('/checkout')->assertOk()->getContent();

        foreach ([$cart, $checkout] as $html) {
            $this->assertStringContainsString(self::FULL, $html);
            $this->assertStringNotContainsString(self::SIMPLE, $html);
        }

        // a published value that is switched off changes nothing at all
        $this->publish($this->values(['enabled' => false]));
        $this->assertSame($cart, $this->get('/cart')->getContent());
        $this->assertSame($checkout, $this->get('/checkout')->getContent());
    }

    public function test_enabled_the_cart_and_the_checkout_show_the_simplified_header_instead_of_the_banner_and_the_full_one(): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_ENABLED, '1');
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'Shop wide sale');
        $this->publish($this->values());

        foreach (['/cart', '/checkout'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(self::SIMPLE, $html, $url);
            $this->assertStringNotContainsString(self::FULL, $html, $url);
            $this->assertStringNotContainsString('Shop wide sale', $html, 'the banner is replaced on '.$url);
            $this->assertStringContainsString('href="/product-category/clothing"', $html);
            $this->assertStringContainsString('Keep shopping', $html);
            $this->assertStringContainsString('href="tel:+359888123456"', $html);
            $this->assertStringContainsString('+359 888 123 456', $html);
            $this->assertStringContainsString('<img src=', $html);
            $this->assertStringContainsString('alt="Raf Shop"', $html);
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, "no style attribute on {$url}");
        }
    }

    public function test_it_is_not_on_the_home_the_category_or_the_product_page(): void
    {
        $this->settings()->set(AppearanceSettings::BANNER_ENABLED, '1');
        $this->settings()->set(AppearanceSettings::BANNER_TEXT, 'Shop wide sale');
        $this->publish($this->values());

        foreach (['/', '/product-category/clothing', '/product/dress'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::SIMPLE, $html, $url);
            $this->assertStringContainsString(self::FULL, $html, $url);
            $this->assertStringContainsString('Shop wide sale', $html, $url);
        }
    }

    public function test_a_hand_edited_value_with_an_unsafe_link_or_a_bad_colour_turns_the_feature_off(): void
    {
        foreach ([['back_url' => 'javascript:alert(1)'], ['bg' => 'red'], ['logo_height_d' => 5000], ['phone' => '"><script>']] as $bad) {
            $document = ['hash' => 'abcdef123456', 'zones' => ['checkout_header' => ['global' => $this->values($bad)]]];
            $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode($document));
            $this->fresh();

            $html = $this->get('/cart')->assertOk()->getContent();

            $this->assertStringContainsString(self::FULL, $html, json_encode($bad));
            $this->assertStringNotContainsString(self::SIMPLE, $html, json_encode($bad));
            $this->assertStringNotContainsString('javascript:', $html);
        }

        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, 'not json');
        $this->fresh();
        $this->assertStringContainsString(self::FULL, $this->get('/cart')->getContent());
    }

    public function test_the_phone_link_keeps_only_a_plus_and_digits_and_every_text_is_escaped(): void
    {
        $this->publish($this->values(['back_label' => '<b>Back</b> & more']));
        $html = $this->get('/cart')->getContent();

        $this->assertStringContainsString('&lt;b&gt;Back&lt;/b&gt; &amp; more', $html);
        $this->assertStringNotContainsString('<b>Back</b>', $html);
        $this->assertStringContainsString('href="tel:+359888123456"', $html);
    }

    public function test_without_a_phone_there_is_no_phone_link_and_an_empty_label_uses_the_shops_words(): void
    {
        $this->publish($this->values(['phone' => '', 'back_label' => '']));
        $html = $this->get('/cart')->getContent();

        $this->assertStringNotContainsString('href="tel:', $html);
        $this->assertStringContainsString('Back to the shop', $html);
    }

    public function test_the_header_is_a_landmark_with_a_named_link_and_the_markup_is_safe(): void
    {
        $this->publish($this->values());
        $html = $this->get('/cart')->getContent();

        $this->assertMatchesRegularExpression('#<header class="sf-chdr" data-sf-checkout-header>#', $html);
        $this->assertSame(1, substr_count($html, '<header'), 'one header landmark on the page');
        $this->assertMatchesRegularExpression('#<a class="sf-chdr-back" href="[^"]+" aria-label="Keep shopping">#', $html);
        $this->assertDoesNotMatchRegularExpression('#<script#i', substr($html, (int) strpos($html, 'sf-chdr'), 2500));
    }

    public function test_the_stylesheet_rules_use_the_variables_with_fallbacks(): void
    {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        $this->assertStringContainsString('background-color: var(--sf-chdr-bg, var(--sf-header-bg, #fff));', $css);
        $this->assertStringContainsString('height: var(--sf-chdr-logo-d-h, 28px);', $css);
        $this->assertStringContainsString('height: var(--sf-chdr-logo-m-h, 26px);', $css);
    }

    // ---- no new query --------------------------------------------------------------------------------------------------------------------

    public function test_the_cart_and_the_checkout_cost_no_query_more_with_the_feature_on(): void
    {
        $count = function (string $url): int {
            $this->fresh();

            foreach (Route::getRoutes() as $route) {
                $route->flushController();
            }

            $sql = [];
            DB::listen(function ($query) use (&$sql): void {
                $sql[] = $query->sql;
            });

            $this->get($url)->assertOk();

            return count($sql);
        };

        $off = ['/cart' => $count('/cart'), '/checkout' => $count('/checkout')];
        $this->publish($this->values(['bg' => '#112233']));
        $on = ['/cart' => $count('/cart'), '/checkout' => $count('/checkout')];

        $this->assertSame($off, $on, 'the simplified header rides the one appearance read');
        $this->assertLessThanOrEqual(7, $on['/cart']);
        $this->assertLessThanOrEqual(9, $on['/checkout']);
    }

    // ---- the preview -------------------------------------------------------------------------------------------------------------------

    public function test_the_preview_needs_a_valid_signature_and_a_known_zone_and_expires(): void
    {
        $body = $this->get('/product/this-does-not-exist')->assertNotFound()->getContent();

        $this->assertSame($body, $this->get('/design-preview?zone=checkout_header')->assertNotFound()->getContent());
        $this->assertSame($body, $this->get(URL::temporarySignedRoute('storefront.design.preview', now()->addMinutes(5), ['zone' => 'footer']))->assertNotFound()->getContent());
        $this->assertSame($body, $this->get(URL::temporarySignedRoute('storefront.design.preview', now()->addMinutes(5)))->assertNotFound()->getContent());

        $url = DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE);
        $this->assertStringContainsString('expires=', $url);
        $this->get($url)->assertOk();
        $this->assertSame($body, $this->get(str_replace('zone=checkout_header', 'zone=checkout_header&x=1', $url))->assertNotFound()->getContent(), 'a changed query breaks the signature');

        $this->travel(31)->minutes();
        $this->assertSame($body, $this->get($url)->assertNotFound()->getContent());
    }

    public function test_the_preview_shows_the_draft_on_the_cart_page_and_is_noindex_and_never_cached(): void
    {
        CheckoutHeaderDrafts::saveDraft($this->values(['bg' => '#336699', 'back_label' => 'Draft link']));
        $this->fresh();

        $response = $this->get(DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(self::SIMPLE, $html);
        $this->assertStringContainsString('Draft link', $html);
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('X-Frame-Options'));

        preg_match('#href="(/storage/appearance/design-preview-[0-9a-f]{12}\.css)"#', $html, $m);
        $this->assertNotEmpty($m, 'the preview links its own small stylesheet');
        $this->assertStringContainsString('--sf-chdr-bg: #336699;', Storage::disk('public')->get(substr($m[1], strlen('/storage/'))));

        $this->fresh();
        $public = $this->get('/cart')->getContent();
        $this->assertStringNotContainsString('Draft link', $public, 'the public cart never shows the draft');
        $this->assertStringNotContainsString('design-preview', $public);
    }

    public function test_the_preview_of_a_disabled_draft_shows_the_full_header(): void
    {
        CheckoutHeaderDrafts::saveDraft($this->values(['enabled' => false]));
        $this->fresh();

        $html = $this->get(DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE))->assertOk()->getContent();

        $this->assertStringContainsString(self::FULL, $html);
        $this->assertStringNotContainsString(self::SIMPLE, $html);
    }

    public function test_the_header_and_the_footer_previews_keep_their_own_routes(): void
    {
        $this->assertSame('header-preview', Route::getRoutes()->getByName('storefront.header.preview')->uri());
        $this->assertSame('footer-preview', Route::getRoutes()->getByName('storefront.footer.preview')->uri());
        $this->assertSame('design-preview', Route::getRoutes()->getByName('storefront.design.preview')->uri());
    }
}
