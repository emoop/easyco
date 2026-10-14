<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\InvoiceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Order context, stage C2-A: the checkout script on a page rendered with the invoice rules BG (the tax number labelled "ЕИК / Булстат" and
 * required, the representative labelled "МОЛ") and DE (no tax number required, the registration number required and labelled "USt-IdNr"),
 * driven in the fake DOM (tests/Feature/Storefront/Js/checkout-flow.mjs). The invoice block is HIDDEN until its box is ticked; while hidden
 * nothing in it is required, validated or sent. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutInvoiceFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        $settings = app(SiteSettingsRepository::class);
        $settings->set('site.locale', 'en');
        $settings->set(InvoiceSettings::RULES_KEY, InvoiceSettings::encode(InvoiceSettings::normalizeRows([
            ['country' => 'BG', 'tax_id_label' => 'ЕИК / Булстат', 'tax_id_required_for_company' => true, 'tax_id_pattern' => '^[0-9]{9,13}$', 'representative_label' => 'МОЛ'],
            ['country' => 'DE', 'tax_id_required_for_company' => false, 'registration_number_label' => 'USt-IdNr', 'registration_number_required' => true],
        ])));
        $settings->set(InvoiceSettings::RULES_VERSION_KEY, '1');
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'invoice_hidden_by_default', 'invoice_shown_when_ticked', 'invoice_individual_sends_no_company_fields', 'invoice_rules_follow_the_invoice_country',
            'invoice_country_defaults_to_the_delivery_country', 'invoice_copy_button_copies_the_street_fields', 'invoice_errors_are_shown_under_the_right_fields',
            'invoice_untick_removes_the_requirements_and_the_payload',
        ];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckin').'.html';
        file_put_contents($file, $this->get('/checkout')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/checkout-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame("OK {$scenario}", trim(implode("\n", $output)));
    }
}
