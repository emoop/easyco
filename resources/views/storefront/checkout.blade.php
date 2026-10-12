@extends('storefront.layout')

@section('content')
    <section class="sf-checkout" data-sf-checkout>
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
                <dl class="sf-cart-totals" data-sf-summary-totals></dl>
            </aside>

            <form class="sf-checkout-form" id="sf-checkout-form" data-sf-checkout-form>
                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_contact') }}</legend>
                    <div class="sf-field">
                        <label for="sf-email">{{ __('storefront.checkout_email') }}</label>
                        <input id="sf-email" name="email" type="email" autocomplete="email" inputmode="email" required maxlength="255">
                        <p class="sf-field-error" id="sf-err-email" data-sf-error-for="email" hidden></p>
                    </div>
                    <div class="sf-field">
                        <label for="sf-recipient-name">{{ __('storefront.checkout_name') }}</label>
                        <input id="sf-recipient-name" name="recipient_name" type="text" autocomplete="name" required maxlength="255">
                        <p class="sf-field-error" id="sf-err-recipient_name" data-sf-error-for="recipient_name" hidden></p>
                    </div>
                    <div class="sf-field">
                        <label for="sf-phone">{{ __('storefront.checkout_phone') }}</label>
                        <input id="sf-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required maxlength="32">
                        <p class="sf-field-error" id="sf-err-phone" data-sf-error-for="phone" hidden></p>
                    </div>
                </fieldset>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_delivery') }}</legend>
                    <div class="sf-field">
                        <label for="sf-country">{{ __('storefront.checkout_country') }}</label>
                        <input id="sf-country" name="country" type="text" value="{{ $defaultCountry }}" autocomplete="country" maxlength="2" required>
                        <p class="sf-field-error" id="sf-err-country" data-sf-error-for="country" hidden></p>
                    </div>
                    {{-- ONE place field: the street address's city, and the settlement a pick-up point is quoted and stored for. --}}
                    <div class="sf-field">
                        <label for="sf-city">{{ __('storefront.checkout_city') }}</label>
                        <input id="sf-city" name="city" type="text" autocomplete="address-level2" required maxlength="255">
                        <p class="sf-field-error" id="sf-err-city" data-sf-error-for="city" hidden></p>
                    </div>

                    <p class="sf-field-note" role="status" aria-live="polite" data-sf-quote-status></p>
                    <p class="sf-shipping-hint" data-sf-shipping-hint hidden></p>

                    <div class="sf-delivery-step" data-sf-courier-box hidden>
                        <p class="sf-delivery-step-title" id="sf-courier-title">{{ __('storefront.checkout_courier') }}</p>
                        <div class="sf-choice-stack" role="radiogroup" aria-labelledby="sf-courier-title" data-sf-couriers></div>
                    </div>
                    <div class="sf-delivery-step" data-sf-type-box hidden>
                        <p class="sf-delivery-step-title" id="sf-type-title">{{ __('storefront.checkout_type') }}</p>
                        <div class="sf-choice-stack" role="radiogroup" aria-labelledby="sf-type-title" data-sf-types></div>
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
                    </div>

                    <div data-sf-pickup-fields hidden>
                        <p class="sf-field-note">{{ __('storefront.checkout_pickup_note') }}</p>
                        <div class="sf-field">
                            <label for="sf-pickup-reference">{{ __('storefront.checkout_pickup_reference') }}</label>
                            <input id="sf-pickup-reference" name="pickup_point_reference" type="text" maxlength="255">
                            <p class="sf-field-error" id="sf-err-pickup_point_reference" data-sf-error-for="pickup_point_reference" hidden></p>
                        </div>
                        <div class="sf-field">
                            <label for="sf-pickup-name">{{ __('storefront.checkout_pickup_name') }}</label>
                            <input id="sf-pickup-name" name="pickup_point_name" type="text" maxlength="255">
                            <p class="sf-field-error" id="sf-err-pickup_point_name" data-sf-error-for="pickup_point_name" hidden></p>
                        </div>
                        <div class="sf-field">
                            <label for="sf-pickup-address">{{ __('storefront.checkout_pickup_address') }}</label>
                            <input id="sf-pickup-address" name="pickup_point_address" type="text" maxlength="255">
                            <p class="sf-field-error" id="sf-err-pickup_point_address" data-sf-error-for="pickup_point_address" hidden></p>
                        </div>
                    </div>
                </fieldset>

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
