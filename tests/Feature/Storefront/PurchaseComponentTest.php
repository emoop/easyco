<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\AppearanceCompiler;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\ProductSummary\ProductSummaryValidator;
use App\Storefront\ProductSummary\PurchaseStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * PB-1: the «Покупка» component: the pickers by display type (colour circles, size buttons, the dropdown as today), the select that stays the source of truth, the data the script greys combinations out with, the
 * settings of the button (validated), the CSS variables (a closed list) and what the storefront prints from the PUBLISHED layout. The script's own behaviour is tests/Browser/pb-1.mjs.
 */
class PurchaseComponentTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        Storage::fake('public');
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue', 'Green']);
        $this->makeAttribute(2, 'Size', ['S', 'M']);
        DB::table('catalog_attribute_values')->where('id', 1001)->update(['colour' => '#c62828']);
        DB::table('catalog_attribute_values')->where('id', 1002)->update(['colour' => '#1565c0']);
        $this->makeProduct(10, ['type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'variations' => [
            ['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '30.00', 'stock' => 5],
            ['attrs' => [1 => 'Red', 2 => 'M'], 'price' => '30.00', 'stock' => 0],
            ['attrs' => [1 => 'Blue', 2 => 'S'], 'price' => '30.00', 'stock' => 120],
            ['attrs' => [1 => 'Green', 2 => 'M'], 'price' => '30.00', 'stock' => 2],
        ]]);
        $this->makeProduct(11, ['name' => 'Plain thing', 'slug' => 'plain-thing', 'price' => '9.00', 'stock' => 3]);
    }

    private function types(string $colour, string $size): void
    {
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['display_type' => $colour]);
        DB::table('catalog_attribute_definitions')->where('id', 2)->update(['display_type' => $size]);
    }

    private function page(string $slug = 'shirt'): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/'.$slug)->assertOk()->getContent();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @param  array<string, mixed>  $set */
    private function publishButton(array $set): void
    {
        $layout = ProductSummaryLayout::default();

        foreach ($layout['components'] as $i => $component) {
            $component['type'] === 'purchase' && $layout['components'][$i]['settings'] = $set + $component['settings'];
        }

        app(ProductSummaryCompiler::class)->publish($layout, $this->settings());
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> the script's data attribute, decoded */
    private function data(string $html): array
    {
        preg_match('/data-sf-purchase="([^"]*)"/', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true);
    }

    private function tokens(): ?string
    {
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }

    // 1. markup per display type

    public function test_a_colour_attribute_prints_round_swatches_with_names_and_a_size_attribute_prints_buttons(): void
    {
        $this->types('swatch', 'buttons');
        $html = $this->page();

        $this->assertSame(2, substr_count($html, 'class="sf-swatch"'), 'Red and Blue have a colour');
        $this->assertStringContainsString('data-colour="#c62828"', $html);
        $this->assertStringContainsString('<span class="sf-sr">Red</span>', $html, 'the name for a screen reader');
        $this->assertStringContainsString('title="Blue"', $html);
        $this->assertStringContainsString('aria-checked="false"', $html);
        $this->assertSame(3, substr_count($html, 'class="sf-size"'), 'S, M and Green (no colour) are rectangular buttons');
        $this->assertStringContainsString('class="sf-size" aria-checked="false" data-sf-value="0" title="S"', $html);
        $this->assertStringContainsString('<div class="sf-picker-values" role="radiogroup"', $html);
    }

    public function test_a_swatch_value_with_no_colour_is_a_text_button_and_a_non_colour_attribute_has_no_circles(): void
    {
        $this->types('swatch', 'buttons');
        $html = $this->page();

        $this->assertStringContainsString('class="sf-size" aria-checked="false" data-sf-value="2" title="Green"', $html, 'a value with no colour is a text button');

        $this->types('buttons', 'buttons');
        $this->assertStringNotContainsString('sf-swatch', $this->page(), 'the stored colour is not exposed while the type is not swatch');
    }

    public function test_a_dropdown_only_product_prints_today_s_select_and_no_pickers(): void
    {
        $this->types('dropdown', 'dropdown');
        $html = $this->page();

        $this->assertStringNotContainsString('role="radio"', $html);
        $this->assertStringNotContainsString('sf-variations--pickers', $html);
        $this->assertStringContainsString('<select class="sf-variation-select" id="sf-variation" name="variation">', $html);
        $this->assertFalse($this->data($html)['pickers']);
    }

    public function test_the_select_stays_in_the_page_and_a_simple_product_has_no_pickers_only_the_counter_and_the_button(): void
    {
        $this->types('swatch', 'buttons');
        $html = $this->page();

        $this->assertStringContainsString('<select class="sf-variation-select" id="sf-variation" name="variation">', $html, 'the select is the source of truth');

        $plain = $this->page('plain-thing');

        $this->assertStringNotContainsString('sf-picker', $plain);
        $this->assertStringNotContainsString('<select', $plain);
        $this->assertStringContainsString('data-sf-qty-input', $plain);
        $this->assertStringContainsString('class="sf-add-to-cart sf-buy"', $plain);
    }

    public function test_a_mixed_product_prints_a_small_select_for_its_dropdown_attribute(): void
    {
        $this->types('swatch', 'dropdown');
        $html = $this->page();

        $this->assertStringContainsString('class="sf-picker-select"', $html);
        $this->assertStringContainsString('<option value="1">M</option>', $html);
        $this->assertSame(2, count($this->data($html)['variations'][0]['v']));
    }

    // 2. availability: printed in full, flags for the script

    public function test_every_value_is_printed_and_the_table_tells_the_script_which_combinations_can_be_bought(): void
    {
        $this->types('swatch', 'buttons');
        $html = $this->page();
        $data = $this->data($html);

        $this->assertTrue($data['pickers']);
        $this->assertSame(2, $data['groups']);
        $this->assertSame([[0, 0], [0, 1], [1, 0], [2, 1]], array_column($data['variations'], 'v'), 'colour index, size index');
        $this->assertSame([1, 0, 1, 1], array_column($data['variations'], 'o'), 'Red/M is out of stock: not orderable, but its values are still printed');
        $this->assertSame([5, 0, 99, 2], array_column($data['variations'], 'q'), 'the counter maximum: the stock, never above 99');

        foreach (['Red', 'Blue', 'Green', 'S', 'M'] as $value) {
            $this->assertStringContainsString('title="'.$value.'"', $html, "{$value} is printed, never hidden");
        }
    }

    // 3. settings validator

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $settings): array
    {
        $layout = ProductSummaryLayout::default();

        foreach ($layout['components'] as $i => $component) {
            $component['type'] === 'purchase' && $layout['components'][$i]['settings'] = $settings;
        }

        [$clean, $errors] = ProductSummaryValidator::validate($layout);

        foreach ($clean['components'] as $component) {
            if ($component['type'] === 'purchase') {
                return [$component['settings'], $errors];
            }
        }

        return [[], $errors];
    }

    public function test_the_default_settings_validate_with_no_errors(): void
    {
        [$clean, $errors] = $this->validate([]);

        $this->assertSame([], $errors);
        $this->assertSame(['label' => '', 'label_choose' => '', 'label_sold_out' => '', 'bg' => null, 'color' => null, 'border_w' => 0, 'border_color' => null, 'rounded' => true], $clean);
    }

    public function test_the_ranges_have_exact_edges(): void
    {
        $this->assertSame([], $this->validate(['label' => str_repeat('я', 30)])[1], '30 characters');
        $this->assertNotSame([], $this->validate(['label' => str_repeat('я', 31)])[1], '31 characters');

        foreach ([0, 4, '3'] as $ok) {
            $this->assertSame([], $this->validate(['border_w' => $ok])[1], "border_w {$ok}");
        }

        foreach ([5, -1, '1;color:red', '1e3', str_repeat('9', 600), 2.5, true] as $bad) {
            [$clean, $errors] = $this->validate(['border_w' => $bad]);
            $this->assertNotSame([], $errors, 'border_w '.var_export($bad, true).' is refused');
            $this->assertSame(0, $clean['border_w'], 'and falls back to the default');
        }
    }

    public function test_colours_are_rrggbb_only_and_stored_lower_case(): void
    {
        foreach (['bg', 'color', 'border_color'] as $field) {
            [$clean, $errors] = $this->validate([$field => '#2E7D32']);
            $this->assertSame([], $errors);
            $this->assertSame('#2e7d32', $clean[$field]);

            foreach (['#fff', 'red', '#GGGGGG', '#12345', '1;color:red', 'url(x)', str_repeat('a', 600)] as $bad) {
                [$clean, $errors] = $this->validate([$field => $bad]);
                $this->assertNotSame([], $errors, "{$field} {$bad}");
                $this->assertNull($clean[$field]);
            }

            $this->assertNull($this->validate([$field => ''])[0][$field], 'empty = none');
        }
    }

    public function test_unknown_keys_are_refused_text_is_plain_and_rounded_must_be_a_boolean(): void
    {
        $this->assertNotSame([], $this->validate(['colour' => '#c62828'])[1], 'an unknown key');
        $this->assertNotSame([], $this->validate(['label' => "Buy"])[1], 'a control character is not plain text');
        $this->assertSame('<b>Buy</b>', $this->validate(['label' => '<b>Buy</b>'])[0]['label'], 'a tag is only text: kept as typed and ESCAPED on output (see the printing test)');
        $this->assertNotSame([], $this->validate(['rounded' => 'yes'])[1]);
        $this->assertNotSame([], $this->validate(['rounded' => 1])[1]);
        $this->assertSame([], $this->validate(['rounded' => false])[1]);
    }

    // 4. CSS variables

    public function test_only_values_that_differ_from_the_defaults_are_emitted(): void
    {
        $this->assertSame([], PurchaseStyle::vars($this->validate([])[0]));
        $this->assertSame(['--sf-buy-bg' => '#2e7d32', '--sf-buy-bg-hover' => PurchaseStyle::hover('#2e7d32'), '--sf-buy-off' => '0.5'], PurchaseStyle::vars($this->validate(['bg' => '#2E7D32'])[0]));
        $this->assertSame(['--sf-buy-bw' => '2px', '--sf-buy-border' => 'currentcolor'], PurchaseStyle::vars($this->validate(['border_w' => 2])[0]));
        $this->assertSame(['--sf-buy-radius' => '0'], PurchaseStyle::vars($this->validate(['rounded' => false])[0]));
        $this->assertSame([], PurchaseStyle::vars($this->validate(['label' => 'Buy now'])[0]), 'a text is not a style');
    }

    public function test_the_closed_list_accepts_what_is_generated_and_refuses_injection(): void
    {
        foreach (PurchaseStyle::vars($this->validate(['bg' => '#0a0a0a', 'color' => '#FFFFFF', 'border_w' => 4, 'border_color' => '#ff0000', 'rounded' => false])[0]) as $variable => $value) {
            $this->assertTrue(PurchaseStyle::isValidVar($variable, $value), "{$variable}: {$value}");
        }

        foreach (['#fff', '#c62828;}', '#c62828} body{x:y', 'url(x)', 'expression(1)', 'red', "#c62828\n", '#C62828'] as $bad) {
            $this->assertFalse(PurchaseStyle::isValidVar('--sf-buy-bg', $bad), $bad);
            $this->assertFalse(PurchaseStyle::isValidVar('--sf-buy-color', $bad), $bad);
        }

        $this->assertFalse(PurchaseStyle::isValidVar('--sf-buy-bw', '5px'));
        $this->assertFalse(PurchaseStyle::isValidVar('--sf-buy-bw', '2px;x'));
        $this->assertFalse(PurchaseStyle::isValidVar('--sf-buy-radius', '8px'));
        $this->assertFalse(PurchaseStyle::isValidVar('--sf-primary', '#c62828'), 'not on the list');

        $this->expectException(\InvalidArgumentException::class);
        app(AppearanceCompiler::class)->css([], buyVars: ['--sf-buy-bg' => 'url(javascript:1)']);
    }

    public function test_with_no_setting_the_stylesheet_is_what_it_was_and_a_published_setting_adds_its_variables(): void
    {
        $this->assertNull(app(AppearanceCompiler::class)->css([], buyVars: []));
        $this->assertStringNotContainsString('--sf-buy', (string) app(AppearanceCompiler::class)->css(['appearance.color.primary' => '#123456']));

        $this->publishButton(['bg' => '#2E7D32', 'border_w' => 2, 'rounded' => false]);
        $css = (string) $this->tokens();

        foreach (['--sf-buy-bg: #2e7d32;', '--sf-buy-bw: 2px;', '--sf-buy-border: currentcolor;', '--sf-buy-radius: 0;', '--sf-buy-off: 0.5;'] as $line) {
            $this->assertStringContainsString($line, $css);
        }

        $this->assertStringNotContainsString('--sf-buy-color', $css, 'what is not set is not written');

        app(ProductSummaryCompiler::class)->unpublish($this->settings());
        $this->assertNull($this->tokens(), 'unpublished: back to no stylesheet at all');
    }

    // 5. what the storefront prints

    public function test_the_published_texts_are_printed_escaped_and_the_default_texts_are_the_shops_own(): void
    {
        $html = $this->page('plain-thing');

        $this->assertStringContainsString('data-sf-label="Add to cart"', $html);
        $this->assertStringContainsString('data-sf-label-choose="Choose a size"', $html);
        $this->assertStringContainsString('data-sf-label-sold-out="Sold out"', $html);

        $this->publishButton(['label' => 'Buy "now" & <go>', 'label_choose' => 'Pick one', 'label_sold_out' => 'Gone']);
        $html = $this->page('plain-thing');

        $this->assertStringContainsString('data-sf-label="Buy &quot;now&quot; &amp; &lt;go&gt;"', $html);
        $this->assertStringContainsString('>Buy &quot;now&quot; &amp; &lt;go&gt;</button>', $html);
        $this->assertStringContainsString('data-sf-label-choose="Pick one"', $html);
        $this->assertStringNotContainsString('<go>', $html);
    }

    public function test_a_draft_is_never_read_by_the_storefront(): void
    {
        $layout = ProductSummaryLayout::default();

        foreach ($layout['components'] as $i => $component) {
            $component['type'] === 'purchase' && $layout['components'][$i]['settings']['label'] = 'From a draft';
        }

        $this->settings()->set(AppearanceSettings::SUMMARY_DRAFT_KEY, json_encode($layout));

        $this->assertStringNotContainsString('From a draft', $this->page('plain-thing'));
    }

    public function test_a_stored_setting_that_is_not_valid_falls_back_to_the_default_page(): void
    {
        $default = $this->page('plain-thing');
        $bad = ProductSummaryLayout::default();

        foreach ($bad['components'] as $i => $component) {
            $component['type'] === 'purchase' && $bad['components'][$i]['settings'] = ['bg' => 'red;}', 'border_w' => 99] + $component['settings'];
        }

        $this->settings()->set(AppearanceSettings::DESIGN_COMPILED_KEY, json_encode(['hash' => 'x', 'zones' => ['product_summary' => ['global' => ['layout' => $bad, 'blocks' => []]]]]));
        \Illuminate\Support\Facades\Cache::forget(\App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY);

        $this->assertSame($default, $this->page('plain-thing'), 'the page is the default one and answers 200');
    }

    // 8. sessionless, no style, no inline script

    public function test_the_product_page_stays_sessionless_with_no_style_attribute_and_no_inline_script(): void
    {
        $this->types('swatch', 'buttons');
        $this->publishButton(['bg' => '#2E7D32', 'label' => 'Buy']);

        $this->app->forgetScopedInstances();
        $response = $this->get('/product/shirt')->assertOk();
        $html = $response->getContent();

        $this->assertSame([], $response->headers->getCookies(), 'no cookie, no session');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
    }
}
