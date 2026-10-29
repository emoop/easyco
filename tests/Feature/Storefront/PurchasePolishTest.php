<?php

namespace Tests\Feature\Storefront;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\ProductSummary\ProductSummaryCompiler;
use App\Storefront\ProductSummary\ProductSummaryLayout;
use App\Storefront\ProductSummary\ProductSummaryValidator;
use App\Storefront\ProductSummary\PurchaseView;
use App\Storefront\Reader\CatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * PB-1.1: the polish of «Покупка»: the counter without a visible label on ONE row with the button, the setting that turns the counter off, the order of the attribute groups the merchant chose (and that the
 * chosen variation stays the right one after a reorder), and the attribute id the read model now carries. The script's own behaviour is tests/Browser/pb-1.mjs.
 */
class PurchasePolishTest extends TestCase
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
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['display_type' => 'swatch']);
        DB::table('catalog_attribute_definitions')->where('id', 2)->update(['display_type' => 'buttons']);
        DB::table('catalog_attribute_values')->where('id', 1001)->update(['colour' => '#c62828']);
        DB::table('catalog_attribute_values')->where('id', 1002)->update(['colour' => '#1565c0']);
        $this->makeProduct(10, ['type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'variations' => [
            ['attrs' => [1 => 'Red', 2 => 'S'], 'price' => '30.00', 'stock' => 5],
            ['attrs' => [1 => 'Red', 2 => 'M'], 'price' => '30.00', 'stock' => 0],
            ['attrs' => [1 => 'Blue', 2 => 'S'], 'price' => '30.00', 'stock' => 120],
            ['attrs' => [1 => 'Green', 2 => 'M'], 'price' => '30.00', 'stock' => 2],
        ]]);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @param  array<string, mixed>  $set */
    private function publish(array $set): void
    {
        $layout = ProductSummaryLayout::default();

        foreach ($layout['components'] as $i => $component) {
            $component['type'] === 'purchase' && $layout['components'][$i]['settings'] = $set + $component['settings'];
        }

        app(ProductSummaryCompiler::class)->publish($layout, $this->settings());
        $this->app->forgetScopedInstances();
    }

    private function page(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/product/shirt')->assertOk()->getContent();
    }

    /** @param  array<string, mixed>  $settings @return array{0: array<string, mixed>, 1: array<string, string>} */
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

    /** @return list<string> the group names in the order the page prints them */
    private function groupNames(string $html): array
    {
        preg_match_all('/<span class="sf-picker-label" id="sf-pg-\d+">([^<]*)<\/span>/', $html, $m);

        return $m[1];
    }

    // 1. the counter row

    public function test_the_counter_has_no_visible_label_an_accessible_name_and_shares_one_row_with_the_button(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('sf-qty-label', $html);
        $this->assertStringNotContainsString('<label class="sf-qty', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Quantity\s*</', $html, 'no visible «Quantity» text');
        $this->assertStringContainsString('aria-label="Quantity"', $html);
        $this->assertMatchesRegularExpression('/<div class="sf-buy-row">.*data-sf-qty-input.*class="sf-add-to-cart sf-buy".*<\/div>\s*<p class="sf-cart-status"/s', $html, 'counter then button in ONE row, the status line under it');
    }

    // 2. show_quantity

    public function test_the_counter_is_there_by_default_and_gone_when_the_setting_is_false(): void
    {
        $this->assertStringContainsString('data-sf-qty-input', $this->page());

        $this->publish(['show_quantity' => false]);
        $html = $this->page();

        foreach (['data-sf-qty', 'sf-qty-input', 'sf-qty-box', 'sf-qty-btn', 'id="sf-qty"'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, $needle);
        }

        $this->assertStringContainsString('class="sf-add-to-cart sf-buy"', $html, 'the button is the whole row');
    }

    public function test_show_quantity_is_a_boolean_default_true_and_only_false_is_stored_as_a_difference(): void
    {
        $this->assertTrue($this->validate([])[0]['show_quantity']);
        $this->assertSame([], $this->validate(['show_quantity' => false])[1]);
        $this->assertFalse($this->validate(['show_quantity' => false])[0]['show_quantity']);

        foreach (['no', 'false', 0, 1, []] as $bad) {
            $this->assertNotSame([], $this->validate(['show_quantity' => $bad])[1], 'refused: '.var_export($bad, true));
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY), 'the default layout is not stored at all');

        $this->publish(['show_quantity' => false]);
        $stored = json_decode((string) $this->settings()->get(AppearanceSettings::SUMMARY_PUBLISHED_KEY), true);
        $purchase = array_values(array_filter($stored['components'], static fn (array $c): bool => $c['type'] === 'purchase'))[0];

        $this->assertFalse($purchase['settings']['show_quantity']);
    }

    // 3. the order of the groups

    public function test_the_groups_follow_the_chosen_order_and_the_others_follow_in_the_default_order(): void
    {
        $this->assertSame(['Colour', 'Size'], $this->groupNames($this->page()));

        $this->publish(['axes' => [2, 1]]);
        $this->assertSame(['Size', 'Colour'], $this->groupNames($this->page()));

        $this->publish(['axes' => [2]]);
        $this->assertSame(['Size', 'Colour'], $this->groupNames($this->page()), 'the one listed is first, the one not listed follows');

        $this->publish(['axes' => [1]]);
        $this->assertSame(['Colour', 'Size'], $this->groupNames($this->page()));
    }

    public function test_an_id_the_product_does_not_have_is_ignored_and_no_axis_is_ever_hidden(): void
    {
        $this->publish(['axes' => [3, 999, 2]]);

        $this->assertSame(['Size', 'Colour'], $this->groupNames($this->page()), '3 and 999 are not attributes of this product');
        $this->assertSame([1, 0], PurchaseView::order([10, 20], [20, 10, 20]), 'a repeated id counts once');
        $this->assertSame([0, 1, 2], PurchaseView::order([5, 6, 7], []));
        $this->assertSame([2, 0, 1], PurchaseView::order([5, 6, 7], [7, 8]));
    }

    public function test_the_list_is_validated_as_a_list_of_whole_positive_ids_with_exact_edges(): void
    {
        $this->assertSame([], $this->validate([])[0]['axes']);
        $this->assertSame([2, 1], $this->validate(['axes' => [2, 1, 2]])[0]['axes'], 'duplicates removed');
        $this->assertSame([], $this->validate(['axes' => range(1, 10)])[1], '10 ids');

        [$clean, $errors] = $this->validate(['axes' => range(1, 11)]);
        $this->assertNotSame([], $errors, '11 ids');
        $this->assertCount(10, $clean['axes']);

        foreach ([['a'], [-1], [0], [1.5], ['1'], [str_repeat('9', 600)], [true], [null], ['x' => 1], 'abc', 5, [[1]]] as $bad) {
            $this->assertNotSame([], $this->validate(['axes' => $bad])[1], 'refused: '.json_encode($bad));
        }

        $this->assertSame([PHP_INT_MAX], $this->validate(['axes' => [PHP_INT_MAX]])[0]['axes']);
    }

    public function test_after_a_reorder_every_row_of_the_table_still_points_at_its_own_values(): void
    {
        $this->publish(['axes' => [2, 1]]);
        $html = $this->page();
        preg_match('/data-sf-purchase="([^"]*)"/', $html, $m);
        $data = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        // the printed values per group, in the printed group order: group index => [value index => label]
        $values = [];

        foreach (preg_split('/(?=<div class="sf-picker" data-sf-group=")/', $html) as $chunk) {
            if (preg_match('/^<div class="sf-picker" data-sf-group="(\d+)"/', $chunk, $g) === 1) {
                preg_match_all('/data-sf-value="(\d+)"[^>]*title="([^"]*)"/', $chunk, $v, PREG_SET_ORDER);
                $values[(int) $g[1]] = array_column($v, 2, 1);
            }
        }

        $this->assertSame(['S', 'M'], array_values($values[0]), 'the first group is Size');

        $reader = $this->app->make(CatalogReader::class)->product('shirt');
        $this->assertCount(4, $data['variations']);

        foreach ($data['variations'] as $row) {
            $label = collect($reader->variations)->firstWhere('id', $row['id'])->label;
            [$colour, $size] = explode(' / ', $label);

            $this->assertSame($size.'/'.$colour, $values[0][$row['v'][0]].'/'.$values[1][$row['v'][1]], "variation {$row['id']} ({$label})");
        }
    }

    // 3b. «Изчистване»

    public function test_the_clear_button_is_printed_only_with_pickers_hidden_and_translated(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-sf-clear'));
        $this->assertStringContainsString('<button type="button" class="sf-clear" data-sf-clear hidden>Clear</button>', $html, 'hidden until the script shows it');
        $this->assertMatchesRegularExpression('/data-sf-group="1".*data-sf-clear.*sf-variation-label/s', $html, 'under the groups');

        app()->setLocale('bg');
        $this->assertStringContainsString('hidden>Изчистване</button>', $this->page());
        app()->setLocale('en');

        DB::table('catalog_attribute_definitions')->whereIn('id', [1, 2])->update(['display_type' => 'dropdown']);
        $this->assertStringNotContainsString('data-sf-clear', $this->page(), 'no pickers, no button');
    }

    // 4. the read model

    public function test_the_option_display_carries_the_definition_id_and_the_json_contract_is_unchanged(): void
    {
        $this->app->forgetScopedInstances();
        $page = $this->app->make(CatalogReader::class)->product('shirt');

        $this->assertSame([1, 2], array_column($page->optionDisplay, 'id'));
        $this->assertSame(['Colour', 'Size'], array_column($page->optionDisplay, 'name'));
        $this->assertArrayNotHasKey('optionDisplay', $page->toArray());
        $this->assertStringNotContainsString('"displayType"', json_encode($page->toArray()));
    }
}
