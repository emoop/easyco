<?php

namespace Tests\Feature;

use App\Settings\CheckoutFormSettings;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B (checkout-form-v2-design.md 6.6): the ONE reader of the form's settings. Defaults, the terms rule (on AND a
 * safe link), the link test, and that ALL its keys (the region rules included) cost ONE query.
 */
class CheckoutFormSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function reader(): CheckoutFormSettings
    {
        // A fresh repository (an empty memo): the real one is request-scoped.
        $this->app->forgetInstance(SiteSettingsRepository::class);

        return new CheckoutFormSettings(app(SiteSettingsRepository::class));
    }

    public function test_every_default_when_nothing_is_stored(): void
    {
        $reader = $this->reader();

        $this->assertFalse($reader->callBeforeShippingEnabled(), 'absent = off');
        $this->assertFalse($reader->newsletterEnabled(), 'absent = off');
        $this->assertTrue($reader->termsEnabled(), 'absent = ON (the EU default)');
        $this->assertNull($reader->termsUrl());
        $this->assertNull($reader->privacyUrl());
        $this->assertNull($reader->termsVersion());
        $this->assertSame('unversioned', $reader->currentTermsVersion());
        $this->assertFalse($reader->termsRequired(), 'on, but no page to read the terms at: nothing is asked');
    }

    public function test_the_optional_boxes_are_on_only_for_one(): void
    {
        foreach ([[null, false], ['1', true], ['0', false], ['yes', false], ['', false]] as [$stored, $expected]) {
            $stored === null ? $this->settings()->forget(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY) : $this->settings()->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, $stored);
            $stored === null ? $this->settings()->forget(CheckoutFormSettings::NEWSLETTER_KEY) : $this->settings()->set(CheckoutFormSettings::NEWSLETTER_KEY, $stored);

            $reader = $this->reader();

            $this->assertSame($expected, $reader->callBeforeShippingEnabled(), (string) $stored);
            $this->assertSame($expected, $reader->newsletterEnabled(), (string) $stored);
        }
    }

    public function test_the_terms_are_on_unless_zero_and_asked_only_with_a_safe_link(): void
    {
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/terms');
        $this->assertTrue($this->reader()->termsRequired());

        $this->settings()->set(CheckoutFormSettings::TERMS_ENABLED_KEY, '0');
        $this->assertFalse($this->reader()->termsEnabled());
        $this->assertFalse($this->reader()->termsRequired(), 'switched off');

        $this->settings()->forget(CheckoutFormSettings::TERMS_ENABLED_KEY);
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, 'javascript:alert(1)');
        $this->assertNull($this->reader()->safeTermsUrl(), 'a hand-edited row with a script link is not a link');
        $this->assertFalse($this->reader()->termsRequired(), 'a sentence asking to accept terms nobody can open is worse than none');

        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');
        $this->assertSame('/terms', $this->reader()->safeTermsUrl());
        $this->assertTrue($this->reader()->termsRequired());
    }

    public function test_the_links_are_http_https_or_a_same_site_path_and_nothing_else(): void
    {
        foreach (['https://shop.test/terms', 'http://shop.test/a?b=c#d', '/terms', '/pages/privacy-policy'] as $ok) {
            $this->assertTrue(CheckoutFormSettings::isSafeLink($ok), $ok);
        }

        foreach ([
            '', ' ', 'terms', 'javascript:alert(1)', 'data:text/html,x', 'ftp://shop.test/x', '//evil.test/x', '/\\evil.test', 'https:///nohost',
            "https://shop.test/a b", "/terms\n", "https://shop.test/\x00", str_repeat('a', 2049), '/'.str_repeat('a', 2048),
        ] as $bad) {
            $this->assertFalse(CheckoutFormSettings::isSafeLink($bad), json_encode($bad));
        }

        $this->assertTrue(CheckoutFormSettings::isSafeLink('/'.str_repeat('a', 2047)), '2048 characters is the ceiling');
    }

    public function test_all_the_keys_including_the_region_rules_are_read_with_one_query(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::DEFAULT_KEY, 'required');
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');
        $reader = $this->reader();
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'site_settings')) {
                $queries++;
            }
        });

        $reader->callBeforeShippingEnabled();
        $reader->newsletterEnabled();
        $reader->termsRequired();
        $reader->privacyUrl();
        $reader->currentTermsVersion();
        // The region policy reads THROUGH this reader: still the same single query.
        $policy = new CheckoutRegionPolicy(app(SiteSettingsRepository::class));
        $this->assertSame('required', $policy->defaultMode()->value);
        $this->assertSame([], $policy->overrides());

        $this->assertSame(1, $queries, 'one getMany for the eight keys, then the repository memo');
    }

    public function test_the_region_policy_keeps_its_behaviour_when_read_through_the_reader(): void
    {
        $this->settings()->set(CheckoutRegionPolicy::DEFAULT_KEY, 'hidden');
        $this->settings()->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"DE":"required"}');
        $this->reader();
        $policy = new CheckoutRegionPolicy(app(SiteSettingsRepository::class));

        $this->assertSame('required', $policy->modeFor('de')->value);
        $this->assertSame('hidden', $policy->modeFor('BG')->value);
    }
}
