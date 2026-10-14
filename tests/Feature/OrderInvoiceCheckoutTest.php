<?php

namespace Tests\Feature;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\InvoiceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 9.3, 9.5, 9.7): the invoice request on POST /api/checkout. The shape of the block, the
 * switch `invoice.enabled`, the merchant's per-country rules (a configured format is enforced and its label quoted; a format nobody entered is
 * not checked), an individual never carrying a tax id, the invoice email defaulting to the order's, and the rule version recorded.
 */
class OrderInvoiceCheckoutTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    /** @param list<array<string, mixed>> $rows */
    private function rules(array $rows, string $version = '3'): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(InvoiceSettings::RULES_KEY, InvoiceSettings::encode(InvoiceSettings::normalizeRows($rows)));
        $settings->set(InvoiceSettings::RULES_VERSION_KEY, $version);
    }

    /** @return array<string, mixed> a valid company block; $override wins */
    private function company(array $override = []): array
    {
        return array_merge([
            'recipient_type' => 'company', 'legal_name' => 'Acme OOD', 'tax_id' => '123456789', 'country' => 'BG', 'city' => 'Sofia',
            'postal_code' => '1000', 'address_line_1' => '1 Main St',
        ], $override);
    }

    /** @return array<string, mixed> a valid individual block */
    private function person(array $override = []): array
    {
        return array_merge([
            'recipient_type' => 'individual', 'legal_name' => 'Ivan Ivanov', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => '1 Main St',
        ], $override);
    }

    private function place(array $invoice, array $extra = []): TestResponse
    {
        return $this->checkout($this->streetFields('BG'), ['invoice' => $invoice] + $extra);
    }

    private function noOrderWasPlaced(): void
    {
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_invoice_details')->count());
        $this->assertSame(0, DB::table('order_attributions')->count());
        $this->assertSame(0, DB::table('order_events')->count());
    }

    // ---- the switch ---------------------------------------------------------------------------------------------------------------

    public function test_an_invoice_request_is_refused_while_the_shop_offers_none(): void
    {
        app(SiteSettingsRepository::class)->set(InvoiceSettings::ENABLED_KEY, '0');

        $response = $this->place($this->company())->assertStatus(422);

        $response->assertJsonPath('reason', 'invoice_not_supported');
        $this->assertSame(__('checkout.invoice_not_supported'), $response->json('message'));
        $this->noOrderWasPlaced();

        // Whatever the block holds: the refusal does not depend on its contents being valid.
        $this->checkout($this->streetFields('BG'), ['invoice' => ['recipient_type' => 'nonsense']])->assertStatus(422)->assertJsonPath('reason', 'invoice_not_supported');
        // An order that asks for no invoice is unaffected by the switch.
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
    }

    public function test_the_invoice_is_on_by_default_and_a_request_with_none_writes_no_row(): void
    {
        $this->assertTrue(app(InvoiceSettings::class)->enabled(), 'absent = ON');

        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->checkout($this->streetFields('BG'), ['invoice' => []])->assertStatus(201);
        $this->checkout($this->streetFields('BG'), ['invoice' => null])->assertStatus(201);

        $this->assertSame(0, DB::table('order_invoice_details')->count(), 'the row EXISTS only when an invoice was asked for');
        $this->assertSame(3, DB::table('order_attributions')->count());
    }

    // ---- a company and an individual ------------------------------------------------------------------------------------------------

    public function test_a_company_request_is_stored_as_typed_with_the_invoice_email_defaulting_to_the_order_email(): void
    {
        $this->place($this->company(['company_registration_number' => 'R-77', 'representative' => 'Ivan Ivanov', 'address_line_2' => 'Floor 3', 'country' => 'bg']))->assertStatus(201);

        $row = DB::table('order_invoice_details')->first();
        $order = DB::table('orders')->first();

        $this->assertSame($order->id, $row->order_id);
        $this->assertSame('company', $row->recipient_type);
        $this->assertSame('Acme OOD', $row->legal_name);
        $this->assertSame('123456789', $row->tax_id);
        $this->assertSame('R-77', $row->company_registration_number);
        $this->assertSame('Ivan Ivanov', $row->representative);
        $this->assertSame('BG', $row->country, 'normalised like the delivery country');
        $this->assertSame(['Sofia', '1000', '1 Main St', 'Floor 3'], [$row->city, $row->postal_code, $row->address_line_1, $row->address_line_2]);
        $this->assertSame($order->email, $row->invoice_email, 'defaults to the order email');
        $this->assertSame($order->placed_at, $row->requested_at, 'requested_at is the placement instant');
        $this->assertNull($row->country_rule_version, 'no rule for BG: the generic rule accepted it, so no version is named');
    }

    public function test_an_invoice_email_of_its_own_is_kept_as_a_copy(): void
    {
        $this->place($this->company(['email' => 'accountant@example.com']))->assertStatus(201);

        $this->assertSame('accountant@example.com', DB::table('order_invoice_details')->value('invoice_email'));
        $this->assertSame('guest@example.com', DB::table('orders')->value('email'));
    }

    public function test_an_individual_request_is_stored_without_any_company_fields(): void
    {
        $this->place($this->person())->assertStatus(201);

        $row = DB::table('order_invoice_details')->first();
        $this->assertSame('individual', $row->recipient_type);
        $this->assertNull($row->tax_id);
        $this->assertNull($row->company_registration_number);
        $this->assertNull($row->representative);
    }

    public function test_a_tax_id_for_an_individual_is_refused_and_never_dropped_silently(): void
    {
        foreach (['tax_id' => '123456789', 'company_registration_number' => 'R-1', 'representative' => 'Ivan'] as $field => $value) {
            $response = $this->place($this->person([$field => $value]))->assertStatus(422);

            $response->assertJsonPath('reason', 'invoice_details_invalid');
            $response->assertJsonPath('field', 'invoice.'.$field);
            $this->assertNotEmpty($response->json('errors')['invoice.'.$field] ?? null, 'a Laravel-style field error the storefront maps');
        }

        $this->noOrderWasPlaced();
    }

    // ---- the rules of a country ------------------------------------------------------------------------------------------------------

    public function test_with_no_rule_a_company_must_give_a_tax_id_and_nothing_checks_its_format(): void
    {
        $missing = $this->place($this->company(['tax_id' => null]))->assertStatus(422);
        $missing->assertJsonPath('reason', 'invoice_details_invalid');
        $missing->assertJsonPath('field', 'invoice.tax_id');
        $this->assertStringContainsString(__('checkout.invoice_labels.tax_id'), $missing->json('message'), 'the generic label is quoted');
        $this->noOrderWasPlaced();

        // Anything at all is accepted: no format was ever entered, so none is asserted.
        $this->place($this->company(['tax_id' => 'any-thing 12!']))->assertStatus(201);
    }

    public function test_a_configured_pattern_is_enforced_and_the_message_quotes_the_configured_label(): void
    {
        $this->rules([['country' => 'BG', 'tax_id_label' => 'ЕИК / Булстат', 'tax_id_pattern' => '^[0-9]{9,13}$']]);

        $response = $this->place($this->company(['tax_id' => 'ABC']))->assertStatus(422);

        $response->assertJsonPath('reason', 'invoice_details_invalid');
        $response->assertJsonPath('field', 'invoice.tax_id');
        $this->assertStringContainsString('ЕИК / Булстат', $response->json('message'), 'the label the merchant configured, not the column name');
        $this->assertStringNotContainsString('tax_id', $response->json('message'));
        $this->noOrderWasPlaced();

        $this->place($this->company(['tax_id' => '123456789']))->assertStatus(201);
    }

    public function test_the_rule_of_the_invoice_country_applies_not_the_delivery_countrys(): void
    {
        $this->rules([['country' => 'DE', 'tax_id_pattern' => '^DE[0-9]{9}$', 'tax_id_label' => 'USt-IdNr']]);

        // Delivered to BG, invoiced to DE: the DE pattern judges the tax id.
        $this->place($this->company(['country' => 'DE', 'tax_id' => '123456789']))->assertStatus(422)->assertJsonPath('field', 'invoice.tax_id');
        $this->place($this->company(['country' => 'DE', 'tax_id' => 'DE123456789']))->assertStatus(201);
        $this->assertSame('3', DB::table('order_invoice_details')->value('country_rule_version'), 'the version of the rule set that accepted it');
    }

    public function test_a_rule_can_make_the_tax_id_optional_and_the_registration_number_required(): void
    {
        $this->rules([['country' => 'BG', 'tax_id_required_for_company' => false, 'registration_number_label' => 'ДДС номер', 'registration_number_required' => true]]);

        $missing = $this->place($this->company(['tax_id' => null]))->assertStatus(422);
        $missing->assertJsonPath('field', 'invoice.company_registration_number');
        $this->assertStringContainsString('ДДС номер', $missing->json('message'));

        $this->place($this->company(['tax_id' => null, 'company_registration_number' => 'BG123']))->assertStatus(201);
        $this->assertNull(DB::table('order_invoice_details')->value('tax_id'));
    }

    public function test_a_hand_edited_rule_set_that_cannot_be_read_fails_loudly_and_never_guesses(): void
    {
        // CLAUDE.md rule 8, the region policy's posture: the settings page cannot save this (a pattern that does not compile), so it can only
        // have been edited by hand. An invoice request meets it as an error, never as a silently looser rule; an order without an invoice
        // never reads the rules at all.
        app(SiteSettingsRepository::class)->set(InvoiceSettings::RULES_KEY, '{"BG":{"tax_id_pattern":"(unclosed","tax_id_required_for_company":true}}');

        $this->place($this->company(['tax_id' => 'whatever']))->assertStatus(500);
        $this->noOrderWasPlaced();

        $this->checkout($this->streetFields('BG'))->assertStatus(201);
    }

    // ---- the shape of the block (section 9.5) ------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badBlocks(): array
    {
        return [
            'no type' => [['recipient_type' => null], 'invoice.recipient_type'],
            'an unknown type' => [['recipient_type' => 'government'], 'invoice.recipient_type'],
            'no legal name' => [['legal_name' => null], 'invoice.legal_name'],
            'a control character in the name' => [['legal_name' => "Acme\x07"], 'invoice.legal_name'],
            'a bidirectional override in the city' => [['city' => "Sofia\u{202E}"], 'invoice.city'],
            'no country' => [['country' => null], 'invoice.country'],
            'an unknown country' => [['country' => 'ZZ'], 'invoice.country'],
            'no city' => [['city' => null], 'invoice.city'],
            'no postal code' => [['postal_code' => null], 'invoice.postal_code'],
            'a postal code past 32 characters' => [['postal_code' => str_repeat('1', 33)], 'invoice.postal_code'],
            'no address line' => [['address_line_1' => null], 'invoice.address_line_1'],
            'an address line past 255 characters' => [['address_line_1' => str_repeat('a', 256)], 'invoice.address_line_1'],
            'a tax id past 64 characters' => [['tax_id' => str_repeat('1', 65)], 'invoice.tax_id'],
            'an invalid email' => [['email' => 'not-an-email'], 'invoice.email'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBlocks')]
    public function test_a_block_with_a_bad_shape_is_a_422_on_its_field_and_places_nothing(array $override, string $field): void
    {
        $this->place($this->company($override))->assertStatus(422)->assertJsonValidationErrors([$field]);

        $this->noOrderWasPlaced();
    }

    public function test_keys_the_request_does_not_own_are_ignored(): void
    {
        $this->place($this->company(['status' => 'issued', 'invoice_number' => 'F-1', 'requested_at' => '2001-01-01 00:00:00']), [
            'source_type' => 'ai_assistant', 'visitor_id' => '00000000-0000-4000-8000-000000000000', 'ip' => '198.51.100.1', 'consent_mode' => 'off',
            'analytics_consent' => 'granted', 'captured_at' => '2001-01-01 00:00:00', 'device_type' => 'bot', 'user_agent' => 'forged',
        ])->assertStatus(201);

        $invoice = DB::table('order_invoice_details')->first();
        $this->assertNotSame('2001-01-01 00:00:00', $invoice->requested_at);
        $this->assertFalse(property_exists($invoice, 'invoice_number'), 'no invoice number exists anywhere');

        $row = DB::table('order_attributions')->first();
        $this->assertSame('unknown', $row->source_type, 'source_type is never client-supplied');
        $this->assertNull($row->visitor_id);
        $this->assertNotSame('198.51.100.1', $row->ip);
        $this->assertSame('strict', $row->consent_mode);
        $this->assertSame('unknown', $row->analytics_consent);
        $this->assertNotSame('2001-01-01 00:00:00', $row->captured_at);
        $this->assertNotSame('forged', $row->user_agent);
        $this->assertNotSame('bot', $row->device_type);
    }

    public function test_the_refusal_sentences_exist_in_both_languages_and_quote_the_label(): void
    {
        foreach (['en', 'bg'] as $locale) {
            foreach (['invoice_not_supported', 'invoice_details_invalid', 'invoice_field_required', 'invoice_field_format', 'invoice_individual_field'] as $key) {
                $this->assertNotSame('checkout.'.$key, __('checkout.'.$key, [], $locale), "{$locale} {$key}");
            }

            foreach (['invoice_field_required', 'invoice_field_format', 'invoice_individual_field'] as $key) {
                $this->assertStringContainsString('МОЙ ЕТИКЕТ', __('checkout.'.$key, ['label' => 'МОЙ ЕТИКЕТ'], $locale));
            }
        }
    }
}
