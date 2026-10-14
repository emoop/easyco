<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CheckoutSettings;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\InvoiceSettings;
use App\Settings\OrderContextSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 9.3, 7.2): the «Фактура» and «Поверителност» sections of Settings > Checkout, and the
 * reader (InvoiceSettings) whose validation the page cannot get around. No country is pre-filled; the rule version is bumped by the page
 * itself, never typed; every key rides the checkout form's ONE getMany, so a page shell costs no query for them.
 */
class InvoiceSettingsTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale('en');

        return Livewire::test(CheckoutSettings::class);
    }

    /** @return array<string, mixed> */
    private function rule(array $override = []): array
    {
        return array_merge([
            'country' => 'BG', 'tax_id_label' => 'ЕИК / Булстат', 'tax_id_required_for_company' => true, 'tax_id_pattern' => '^[0-9]{9,13}$',
            'registration_number_label' => 'ДДС номер', 'registration_number_required' => false, 'representative_label' => 'МОЛ',
        ], $override);
    }

    // ---- the page -------------------------------------------------------------------------------------------------------------------------

    public function test_nothing_is_prefilled_and_the_invoice_is_on_by_default(): void
    {
        $this->page()->assertFormSet(['invoice_enabled' => true, 'invoice_rules' => [], 'ip_full_retention_days' => 30]);
    }

    public function test_saving_stores_the_rules_and_bumps_the_version_by_itself(): void
    {
        $this->page()->fillForm(['invoice_rules' => [$this->rule()]])->call('save')->assertHasNoFormErrors();

        $stored = json_decode($this->settings()->get(InvoiceSettings::RULES_KEY), true);
        $this->assertSame([
            'BG' => [
                'tax_id_label' => 'ЕИК / Булстат', 'tax_id_pattern' => '^[0-9]{9,13}$', 'registration_number_label' => 'ДДС номер', 'representative_label' => 'МОЛ',
                'tax_id_required_for_company' => true, 'registration_number_required' => false,
            ],
        ], $stored);
        $this->assertSame('1', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY), 'the first rule set is version 1');

        // The reader gives back what was stored.
        $rule = app(InvoiceSettings::class)->ruleFor('bg');
        $this->assertSame('ЕИК / Булстат', $rule->taxIdLabel);
        $this->assertSame('^[0-9]{9,13}$', $rule->taxIdPattern);
        $this->assertSame('МОЛ', $rule->representativeLabel);
    }

    public function test_the_version_moves_when_the_rules_change_and_not_when_the_page_is_saved_for_something_else(): void
    {
        $this->page()->fillForm(['invoice_rules' => [$this->rule()]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY));

        // Saved again with the same rules and another, unrelated, setting: the version stays.
        $this->page()->fillForm(['newsletter_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY));

        $this->page()->fillForm(['invoice_rules' => [$this->rule(['tax_id_pattern' => '^[0-9]{9}$'])]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY), 'a changed rule is a new version');

        // Emptying the list forgets the rules but the counter keeps going: a later rule set can never be mistaken for an older one.
        $this->page()->fillForm(['invoice_rules' => []])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->settings()->get(InvoiceSettings::RULES_KEY));
        $this->assertSame('3', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY));

        $this->page()->fillForm(['invoice_rules' => [$this->rule()]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('4', $this->settings()->get(InvoiceSettings::RULES_VERSION_KEY));
    }

    public function test_a_pattern_that_is_not_a_regular_expression_is_refused_and_nothing_is_stored(): void
    {
        $this->page()->fillForm(['invoice_rules' => [$this->rule(['tax_id_pattern' => '(unclosed'])]])->call('save')->assertHasFormErrors();

        $this->assertNull($this->settings()->get(InvoiceSettings::RULES_KEY));
        $this->assertNull($this->settings()->get(InvoiceSettings::RULES_VERSION_KEY));
    }

    public function test_a_country_listed_twice_or_unknown_is_refused(): void
    {
        $this->page()->fillForm(['invoice_rules' => [$this->rule(), $this->rule(['tax_id_label' => 'Other'])]])->call('save')->assertHasFormErrors();
        $this->page()->fillForm(['invoice_rules' => [$this->rule(['country' => 'ZZ'])]])->call('save')->assertHasFormErrors();

        $this->assertNull($this->settings()->get(InvoiceSettings::RULES_KEY));
    }

    public function test_turning_the_invoice_off_stores_zero_and_back_on_forgets_it(): void
    {
        $this->page()->fillForm(['invoice_enabled' => false])->call('save')->assertHasNoFormErrors();
        $this->assertSame('0', $this->settings()->get(InvoiceSettings::ENABLED_KEY));
        $this->assertFalse(app(InvoiceSettings::class)->enabled());

        $this->page()->fillForm(['invoice_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->settings()->get(InvoiceSettings::ENABLED_KEY), 'the default is not stored');
        $this->assertTrue(app(InvoiceSettings::class)->enabled());
    }

    public function test_the_ip_retention_window_is_a_whole_number_of_days_and_the_default_is_not_stored(): void
    {
        $this->page()->fillForm(['ip_full_retention_days' => 7])->call('save')->assertHasNoFormErrors();
        $this->assertSame('7', $this->settings()->get(OrderContextSettings::IP_RETENTION_DAYS_KEY));
        $this->assertSame(7, app(OrderContextSettings::class)->ipFullRetentionDays());

        $this->page()->fillForm(['ip_full_retention_days' => 0])->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, app(OrderContextSettings::class)->ipFullRetentionDays(), 'zero = never keep the full address');

        $this->page()->fillForm(['ip_full_retention_days' => 30])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->settings()->get(OrderContextSettings::IP_RETENTION_DAYS_KEY));

        foreach ([-1, 3651, 'abc'] as $bad) {
            $this->page()->fillForm(['ip_full_retention_days' => $bad])->call('save')->assertHasFormErrors(['ip_full_retention_days']);
        }
    }

    public function test_the_page_shows_the_hint_to_confirm_the_format_with_an_accountant(): void
    {
        $html = $this->page()->html();

        $this->assertStringContainsString(__('settings.checkout_page.invoice_help'), $html);
        $this->assertStringContainsString('Confirm the format with your accountant', __('settings.checkout_page.invoice_pattern_hint'));
        $this->assertStringContainsString('Потвърдете формата със счетоводител', __('settings.checkout_page.invoice_pattern_hint', [], 'bg'));
    }

    // ---- the reader -------------------------------------------------------------------------------------------------------------------------

    public function test_the_defaults_when_nothing_is_stored(): void
    {
        $invoice = app(InvoiceSettings::class);

        $this->assertTrue($invoice->enabled());
        $this->assertNull($invoice->version());
        $this->assertSame([], $invoice->rules());
        $this->assertFalse($invoice->hasRuleFor('BG'));

        $generic = $invoice->ruleFor('BG');
        $this->assertTrue($generic->taxIdRequiredForCompany, 'the generic rule requires a company tax id');
        $this->assertNull($generic->taxIdPattern, 'and checks no format');
        $this->assertFalse($generic->registrationNumberRequired);
        $this->assertTrue($generic->taxIdMatches('anything at all'));
    }

    /** @return array<string, array{0: string}> */
    public static function malformed(): array
    {
        return [
            'not JSON' => ['{not json'],
            'a list' => ['["BG"]'],
            'a scalar' => ['"BG"'],
            'a rule that is not an object' => ['{"BG":"x"}'],
            'an unknown country' => ['{"ZZ":{}}'],
            'a label that is not text' => ['{"BG":{"tax_id_label":5}}'],
            'a label past 64 characters' => ['{"BG":{"tax_id_label":"'.'x'.str_repeat('y', 70).'"}}'],
            'a pattern that does not compile' => ['{"BG":{"tax_id_pattern":"(unclosed"}}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_stored_rule_set_throws_never_a_silent_default(string $stored): void
    {
        $this->settings()->set(InvoiceSettings::RULES_KEY, $stored);

        $this->expectException(\InvalidArgumentException::class);
        app(InvoiceSettings::class)->rules();
    }

    public function test_the_checkout_page_data_carries_labels_and_requirements_but_never_a_format(): void
    {
        $this->settings()->set(InvoiceSettings::RULES_KEY, InvoiceSettings::encode(InvoiceSettings::normalizeRows([$this->rule()])));

        $page = app(InvoiceSettings::class)->forPage();

        $this->assertSame(['BG'], array_keys($page));
        $this->assertSame('ЕИК / Булстат', $page['BG']['tax_id_label']);
        $this->assertTrue($page['BG']['tax_id_required_for_company']);
        $this->assertArrayNotHasKey('tax_id_pattern', $page['BG'], 'the server is the only judge of a format');
        $this->assertStringNotContainsString('0-9', json_encode($page));
    }

    public function test_every_key_rides_the_checkout_forms_one_query(): void
    {
        $this->settings()->set(InvoiceSettings::ENABLED_KEY, '0');
        $this->app->forgetScopedInstances();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'site_settings')) {
                $queries[] = $query->sql;
            }
        });

        $form = app(CheckoutFormSettings::class);
        $form->termsEnabled();
        app(InvoiceSettings::class)->enabled();
        app(InvoiceSettings::class)->rules();
        app(InvoiceSettings::class)->version();
        app(OrderContextSettings::class)->consentMode();
        app(OrderContextSettings::class)->userAgentMaxLength();
        app(OrderContextSettings::class)->ipFullRetentionDays();

        $this->assertLessThanOrEqual(1, count($queries), 'one getMany, however many of the invoice and privacy values are asked for');
    }

    public function test_the_privacy_settings_fall_back_to_the_conservative_defaults(): void
    {
        $privacy = app(OrderContextSettings::class);

        $this->assertSame('strict', $privacy->consentMode()->value);
        $this->assertSame(255, $privacy->userAgentMaxLength());
        $this->assertSame(30, $privacy->ipFullRetentionDays());

        foreach (['privacy.consent_mode' => 'weird', OrderContextSettings::USER_AGENT_MAX_LENGTH_KEY => '0', OrderContextSettings::IP_RETENTION_DAYS_KEY => '-5'] as $key => $value) {
            $this->settings()->set($key, $value);
        }

        $this->assertSame('strict', $privacy->consentMode()->value, 'an unreadable regime is the strict one');
        $this->assertSame(255, $privacy->userAgentMaxLength());
        $this->assertSame(30, $privacy->ipFullRetentionDays());
    }
}
