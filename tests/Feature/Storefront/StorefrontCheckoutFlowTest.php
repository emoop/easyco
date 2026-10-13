<?php

namespace Tests\Feature\Storefront;

use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * C1 / C1b: the checkout script's all-kinds delivery flow, run for real. The rendered /checkout page is parsed by a tiny fake
 * DOM (tests/Feature/Storefront/Js/checkout-flow.mjs) and the REAL script runs on it against a scripted `all` API with two couriers
 * (one with a single kind), one courier-less method, a preview and a final answer. The page is rendered with the region rule
 * "optional, DE required, FR hidden". No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        app(SiteSettingsRepository::class)->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"DE":"required","FR":"hidden"}');
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'structure', 'preview_before_place', 'one_request_after_place', 'courier_then_kind_dropdown', 'switch_courier_and_kind_keeps_data',
            'rule_a_price_changed', 'rule_a_requote_shows_yet_another_price', 'quote_expired_requote', 'requote_keeps_choice', 'requote_clears_vanished_choice',
            'unavailable_kinds_are_not_offered', 'courierless_method', 'region_modes', 'note_counter', 'summary_toggle',
            'promo_box_in_summary', 'promo_apply_requotes_and_keeps_choice', 'promo_changes_delivery_price_rule_a', 'promo_remove_requotes',
            'promo_invalid_and_error', 'promo_does_not_submit_the_form', 'promo_busy_locks_place_order',
            'required_markers', 'promo_compact_box', 'promo_cart_box_unchanged',
        ];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $this->runScenario($scenario);
    }

    /**
     * The JS region rule and CheckoutRegionPolicy::modeFor agree: a table of policies and countries (case, spaces, unknown codes, a
     * missing exception list, codes that are property names of a plain object) whose expected mode is computed by the REAL PHP reader,
     * then fed to the script's exported rule.
     */
    public function test_the_js_region_rule_and_the_php_rule_agree_on_a_table_of_cases(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $policies = [
            ['default' => null, 'countries' => null],
            ['default' => 'optional', 'countries' => '{}'],
            ['default' => 'required', 'countries' => '{"DE":"optional"}'],
            ['default' => 'hidden', 'countries' => '{"BG":"required","FR":"optional"}'],
            ['default' => 'optional', 'countries' => '{"DE":"required","FR":"hidden","US":"required"}'],
        ];
        $countries = ['BG', 'bg', ' bg ', 'De', 'de', 'FR', 'us', 'XX', '', 'B', 'GBR', 'constructor', '__proto__', 'toString'];
        $cases = [];

        foreach ($policies as $stored) {
            foreach ([CheckoutRegionPolicy::DEFAULT_KEY => $stored['default'], CheckoutRegionPolicy::COUNTRIES_KEY => $stored['countries']] as $key => $value) {
                $value === null ? $settings->forget($key) : $settings->set($key, $value);
            }

            $reader = new CheckoutRegionPolicy($settings);

            foreach ($countries as $country) {
                $cases[] = ['policy' => $reader->all(), 'country' => $country, 'expected' => $reader->modeFor($country)->value];
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'regcases');
        file_put_contents($file, json_encode($cases, JSON_THROW_ON_ERROR));

        try {
            $this->runScenario('region_agreement', $file);
        } finally {
            @unlink($file);
        }
    }

    private function runScenario(string $scenario, ?string $extra = null): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckout').'.html';
        file_put_contents($file, $this->get('/checkout')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/checkout-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).($extra === null ? '' : ' '.escapeshellarg($extra)).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame("OK {$scenario}", trim(implode("\n", $output)));
    }
}
