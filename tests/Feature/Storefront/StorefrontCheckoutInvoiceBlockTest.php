<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\InvoiceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Order context, stage C2-A, the checkout page's invoice block as the SERVER renders it (the script's behaviour is the fake-DOM flow test):
 * an unticked «Искам фактура» box, the whole block `hidden` and carrying no requirement, no markup built from the merchant's rules, none of it
 * rendered when the shop offers no invoice, and no new query for a page shell.
 */
class StorefrontCheckoutInvoiceBlockTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('site.locale', 'en');
    }

    private function block(string $html): string
    {
        $start = strpos($html, 'data-sf-invoice-block');
        $this->assertNotFalse($start, 'the invoice block is on the page');

        return substr($html, $start, strpos($html, '</fieldset>', $start) - $start);
    }

    public function test_the_box_is_never_ticked_and_the_whole_block_is_hidden_and_requires_nothing(): void
    {
        $html = $this->get('/checkout')->assertOk()->getContent();
        $block = $this->block($html);

        $this->assertMatchesRegularExpression('/<input id="sf-invoice-requested" name="invoice_requested" type="checkbox" value="1"[^>]*data-sf-invoice-toggle>/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="invoice_requested"[^>]*checked/', $html, 'never ticked in advance');
        $this->assertMatchesRegularExpression('/<fieldset class="sf-fieldset sf-invoice-block" id="sf-invoice-block" data-sf-invoice-block hidden>/', $html, 'hidden by default');
        $this->assertStringNotContainsString('required', $block, 'while hidden no field is required: not by attribute, not by aria, not by marker');
        $this->assertStringNotContainsString('sf-required', $block);
        $this->assertSame(1, preg_match_all('/<input[^>]*name="invoice_recipient_type"[^>]*checked/', $block), 'a company is the chosen type: a choice, not a consent');
        $this->assertStringContainsString(__('storefront.checkout_invoice_toggle'), $html);

        // No checkbox anywhere on the page is pre-ticked (D8).
        $this->assertSame(0, preg_match_all('/<input[^>]*type="checkbox"[^>]*\schecked/', $html));
    }

    public function test_the_block_is_in_its_place_after_the_note_and_before_the_payment(): void
    {
        $html = $this->get('/checkout')->assertOk()->getContent();

        $note = strpos($html, 'id="sf-note"');
        $toggle = strpos($html, 'data-sf-invoice-toggle');
        $payment = strpos($html, 'name="payment_method"');

        $this->assertLessThan($toggle, $note);
        $this->assertLessThan($payment, $toggle);
    }

    public function test_the_block_holds_every_field_the_design_asks_for_and_an_error_holder_for_each(): void
    {
        $block = $this->block($this->get('/checkout')->assertOk()->getContent());

        foreach (['invoice_legal_name', 'invoice_tax_id', 'invoice_registration_number', 'invoice_representative', 'invoice_country', 'invoice_city', 'invoice_postal_code', 'invoice_address_line_1', 'invoice_address_line_2', 'invoice_email'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $block, "{$name} is on the page");
            $this->assertStringContainsString('data-sf-error-for="'.$name.'"', $block, "{$name} has its own error holder");
        }

        $this->assertStringContainsString('data-sf-invoice-copy', $block, 'the copy-from-delivery button');
        $this->assertMatchesRegularExpression('/data-sf-invoice-copy hidden>/', $block, 'and it starts hidden');
        $this->assertSame(2, preg_match_all('/name="invoice_recipient_type" value="(company|individual)"/', $block));
        $this->assertStringContainsString(__('checkout.invoice_labels.tax_id'), $block, 'the generic tax-id label when no rule exists');
    }

    public function test_the_rules_reach_the_page_as_labels_and_requirements_only_and_as_escaped_data(): void
    {
        app(SiteSettingsRepository::class)->set(InvoiceSettings::RULES_KEY, InvoiceSettings::encode(InvoiceSettings::normalizeRows([
            ['country' => 'BG', 'tax_id_label' => 'ЕИК "Булстат" <b>', 'tax_id_pattern' => '^[0-9]{9,13}$', 'representative_label' => 'МОЛ'],
        ])));

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringNotContainsString('0-9', $html, 'no format is ever sent to the browser');
        $this->assertStringNotContainsString('<b>', $html, 'a merchant label is data, never markup');
        preg_match('/data-invoice-rules="([^"]*)"/', $html, $match);
        $rules = json_decode(html_entity_decode($match[1]), true);
        $this->assertSame('ЕИК "Булстат" <b>', $rules['BG']['tax_id_label']);
        $this->assertTrue($rules['BG']['tax_id_required_for_company']);
        $this->assertArrayNotHasKey('tax_id_pattern', $rules['BG']);
    }

    public function test_nothing_is_rendered_when_the_shop_offers_no_invoice(): void
    {
        app(SiteSettingsRepository::class)->set(InvoiceSettings::ENABLED_KEY, '0');

        $html = $this->get('/checkout')->assertOk()->getContent();

        foreach (['invoice_requested', 'data-sf-invoice', 'sf-invoice-block', 'invoice_legal_name', __('storefront.checkout_invoice_toggle')] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_the_bulgarian_page_says_it_in_bulgarian(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('Искам фактура', $html);
        $this->assertStringContainsString('Като адреса за доставка', $html);
        $this->assertStringContainsString('Фирма', $html);
        $this->assertStringContainsString('Физическо лице', $html);
        $this->assertStringContainsString('ЕИК / данъчен номер', $html, 'the default tax-id label');
        $this->assertStringContainsString('Регистрационен / ДДС номер', $html);
        $this->assertStringContainsString('Представител', $html);
    }

    public function test_the_page_shell_pays_no_query_of_its_own_for_the_invoice_or_privacy_settings(): void
    {
        $this->get('/checkout')->assertOk(); // warm what is one-off (the country list, ...)
        $this->app->forgetScopedInstances();
        $reads = [];

        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'site_settings')) {
                $reads[] = $query->bindings;
            }
        });
        $this->get('/checkout')->assertOk();

        $withInvoiceKeys = array_filter($reads, fn (array $bindings): bool => in_array(InvoiceSettings::ENABLED_KEY, $bindings, true));
        $this->assertCount(1, $withInvoiceKeys, 'the invoice keys are read once, by the ONE getMany of the checkout form');

        $one = array_values($withInvoiceKeys)[0];
        foreach ([InvoiceSettings::RULES_KEY, InvoiceSettings::RULES_VERSION_KEY, 'privacy.consent_mode', 'privacy.user_agent_max_length', 'privacy.ip_full_retention_days', 'checkout.terms_url'] as $key) {
            $this->assertContains($key, $one, "{$key} rides the same query");
        }
    }
}
