<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use App\Storefront\Header\AppearanceRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DRIBNI-5, the storefront side: the listing cloud's two colours reach the page ONLY as CSS variables of the token stylesheet (the chips' rules fall back to today's look); an empty colour changes nothing (the same
 * HTML, the same queries); a bad colour reads as empty; and the product page's counter and add-to-cart button are two full-width rows below 48rem.
 */
class DribniFiveStorefrontTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const CLOTHING = '/product-category/clothing';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Coats', 'coats', 1);
        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
        $this->makeProduct(2, ['name' => 'P2', 'slug' => 'p2', 'categories' => [3], 'price' => '10.00', 'stock' => 3]);
    }

    private function visit(string $url): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->get($url);
    }

    /** Publishes a cloud on every category page with the given colours (through the real validator and store). @param  array<string, mixed>  $colours */
    private function publishCloud(array $colours): void
    {
        $set = ContentSet::blank();
        $set['style'] = $colours + ['cloud_shape' => 'site', 'title_style' => 'lines'];
        $set['categories'] = ['default' => ['cloud' => true, 'title' => true, 'top' => null, 'bottom' => null, 'bottom_heading' => ''], 'own' => []];
        [$clean, $errors] = ContentSet::validate($set, ContentStore::existing());
        $this->assertSame([], $errors, json_encode($errors));
        ContentStore::publish($clean);
        $this->app->forgetScopedInstances();
    }

    /** The compiled token stylesheet of the shop, or null. */
    private function css(): ?string
    {
        $compiled = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }

    private function queryCount(string $url): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->visit($url)->assertOk();

        return $n;
    }

    /** ★ the colours are variables of the token stylesheet, and the page itself never carries them (no style=, no attribute) */
    public function test_the_colours_are_variables_of_the_token_stylesheet_and_never_markup(): void
    {
        $this->publishCloud(['cloud_bg' => '#1A2B3C', 'cloud_color' => '#445566']);

        $css = (string) $this->css();
        $this->assertStringContainsString('--sf-cloud-bg: #1a2b3c;', $css);
        $this->assertStringContainsString('--sf-cloud-color: #445566;', $css);

        $html = $this->visit(self::CLOTHING)->assertOk()->getContent();
        $this->assertStringNotContainsString('--sf-cloud', $html);
        $this->assertStringNotContainsString('1a2b3c', $html);
        $this->assertDoesNotMatchRegularExpression('/<nav class="sf-subcategories sf-cloud[^"]*"[^>]*style=/', $html);
        $this->assertStringContainsString('<a class="sf-bv-chip" href="/product-category/clothing/dresses">Dresses</a>', $html, 'the chips are the same links');
    }

    /** ★ the chips' rules use the variables and fall back to the look the shop always had (the site chip: #fff and the text colour; the rounded chip: the tailwind white and neutral-700) */
    public function test_the_chips_use_the_variables_with_the_shops_own_fallbacks(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));

        $this->assertMatchesRegularExpression('/\.sf-cloud-site \.sf-bv-chip \{\s*background: var\(--sf-cloud-bg, #fff\);\s*color: var\(--sf-cloud-color, var\(--sf-pdp-text\)\);\s*\}/', $css);
        $this->assertMatchesRegularExpression('/\.sf-cloud-pill \.sf-subcategory > a \{\s*background-color: var\(--sf-cloud-bg, var\(--color-white\)\);\s*color: var\(--sf-cloud-color, var\(--color-neutral-700\)\);\s*\}/', $css);
        // DRIBNI-6: on hover and keyboard focus the two colours swap places (the DRIBNI-5 hover that kept the background is gone)
        $this->assertMatchesRegularExpression('/\.sf-cloud-site \.sf-bv-chip:hover,\s*\.sf-cloud-site \.sf-bv-chip:focus-visible \{\s*background: var\(--sf-cloud-color, var\(--sf-pdp-text\)\);\s*color: var\(--sf-cloud-bg, #fff\);\s*\}/', $css);
        $this->assertMatchesRegularExpression('/\.sf-cloud-pill \.sf-subcategory > a:hover,\s*\.sf-cloud-pill \.sf-subcategory > a:focus-visible \{\s*background-color: var\(--sf-cloud-color, var\(--color-neutral-700\)\);\s*color: var\(--sf-cloud-bg, var\(--color-white\)\);\s*\}/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.sf-bv-chip:hover \{[^}]*background/', $css, 'the swap is never on the chip shared with the filters');
    }

    /** ★ an empty colour changes nothing: the same HTML as with no colour set, no variable in the stylesheet, and the same queries (the colours cost no query on a page) */
    public function test_an_empty_colour_changes_nothing(): void
    {
        $this->publishCloud([]);
        $plain = $this->visit(self::CLOTHING)->assertOk()->getContent();
        $plainCss = (string) $this->css();
        $plainQueries = $this->queryCount(self::CLOTHING);

        $this->assertStringNotContainsString('--sf-cloud', $plainCss);

        // the page links its token stylesheet by its hash: a changed stylesheet is a new link (the same for every colour the shop sets); the rest of the page is the same
        $this->publishCloud(['cloud_bg' => '#112233', 'cloud_color' => '#eeeeee']);
        $withColours = $this->visit(self::CLOTHING)->assertOk()->getContent();
        $squash = static fn (string $html): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('#<link rel="stylesheet" href="/storage/appearance/tokens-[0-9a-f]+\.css">#', '', $html)));
        $this->assertSame($squash($plain), $squash($withColours), 'the colours change the stylesheet link and nothing else');
        $this->assertSame($plainQueries, $this->queryCount(self::CLOTHING), 'the colours cost no query');

        $this->publishCloud(['cloud_bg' => null, 'cloud_color' => null]);
        $this->assertSame($plain, $this->visit(self::CLOTHING)->assertOk()->getContent(), 'emptied again: the same page, the same stylesheet link');
        $this->assertStringNotContainsString('--sf-cloud', (string) $this->css());
    }

    /** ★ a stored value that is not a hex (or a 3-digit one) is EMPTY on read, the other colour stays; the stylesheet never gets it */
    public function test_a_bad_stored_colour_reads_as_empty(): void
    {
        $this->publishCloud(['cloud_bg' => '#112233', 'cloud_color' => '#445566']);

        $stored = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY), true);
        $stored['style']['cloud_bg'] = 'javascript:alert(1)';
        $stored['style']['cloud_color'] = '#abc';
        app(SiteSettingsRepository::class)->set(AppearanceSettings::CONTENT_PUBLISHED_KEY, (string) json_encode($stored));
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));

        $css = (string) $this->css();
        $this->assertStringNotContainsString('--sf-cloud-bg', $css);
        $this->assertStringNotContainsString('--sf-cloud-color', $css, 'a 3-digit colour is not a valid colour here');
        $this->assertStringNotContainsString('javascript', $css);
        $this->visit(self::CLOTHING)->assertOk();
    }

    /** the validator: `#rrggbb` in any case (lower-cased on read), nothing else; the closed list of the two variables */
    public function test_the_colour_rules_are_closed(): void
    {
        $this->assertSame('#abcdef', ContentSet::hex('#ABCDEF'));
        $this->assertNull(ContentSet::hex('#abc'));
        $this->assertNull(ContentSet::hex('red'));
        $this->assertNull(ContentSet::hex(['#abcdef']));
        $this->assertNull(ContentSet::hex(null));

        $this->assertTrue(ContentSet::isValidCloudVar('--sf-cloud-bg', '#112233'));
        $this->assertFalse(ContentSet::isValidCloudVar('--sf-cloud-bg', 'red'));
        $this->assertFalse(ContentSet::isValidCloudVar('--sf-primary', '#112233'), 'not a cloud variable');
        $this->assertSame(['--sf-cloud-bg' => '#112233'], ContentSet::cloudVars(['cloud_bg' => '#112233', 'cloud_color' => null]));
    }

    /** ★ below 48rem the counter and the add-to-cart button are two rows, each the full width; no other breakpoint carries the rule (the source CSS) */
    public function test_on_a_phone_the_counter_and_the_button_are_two_full_width_rows(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/storefront.css'));

        // DRIBNI-6: the −/+ of a phone (`.sf-buy-row .sf-qty-btn { w-12 }`, with a comment) sit between the counter's rule and the button's
        $this->assertMatchesRegularExpression('/@media \(max-width: 47\.99rem\) \{\s*\.sf-buy-row \{\s*@apply flex-wrap;\s*\}\s*\.sf-buy-row > \.sf-qty \{\s*@apply w-full flex-none;\s*\}\s*(?:\/\*[^*]*\*\/\s*)?\.sf-buy-row \.sf-qty-btn \{\s*@apply w-12;\s*\}\s*\.sf-buy-row \.sf-add-to-cart \{\s*@apply w-full flex-none;\s*\}\s*\}/s', $css);
        $this->assertSame(1, substr_count($css, '.sf-buy-row > .sf-qty {'), 'one rule for the counter');
    }
}
