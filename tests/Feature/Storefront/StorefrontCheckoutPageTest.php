<?php

namespace Tests\Feature\Storefront;

use App\Services\PaymentMethodAdapterResolver;
use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/** C1: GET /checkout, a cookie-free server shell driven by the browser on the existing API. */
class StorefrontCheckoutPageTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$category], 'price' => '10.00', 'stock' => 3]);
    }

    public function test_the_shell_is_private_no_store_noindex_and_cookie_free(): void
    {
        $response = $this->get('/checkout')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['session']->driver()->isStarted());
        $this->assertDoesNotMatchRegularExpression('/csrf|_token/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html);

        $this->withUnencryptedCookies(['laravel_session' => 'abc', 'XSRF-TOKEN' => 'x', 'easyco_cart' => 'y', 'easyco_has_cart' => '1'])
            ->get('/checkout')->assertOk()->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_route_is_in_the_cookie_free_group_and_named(): void
    {
        $route = Route::getRoutes()->getByName('storefront.checkout');

        $this->assertNotNull($route);
        $this->assertSame('checkout', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'cart.cookie'))));
    }

    public function test_the_payment_methods_come_from_the_resolver_with_labels_from_lang(): void
    {
        $html = $this->get('/checkout')->getContent();

        foreach (app(PaymentMethodAdapterResolver::class)->availableMethods() as $code) {
            $this->assertStringContainsString('name="payment_method" value="'.$code.'"', $html, $code);
        }

        $this->assertStringContainsString('<span>Cash on delivery</span>', $html);
        $this->assertStringContainsString('<span>Bank transfer</span>', $html);
        $this->assertSame(1, preg_match_all('/name="payment_method" value="[a-z_]+" checked/', $html), 'exactly the first method is preselected');
    }

    public function test_the_payment_labels_are_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/checkout')->getContent();

        $this->assertStringContainsString('<span>Наложен платеж</span>', $html);
        $this->assertStringContainsString('<span>Банков превод</span>', $html);
        $this->assertStringContainsString('Контакт', $html);
    }

    public function test_the_form_has_the_right_autocomplete_input_modes_and_labels(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertMatchesRegularExpression('/<input id="sf-email" name="email" type="email" autocomplete="email" inputmode="email" required/', $html);
        $this->assertMatchesRegularExpression('/<input id="sf-first-name" name="recipient_first_name" type="text" autocomplete="given-name" required/', $html);
        $this->assertMatchesRegularExpression('/<input id="sf-last-name" name="recipient_last_name" type="text" autocomplete="family-name" required/', $html);
        $this->assertMatchesRegularExpression('/<input id="sf-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required/', $html);
        $this->assertStringContainsString('autocomplete="address-level2"', $html);
        $this->assertStringContainsString('autocomplete="postal-code"', $html);
        $this->assertStringContainsString('autocomplete="address-line1"', $html);
        $this->assertStringContainsString('autocomplete="address-line2"', $html);
        $this->assertStringContainsString('autocomplete="country"', $html);

        foreach (['sf-email', 'sf-first-name', 'sf-last-name', 'sf-phone', 'sf-country', 'sf-city', 'sf-postal-code', 'sf-address-1', 'sf-address-2', 'sf-pickup-address'] as $id) {
            $this->assertStringContainsString('<label for="'.$id.'">', $html, "a label for #{$id}");
        }
    }

    public function test_the_error_regions_and_the_summary_are_in_the_markup(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertMatchesRegularExpression('/<div class="sf-error-summary" role="alert" tabindex="-1" data-sf-error-summary hidden>/', $html);

        foreach (['email', 'recipient_first_name', 'recipient_last_name', 'phone', 'country', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'pickup_point_address', 'shipping_method_id', 'payment_method'] as $field) {
            $this->assertStringContainsString('id="sf-err-'.$field.'" data-sf-error-for="'.$field.'" hidden', $html, $field);
        }

        $this->assertStringContainsString('data-sf-place-order', $html);
        $this->assertMatchesRegularExpression('/<button class="sf-place-order" type="submit" disabled data-sf-place-order>/', $html, 'disabled until the script enables it');
        $this->assertStringContainsString('<noscript>', $html);
    }

    public function test_the_delivery_is_courier_first_with_no_typed_courier_or_kind_fields(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertMatchesRegularExpression('/<select id="sf-country" name="country" autocomplete="country" required aria-required="true">.*?<option value="BG" selected>/s', $html);

        // The courier fills carrier_code; the customer types none, and there is no up-front address/office/locker choice.
        $this->assertStringNotContainsString('name="carrier_code"', $html);
        $this->assertStringNotContainsString('name="settlement"', $html);
        $this->assertStringNotContainsString('name="destination_kind"', $html);
        $this->assertStringNotContainsString('sf-carrier-code', $html);
        $this->assertStringNotContainsString('Courier (for example', $html);

        // Courier, then kind: two empty, hidden steps the script fills from the one `all` quote. The kind is a NATIVE select (with its
        // label) and a text line for a courier that serves exactly one kind.
        $this->assertMatchesRegularExpression('/<div class="sf-delivery-step" data-sf-courier-box hidden>.*?role="radiogroup" aria-labelledby="sf-courier-title" data-sf-couriers>/s', $html);
        $this->assertMatchesRegularExpression('/<div class="sf-delivery-step sf-float sf-float-up" data-sf-type-box hidden>\s*<label class="sf-delivery-step-title" for="sf-delivery-destination">[^<]+<\/label>\s*<select class="sf-kind-select" id="sf-delivery-destination" name="delivery_destination" data-sf-kind-select hidden><\/select>\s*<p class="sf-kind-text" data-sf-kind-text hidden><\/p>/s', $html);
        $this->assertLessThan(strpos($html, 'data-sf-type-box'), strpos($html, 'data-sf-courier-box'));

        // The street fields and the pick-up point's fields start hidden: they appear for the type the customer picks.
        $this->assertStringContainsString('<div data-sf-address-fields hidden>', $html);
        $this->assertStringContainsString('<div data-sf-pickup-fields hidden>', $html);

        // ONE place field serves the address and the pick-up point.
        $this->assertSame(1, preg_match_all('/name="city"/', $html));
    }

    // ---- C1b: the region field, the note, the policy handed to the script -------------------------------------------------------------------

    /** @return array{default: string, countries: array<string, string>} the policy the page hands to the script */
    private function policyOf(string $html): array
    {
        $this->assertSame(1, preg_match('/<section class="sf-checkout" data-sf-checkout data-region-policy="([^"]*)">/', $html, $match), 'the policy is ONE attribute of the checkout section, its value free of a raw quote');

        return json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_with_no_policy_stored_the_page_says_optional_and_no_exceptions(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertStringContainsString('data-region-policy="{&quot;default&quot;:&quot;optional&quot;,&quot;countries&quot;:{}}"', $html, 'an empty list of exceptions is an object, {}');
        $this->assertSame(['default' => 'optional', 'countries' => []], $this->policyOf($html));
    }

    public function test_the_stored_policy_is_the_one_in_the_attribute(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(CheckoutRegionPolicy::DEFAULT_KEY, 'hidden');
        $settings->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"DE":"required","US":"optional"}');

        $this->assertSame(['default' => 'hidden', 'countries' => ['DE' => 'required', 'US' => 'optional']], $this->policyOf($this->get('/checkout')->getContent()));
    }

    public function test_the_policy_attribute_cannot_be_broken_out_of(): void
    {
        // The real policy only ever holds known ISO codes and three words (anything else fails loud), so hostile text is injected where the
        // view receives it: the attribute must still be ONE attribute and the text must come back unchanged, never as markup.
        $hostile = ['default' => 'optional', 'countries' => ['"><script>alert(1)</script>' => 'required\'"</section><img src=x onerror=alert(2)>']];
        View::composer('storefront.checkout', fn ($view) => $view->with('regionPolicy', $hostile));

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertSame($hostile, $this->policyOf($html));
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('onerror=alert(2)>', $html);
    }

    public function test_a_malformed_stored_policy_fails_loud_instead_of_showing_a_page_that_guesses(): void
    {
        app(SiteSettingsRepository::class)->set(CheckoutRegionPolicy::DEFAULT_KEY, 'sometimes');

        $this->get('/checkout')->assertStatus(500);
    }

    public function test_the_region_input_lives_in_the_street_block_and_the_note_textarea_is_always_there(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertMatchesRegularExpression('/<div data-sf-address-fields hidden>.*?<div class="sf-field sf-float" data-sf-region-field>\s*<label for="sf-region">Region<\/label>\s*<input id="sf-region" name="region" type="text" autocomplete="address-level1" maxlength="100" placeholder=" ">\s*<p class="sf-field-error" id="sf-err-region" data-sf-error-for="region" hidden><\/p>.*?<div data-sf-pickup-fields hidden>/s', $html, 'the region is part of the street-address block, before the pick-up block');
        $this->assertStringNotContainsString('name="region"', substr($html, (int) strpos($html, 'data-sf-pickup-fields')), 'never for a pick-up point');

        $this->assertMatchesRegularExpression('/<label for="sf-note">Order note<\/label>\s*<textarea id="sf-note" name="customer_note" rows="3" maxlength="1000" placeholder="[^"]+" aria-describedby="sf-note-counter" data-sf-describedby="sf-note-counter"><\/textarea>/', $html);
        $this->assertStringContainsString('<p class="sf-note-counter" id="sf-note-counter" data-sf-note-counter>0 / 1000</p>', $html);
        $this->assertStringContainsString('id="sf-err-customer_note" data-sf-error-for="customer_note" hidden', $html);
        $this->assertStringNotContainsString('data-sf-note-field hidden', $html, 'the note is always visible');
    }

    public function test_the_labels_of_the_new_fields_are_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/checkout')->getContent();

        $this->assertStringContainsString('<label for="sf-region">Област</label>', $html);
        $this->assertStringContainsString('<label for="sf-note">Бележка към поръчката</label>', $html);
    }

    public function test_the_promo_box_is_in_the_summary_above_the_totals_and_outside_the_form(): void
    {
        $html = $this->get('/checkout')->getContent();

        // Inside the summary aside (so inside the mobile panel), after the lines and before the totals...
        $this->assertMatchesRegularExpression('/<aside class="sf-checkout-summary".*?data-sf-summary-lines><\/ul>.*?<div class="sf-summary-promo" data-sf-promo hidden><\/div>\s*<p class="sf-field-error" id="sf-err-promo" role="alert" data-sf-promo-error hidden><\/p>.*?<dl class="sf-cart-totals" data-sf-summary-totals><\/dl>.*?<\/aside>/s', $html);
        // ...and the checkout form (where nothing may submit the order by accident) starts after the aside closes.
        $this->assertLessThan(strpos($html, '<form class="sf-checkout-form"'), strpos($html, 'data-sf-promo hidden'));
        $this->assertSame(1, substr_count($html, '<form '), 'the promo form is built by the script; the page holds only the checkout form');
        $this->assertStringNotContainsString('<fieldset class="sf-fieldset"><div class="sf-summary-promo"', $html);
    }

    /** Checkout form v2, section 4: the asterisk of a label and the `required` of its input are ONE fact, in both directions. */
    public function test_a_required_input_has_the_marker_and_the_marker_sits_on_no_optional_input(): void
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$this->get('/checkout')->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@id="sf-checkout-form"]')->item(0);
        $this->assertNotNull($form);

        $required = [];
        $marked = [];

        foreach ($xpath->query('.//input[@id] | .//textarea[@id] | .//select[@id]', $form) as $input) {
            $id = $input->getAttribute('id');
            $label = $xpath->query('.//label[@for="'.$id.'"]', $form)->item(0);
            $hasMarker = $label !== null && $xpath->query('.//span[@class="sf-required" and @aria-hidden="true" and normalize-space(.)="*"]', $label)->length === 1;

            if ($input->hasAttribute('required')) {
                $required[] = $id;
                $this->assertSame('true', $input->getAttribute('aria-required'), "#{$id} is required, so aria-required");
                $this->assertTrue($hasMarker, "#{$id} is required but its label has no asterisk");
            }

            if ($hasMarker) {
                $marked[] = $id;
                $this->assertTrue($input->hasAttribute('required'), "#{$id} has an asterisk but is not required");
            }
        }

        $this->assertSame($required, $marked);
        $this->assertSame(['sf-email', 'sf-first-name', 'sf-last-name', 'sf-phone', 'sf-country', 'sf-city'], $required, 'the always-required fields; the others are toggled by the script from the same state as `required`');
        $this->assertStringContainsString('<p class="sf-required-note">Fields marked with * are required.</p>', $this->get('/checkout')->getContent());
    }

    public function test_the_required_line_and_the_two_names_are_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $html = $this->get('/checkout')->getContent();

        $this->assertStringContainsString('Полетата, отбелязани с *, са задължителни.', $html);
        $this->assertStringContainsString('<label for="sf-first-name">Име <span class="sf-required" aria-hidden="true">*</span></label>', $html);
        $this->assertStringContainsString('<label for="sf-last-name">Фамилия <span class="sf-required" aria-hidden="true">*</span></label>', $html);
        $this->assertStringContainsString('<label for="sf-pickup-address">Офис или автомат</label>', $html);
        $this->assertStringContainsString('Адрес, име или код на офиса', $html);
    }

    public function test_the_pickup_block_is_one_field_with_a_linked_help_line_and_no_map_note(): void
    {
        $html = $this->get('/checkout')->getContent();
        $block = substr($html, (int) strpos($html, '<div data-sf-pickup-fields hidden>'), 1400);

        $this->assertSame(1, substr_count($block, '<input '), 'ONE input in the pick-up block');
        $this->assertStringContainsString('<input id="sf-pickup-address" name="pickup_point_address" type="text" maxlength="255" aria-describedby="sf-pickup-help" data-sf-describedby="sf-pickup-help" placeholder=" ">', $block);
        $this->assertStringContainsString('<p class="sf-field-help" id="sf-pickup-help">The office&#039;s address, name or code</p>', $block);
        $this->assertStringContainsString('<label for="sf-pickup-address">Office or locker</label>', $block);
        $this->assertStringNotContainsString('name="pickup_point_reference"', $html);
        $this->assertStringNotContainsString('name="pickup_point_name"', $html);
        $this->assertStringNotContainsString('map', strtolower($block), 'the "not selected from a map yet" note is gone');
    }

    public function test_the_summary_is_a_sticky_right_column_with_a_mobile_toggle_bar(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertMatchesRegularExpression('/<button class="sf-summary-toggle" type="button" aria-expanded="false" aria-controls="sf-summary-panel" data-sf-summary-toggle>/', $html);
        $this->assertMatchesRegularExpression('/<aside class="sf-checkout-summary" id="sf-summary-panel" data-open="false"/', $html);
        $this->assertStringContainsString('data-sf-summary-lines', $html);
        $this->assertStringContainsString('data-sf-summary-totals', $html);
        $this->assertStringContainsString('data-sf-summary-toggle-total', $html);

        // The bar comes first, then the SAME summary, then the form (the grid puts the summary in the right column on desktop).
        $this->assertLessThan(strpos($html, 'id="sf-summary-panel"'), strpos($html, 'data-sf-summary-toggle>'));
        $this->assertLessThan(strpos($html, 'data-sf-checkout-form>'), strpos($html, 'id="sf-summary-panel"'));
        $this->assertStringContainsString('data-sf-summary-toggle-label>Show order</span>', $html);

        $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/storefront.css');

        $this->assertMatchesRegularExpression('/@media \(min-width: 64rem\) \{.*?grid-template-columns: minmax\(0, 1fr\) 24rem;.*?\.sf-checkout-summary \{\s*grid-area: summary;\s*position: sticky;.*?\.sf-summary-toggle \{\s*display: none;/s', $css, 'desktop: two columns, the summary sticky on the right, no toggle bar');
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.999rem\) \{\s*\.sf-checkout-summary\[data-open=\'false\'\] \{\s*display: none;/', $css, 'mobile: collapsed until opened');
    }

    public function test_the_toggle_label_is_translated(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $this->assertStringContainsString('data-sf-summary-toggle-label>Покажи поръчката</span>', $this->get('/checkout')->getContent());
    }

    public function test_the_script_data_the_page_hands_over(): void
    {
        $html = $this->get('/checkout')->getContent();

        $this->assertStringContainsString('data-price-template="0.00 €"', $html);
        $this->assertStringContainsString('data-checkout-url="/checkout"', $html);
        $this->assertStringContainsString('data-m-ck-placing=', $html);
        $this->assertStringContainsString('data-m-generic-error=', $html);
    }

    public function test_the_header_and_the_cart_page_link_to_it(): void
    {
        $this->assertStringContainsString('data-checkout-url="/checkout"', $this->get('/cart')->getContent());
    }

    public function test_the_query_count_of_the_shell(): void
    {
        $this->app->forgetScopedInstances();

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->get('/checkout')->assertOk();

        fwrite(STDERR, "\n[query-count] storefront /checkout: ".count($sql)." queries (pinned ceiling 8)\n");

        // 8 = locale, time zone, currency position, store country, the menu (2), ONE read of the checkout form's settings (all keys, the
        // region rule included: getMany) and ONE read of the published content pages (P1: the footer's list, which the terms / privacy
        // page lookup shares). It stays a ceiling: 9 fails.
        $this->assertLessThanOrEqual(8, count($sql), 'the shell reads no cart; the settings are ONE query and the content pages ONE');
    }
}
