<?php

namespace Tests\Feature\Storefront;

use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use EasyCo\Extensibility\Hook;
use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B (checkout-form-v2-design.md 5, 6, 8.3): GET /checkout as the merchant's settings and the payment registry
 * shape it. No checkbox is ever ticked; the field order; the two sentences with their escaped links; the country select; the payment
 * cards (nothing from a descriptor is HTML); the express slot, empty and filled.
 */
class StorefrontCheckoutChoicesPageTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
    }

    private function set(string $key, ?string $value): void
    {
        $value === null ? app(SiteSettingsRepository::class)->forget($key) : app(SiteSettingsRepository::class)->set($key, $value);
    }

    private function everythingOn(): void
    {
        $this->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, '1');
        $this->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/terms');
        $this->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-10-14');
        $this->set(CheckoutFormSettings::PRIVACY_URL_KEY, '/privacy');
    }

    private function html(): string
    {
        return $this->get('/checkout')->assertOk()->getContent();
    }

    // ---- the checkboxes ---------------------------------------------------------------------------------------------------------------

    public function test_no_checkbox_on_the_page_is_ever_ticked(): void
    {
        $this->everythingOn();
        $html = $this->html();

        preg_match_all('/<input\b[^>]*\btype="checkbox"[^>]*>/', $html, $boxes);

        $this->assertCount(4, $boxes[0], 'the newsletter, the call, the terms and (C2-A, on by default) the invoice boxes');

        foreach ($boxes[0] as $box) {
            $this->assertDoesNotMatchRegularExpression('/\bchecked\b/', $box, 'a ticked box is not consent: '.$box);
        }

        $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*\btype="checkbox"[^>]*\b(checked|value="on")\b/', $html);
    }

    public function test_the_boxes_the_merchant_did_not_turn_on_are_not_rendered(): void
    {
        $html = $this->html();

        // Changed on purpose (order-context stage C2-A): the invoice box is on by default (`invoice.enabled` absent = ON), so it is the ONE checkbox left.
        $this->assertSame(1, preg_match_all('/type="checkbox"/', $html));
        $this->assertStringContainsString('name="invoice_requested"', $html);
        $this->assertStringNotContainsString('name="marketing_consent"', $html);
        $this->assertStringNotContainsString('name="call_before_shipping"', $html);
        $this->assertStringNotContainsString('name="terms_accepted"', $html, 'on by default, but there is no page to read the terms at: nothing is asked');
        $this->assertStringNotContainsString('sf-privacy-notice', $html);
    }

    public function test_the_order_of_the_fields_is_the_designed_one(): void
    {
        $this->everythingOn();
        $html = $this->html();

        $positions = array_map(fn (string $needle): int => (int) strpos($html, $needle), [
            'id="sf-email"', 'id="sf-first-name"', 'id="sf-newsletter"', 'id="sf-phone"', 'id="sf-call-before"', 'id="sf-country"', 'id="sf-note"',
            'class="sf-pay-cards"', 'class="sf-privacy-notice"', 'id="sf-terms"', 'data-sf-place-order',
        ]);

        foreach ($positions as $at) {
            $this->assertGreaterThan(0, $at);
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'email, names, newsletter, phone, call, delivery, note, payment, privacy notice, terms, button');
    }

    public function test_the_terms_box_stands_before_its_sentence_is_required_and_carries_the_version_the_page_shows(): void
    {
        $this->everythingOn();
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<input id="sf-terms" name="terms_accepted" type="checkbox" value="1" required aria-required="true" data-sf-describedby="sf-err-terms_accepted" data-sf-terms-version="2026-10-14">\s*<label for="sf-terms">I have read and agree to the website&#039;s <a href="https:\/\/shop\.test\/terms" target="_blank" rel="noopener">terms and conditions<\/a> <span class="sf-required" aria-hidden="true">\*<\/span><\/label>/', $html);
        $this->assertStringContainsString('id="sf-err-terms_accepted" data-sf-error-for="terms_accepted" hidden', $html);
    }

    public function test_the_two_sentences_are_translated_and_the_newsletter_label_is_the_recorded_sentence(): void
    {
        $this->everythingOn();
        $this->set('site.locale', 'bg');
        $html = $this->html();

        $this->assertStringContainsString('Прочетох и се съгласявам с <a href="https://shop.test/terms" target="_blank" rel="noopener">правилата и условията</a> на сайта', $html);
        $this->assertStringContainsString('за други цели, описани в нашата <a href="/privacy" target="_blank" rel="noopener">политика за поверителност</a>.', $html);
        $this->assertStringContainsString('<label for="sf-newsletter">'.__('checkout.marketing_consent_text').'</label>', $html);
        $this->assertStringContainsString('Абонирайте ме за бюлетина', $html);
        $this->assertStringContainsString('<label for="sf-call-before">Позвънете ми преди изпращане</label>', $html);
    }

    public function test_the_links_are_escaped_and_a_link_that_is_not_safe_is_never_rendered(): void
    {
        $this->everythingOn();
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/t?a=1&b="x"<i>');
        $html = $this->html();

        $this->assertStringContainsString('href="https://shop.test/t?a=1&amp;b=&quot;x&quot;&lt;i&gt;" target="_blank" rel="noopener"', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertSame(2, substr_count($html, 'rel="noopener"'), 'both links open a new tab without opener');

        $this->set(CheckoutFormSettings::TERMS_URL_KEY, 'javascript:alert(1)');
        $this->set(CheckoutFormSettings::PRIVACY_URL_KEY, 'data:text/html,<script>alert(1)</script>');
        $html = $this->html();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('name="terms_accepted"', $html, 'no usable link: no box');
        $this->assertStringNotContainsString('sf-privacy-notice', $html);
    }

    public function test_the_terms_box_is_hidden_when_switched_off_or_without_a_link_and_the_notice_without_its_link(): void
    {
        $this->everythingOn();
        $this->set(CheckoutFormSettings::TERMS_ENABLED_KEY, '0');
        $this->assertStringNotContainsString('name="terms_accepted"', $this->html());
        $this->assertStringContainsString('class="sf-privacy-notice"', $this->html(), 'the notice does not depend on the terms');

        $this->set(CheckoutFormSettings::TERMS_ENABLED_KEY, null);
        $this->set(CheckoutFormSettings::TERMS_URL_KEY, null);
        $this->assertStringNotContainsString('name="terms_accepted"', $this->html());

        $this->set(CheckoutFormSettings::PRIVACY_URL_KEY, null);
        $this->assertStringNotContainsString('sf-privacy-notice', $this->html());
    }

    public function test_the_order_button_label_is_a_lang_key(): void
    {
        $this->assertStringContainsString('data-sf-place-order>Place order</button>', $this->html());

        $this->set('site.locale', 'bg');
        $this->assertStringContainsString('data-sf-place-order>Поръчване</button>', $this->html());
    }

    // ---- the country select -----------------------------------------------------------------------------------------------------------

    public function test_the_country_is_a_native_select_with_every_country_in_the_page_locale_sorted_by_name(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<select id="sf-country" name="country" autocomplete="country" required aria-required="true">/', $html);
        preg_match('/<select id="sf-country".*?<\/select>/s', $html, $select);
        preg_match_all('/<option value="([A-Z]{2})"[^>]*>([^<]*)<\/option>/', $select[0], $options, PREG_SET_ORDER);

        $expected = CountryNames::forLocale('en');
        $this->assertSame(array_keys($expected), array_map(fn ($o) => $o[1], $options), 'every country, in the locale\'s own order');
        $this->assertSame(array_values(array_map(fn ($n) => e($n), $expected)), array_map(fn ($o) => $o[2], $options));
        $this->assertGreaterThan(200, count($options));

        $this->set('site.locale', 'bg');
        preg_match('/<select id="sf-country".*?<\/select>/s', $this->html(), $bg);
        $this->assertStringContainsString('<option value="BG" selected>България</option>', $bg[0]);
        $this->assertStringContainsString('<option value="DE">Германия</option>', $bg[0]);
    }

    public function test_the_stores_country_is_preselected_and_it_is_the_only_one(): void
    {
        foreach (['BG', 'DE', 'GR'] as $country) {
            $this->set('site.country', $country);
            $html = $this->html();

            $this->assertStringContainsString('<option value="'.$country.'" selected>', $html);
            preg_match('/<select id="sf-country".*?<\/select>/s', $html, $select);
            $this->assertSame(1, substr_count($select[0], ' selected>'), $country);
        }
    }

    // ---- the payment cards, the extension and the express slot -----------------------------------------------------------------

    public function test_the_built_in_methods_are_cards_with_a_description_and_the_first_is_preselected(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<label class="sf-pay-card">\s*<input type="radio" name="payment_method" value="cash_on_delivery" checked data-place-order-label="" data-blockable="1">\s*<span>Cash on delivery<\/span>\s*<small class="sf-pay-card-description">Pay in cash when the parcel arrives\.<\/small>/', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="payment_method" value="bank_transfer" data-place-order-label="">\s*<span>Bank transfer<\/span>/', $html);
        $this->assertSame(1, preg_match_all('/name="payment_method" value="[a-z_]+" checked/', $html), 'exactly the first method, a choice and not a consent');
    }

    private function extension(string $code, array $override = []): void
    {
        app(PaymentMethodRegistry::class)->register(new PaymentMethodDescriptor(...array_merge([
            'code' => $code,
            'label' => fn (string $locale): string => 'Card <b>pay</b> '.$locale,
            'description' => fn (string $locale): ?string => '<script>alert(1)</script> & more',
            'icon' => 'https://cdn.test/card.svg',
            'placeOrderLabel' => fn (string $locale): ?string => 'Place order and pay by card',
            'sortOrder' => 5,
        ], $override)));
        $this->app->bind('payment.adapter.'.$code, CashOnDeliveryPaymentMethodAdapter::class);
    }

    public function test_a_method_an_extension_registers_appears_with_its_logo_and_label_and_nothing_of_it_is_html(): void
    {
        $this->extension('test_card');
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<input type="radio" name="payment_method" value="test_card" checked data-place-order-label="Place order and pay by card">\s*<img class="sf-pay-card-logo" src="https:\/\/cdn\.test\/card\.svg" alt="Card &lt;b&gt;pay&lt;\/b&gt; en" loading="lazy" decoding="async">/', $html);
        $this->assertStringContainsString('<span>Card &lt;b&gt;pay&lt;/b&gt; en</span>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $html);
        $this->assertStringNotContainsString('<b>pay</b>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertLessThan(strpos($html, 'value="cash_on_delivery"'), strpos($html, 'value="test_card"'), 'sort order 5 comes before the built-ins');
    }

    public function test_a_logo_that_is_not_a_safe_link_is_dropped_and_a_method_that_is_not_available_is_not_offered(): void
    {
        $this->extension('script_logo', ['icon' => 'javascript:alert(1)']);
        $this->extension('not_here', ['availability' => fn ($context): bool => false]);
        $html = $this->html();

        $this->assertStringContainsString('value="script_logo"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('value="not_here"', $html, 'an unavailable method is absent from the page');
    }

    public function test_the_express_slot_is_not_rendered_when_empty_and_holds_what_an_extension_contributes(): void
    {
        $this->assertStringNotContainsString('sf-express', $this->html());

        $dir = sys_get_temp_dir().'/sf-express-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/wallet.blade.php', '<div class="wallet-test">PAYPAL BUTTON</div>');
        View::addNamespace('walletext', $dir);

        try {
            Hook::filter('storefront.checkout.express', fn (array $partials): array => [...$partials, 'walletext::wallet', 'walletext::missing', '', 42]);
            $html = $this->html();

            $this->assertMatchesRegularExpression('/<section class="sf-express" aria-label="Express checkout">\s*<div class="wallet-test">PAYPAL BUTTON<\/div>\s*<\/section>/', $html);
            $this->assertLessThan(strpos($html, 'id="sf-email"'), strpos($html, 'sf-express'), 'above "Contact"');
            $this->assertStringNotContainsString('walletext::missing', $html, 'a partial that does not exist is skipped');
        } finally {
            @unlink($dir.'/wallet.blade.php');
            @rmdir($dir);
        }
    }

    public function test_the_descriptor_and_the_adapter_lists_are_the_same_set(): void
    {
        $codes = array_map(fn ($d) => $d->code, app(PaymentMethodRegistry::class)->all());
        $bound = [];

        foreach (array_keys(app()->getBindings()) as $key) {
            if (is_string($key) && str_starts_with($key, 'payment.adapter.')) {
                $bound[] = substr($key, strlen('payment.adapter.'));
            }
        }

        sort($codes);
        sort($bound);
        $this->assertSame($bound, $codes, 'every descriptor has an adapter and every adapter a descriptor');
    }
}
