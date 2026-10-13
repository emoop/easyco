@extends('storefront.layout')

@section('content')
    {{-- The region rule per country (CheckoutRegionPolicy::all()): the script resolves the mode of the CURRENT country from it. --}}
    <section class="sf-checkout" data-sf-checkout data-region-policy="{{ json_encode($regionPolicy, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT) }}">
        <h1 class="sf-title">{{ __('storefront.checkout_heading') }}</h1>
        <p class="sf-cart-loading" data-sf-checkout-loading>{{ __('storefront.checkout_loading') }}</p>
        <noscript>
            <p class="sf-cart-noscript">{{ __('storefront.checkout_noscript') }}</p>
        </noscript>

        <div class="sf-error-summary" role="alert" tabindex="-1" data-sf-error-summary hidden></div>

        <div class="sf-checkout-layout">
            {{-- Below 1024px: a collapsed bar above the form that opens the SAME summary in place (the aside below). --}}
            <button class="sf-summary-toggle" type="button" aria-expanded="false" aria-controls="sf-summary-panel" data-sf-summary-toggle>
                <span class="sf-summary-toggle-label" data-sf-summary-toggle-label>{{ __('storefront.js_checkout.ck_summary_show') }}</span>
                <span class="sf-summary-toggle-sep" aria-hidden="true">·</span>
                <span class="sf-summary-toggle-total" data-sf-summary-toggle-total></span>
            </button>

            <aside class="sf-checkout-summary" id="sf-summary-panel" data-open="false" aria-labelledby="sf-summary-title">
                <h2 class="sf-section-title" id="sf-summary-title">{{ __('storefront.checkout_summary_title') }}</h2>
                <ul class="sf-summary-lines" data-sf-summary-lines></ul>
                {{-- The promo code box (the cart page's own, filled by the script): inside the summary, so inside the mobile panel too, and OUTSIDE the checkout form. --}}
                <div class="sf-summary-promo" data-sf-promo hidden></div>
                <p class="sf-field-error" id="sf-err-promo" role="alert" data-sf-promo-error hidden></p>
                <dl class="sf-cart-totals" data-sf-summary-totals></dl>
            </aside>

            <form class="sf-checkout-form" id="sf-checkout-form" data-sf-checkout-form>
                {{-- Every required field's label ends with the asterisk (rendered here for the always-required ones; the script toggles it, from the same state as `required`, for the fields whose requirement changes). --}}
                <p class="sf-required-note">{{ __('storefront.checkout_required_note') }}</p>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_contact') }}</legend>
                    <div class="sf-field">
                        <label for="sf-email">{{ __('storefront.checkout_email') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-email" name="email" type="email" autocomplete="email" inputmode="email" required aria-required="true" maxlength="255">
                        <p class="sf-field-error" id="sf-err-email" data-sf-error-for="email" hidden></p>
                    </div>
                    {{-- First and last name, side by side from a tablet up, stacked on a phone (checkout form v2, D1). --}}
                    <div class="sf-field-row">
                        <div class="sf-field">
                            <label for="sf-first-name">{{ __('storefront.checkout_first_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                            <input id="sf-first-name" name="recipient_first_name" type="text" autocomplete="given-name" required aria-required="true" maxlength="100">
                            <p class="sf-field-error" id="sf-err-recipient_first_name" data-sf-error-for="recipient_first_name" hidden></p>
                        </div>
                        <div class="sf-field">
                            <label for="sf-last-name">{{ __('storefront.checkout_last_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                            <input id="sf-last-name" name="recipient_last_name" type="text" autocomplete="family-name" required aria-required="true" maxlength="100">
                            <p class="sf-field-error" id="sf-err-recipient_last_name" data-sf-error-for="recipient_last_name" hidden></p>
                        </div>
                    </div>
                    <div class="sf-field">
                        <label for="sf-phone">{{ __('storefront.checkout_phone') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required aria-required="true" maxlength="32">
                        <p class="sf-field-error" id="sf-err-phone" data-sf-error-for="phone" hidden></p>
                    </div>
                </fieldset>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_delivery') }}</legend>
                    <div class="sf-field">
                        <label for="sf-country">{{ __('storefront.checkout_country') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-country" name="country" type="text" value="{{ $defaultCountry }}" autocomplete="country" maxlength="2" required aria-required="true">
                        <p class="sf-field-error" id="sf-err-country" data-sf-error-for="country" hidden></p>
                    </div>
                    {{-- ONE place field: the street address's city, and the settlement a pick-up point is quoted and stored for. --}}
                    <div class="sf-field">
                        <label for="sf-city">{{ __('storefront.checkout_city') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-city" name="city" type="text" autocomplete="address-level2" required aria-required="true" maxlength="255">
                        <p class="sf-field-error" id="sf-err-city" data-sf-error-for="city" hidden></p>
                    </div>

                    <p class="sf-field-note" role="status" aria-live="polite" data-sf-quote-status></p>
                    <p class="sf-shipping-hint" data-sf-shipping-hint hidden></p>

                    <div class="sf-delivery-step" data-sf-courier-box hidden>
                        <p class="sf-delivery-step-title" id="sf-courier-title">{{ __('storefront.checkout_courier') }}</p>
                        <div class="sf-choice-stack" role="radiogroup" aria-labelledby="sf-courier-title" data-sf-couriers></div>
                    </div>
                    {{-- After a courier: its kinds. A native <select> for two or more, plain text for exactly one (the script fills both). --}}
                    <div class="sf-delivery-step" data-sf-type-box hidden>
                        <label class="sf-delivery-step-title" for="sf-delivery-destination">{{ __('storefront.checkout_type') }}</label>
                        <select class="sf-kind-select" id="sf-delivery-destination" name="delivery_destination" data-sf-kind-select hidden></select>
                        <p class="sf-kind-text" data-sf-kind-text hidden></p>
                    </div>
                    <p class="sf-field-error" id="sf-err-shipping_method_id" data-sf-error-for="shipping_method_id" hidden></p>

                    <div data-sf-address-fields hidden>
                        <div class="sf-field">
                            <label for="sf-postal-code">{{ __('storefront.checkout_postal_code') }}</label>
                            <input id="sf-postal-code" name="postal_code" type="text" autocomplete="postal-code" inputmode="numeric" maxlength="255">
                            <p class="sf-field-error" id="sf-err-postal_code" data-sf-error-for="postal_code" hidden></p>
                        </div>
                        <div class="sf-field">
                            <label for="sf-address-1">{{ __('storefront.checkout_address_1') }}</label>
                            <input id="sf-address-1" name="address_line_1" type="text" autocomplete="address-line1" maxlength="255">
                            <p class="sf-field-error" id="sf-err-address_line_1" data-sf-error-for="address_line_1" hidden></p>
                        </div>
                        <div class="sf-field">
                            <label for="sf-address-2">{{ __('storefront.checkout_address_2') }}</label>
                            <input id="sf-address-2" name="address_line_2" type="text" autocomplete="address-line2" maxlength="255">
                            <p class="sf-field-error" id="sf-err-address_line_2" data-sf-error-for="address_line_2" hidden></p>
                        </div>
                        {{-- The region (state / province): optional, required or hidden per country; the street address only. --}}
                        <div class="sf-field" data-sf-region-field>
                            <label for="sf-region">{{ __('storefront.checkout_region') }}</label>
                            <input id="sf-region" name="region" type="text" autocomplete="address-level1" maxlength="100">
                            <p class="sf-field-error" id="sf-err-region" data-sf-error-for="region" hidden></p>
                        </div>
                    </div>

                    <div data-sf-pickup-fields hidden>
                        {{-- ONE field for the office or locker: the customer types the address, the name or the code, whatever they know (checkout form v2, D3, D14). --}}
                        <div class="sf-field">
                            <label for="sf-pickup-address">{{ __('storefront.checkout_pickup_address') }}</label>
                            <input id="sf-pickup-address" name="pickup_point_address" type="text" maxlength="255" aria-describedby="sf-pickup-help" data-sf-describedby="sf-pickup-help">
                            <p class="sf-field-help" id="sf-pickup-help">{{ __('storefront.checkout_pickup_help') }}</p>
                            <p class="sf-field-error" id="sf-err-pickup_point_address" data-sf-error-for="pickup_point_address" hidden></p>
                        </div>
                    </div>
                </fieldset>

                <div class="sf-field sf-note-field">
                    <label for="sf-note">{{ __('storefront.checkout_note') }}</label>
                    <textarea id="sf-note" name="customer_note" rows="3" maxlength="1000" placeholder="{{ __('storefront.checkout_note_placeholder') }}" aria-describedby="sf-note-counter" data-sf-describedby="sf-note-counter"></textarea>
                    <p class="sf-note-counter" id="sf-note-counter" data-sf-note-counter>{{ __('storefront.js_checkout.ck_note_counter', ['count' => 0, 'max' => 1000]) }}</p>
                    <p class="sf-field-error" id="sf-err-customer_note" data-sf-error-for="customer_note" hidden></p>
                </div>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_payment') }}</legend>
                    <div class="sf-field sf-choice-row" role="radiogroup" aria-label="{{ __('storefront.checkout_payment') }}">
                        @foreach ($paymentMethods as $method)
                            <label class="sf-choice"><input type="radio" name="payment_method" value="{{ $method['code'] }}" @checked($loop->first)> <span>{{ $method['label'] }}</span></label>
                        @endforeach
                    </div>
                    <p class="sf-field-error" id="sf-err-payment_method" data-sf-error-for="payment_method" hidden></p>
                </fieldset>

                <div class="sf-checkout-actions">
                    <button class="sf-place-order" type="submit" disabled data-sf-place-order>{{ __('storefront.checkout_place_order') }}</button>
                    <p class="sf-cart-status" role="status" aria-live="polite" data-sf-checkout-status></p>
                    <a class="sf-checkout-back" href="{{ route('storefront.cart', [], false) }}">{{ __('storefront.checkout_back_to_cart') }}</a>
                </div>
            </form>
        </div>
    </section>
@endsection
