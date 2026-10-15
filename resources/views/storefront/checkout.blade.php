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

        <div class="sf-checkout-layout" data-sf-checkout-layout>
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
                {{-- The express-checkout slot (one-click wallets), filled by extensions through the `storefront.checkout.express` filter: nothing is rendered while it is empty. --}}
                @if ($expressPartials !== [])
                    <section class="sf-express" aria-label="{{ __('storefront.checkout_express') }}">
                        @foreach ($expressPartials as $partial)
                            @include($partial)
                        @endforeach
                    </section>
                    <p class="sf-express-divider" role="separator"><span>{{ __('storefront.checkout_or') }}</span></p>
                @endif

                <p class="sf-required-note">{{ __('storefront.checkout_required_note') }}</p>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_contact') }}</legend>
                    <div class="sf-field sf-float">
                        <label for="sf-email">{{ __('storefront.checkout_email') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-email" name="email" type="email" autocomplete="email" inputmode="email" required aria-required="true" maxlength="255" placeholder=" ">
                        <p class="sf-field-error" id="sf-err-email" data-sf-error-for="email" hidden></p>
                    </div>
                    {{-- First and last name, side by side from a tablet up, stacked on a phone (checkout form v2, D1). --}}
                    <div class="sf-field-row">
                        <div class="sf-field sf-float">
                            <label for="sf-first-name">{{ __('storefront.checkout_first_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                            <input id="sf-first-name" name="recipient_first_name" type="text" autocomplete="given-name" required aria-required="true" maxlength="100" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-recipient_first_name" data-sf-error-for="recipient_first_name" hidden></p>
                        </div>
                        <div class="sf-field sf-float">
                            <label for="sf-last-name">{{ __('storefront.checkout_last_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                            <input id="sf-last-name" name="recipient_last_name" type="text" autocomplete="family-name" required aria-required="true" maxlength="100" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-recipient_last_name" data-sf-error-for="recipient_last_name" hidden></p>
                        </div>
                    </div>
                    {{-- Optional and never ticked (checkout form v2, D6, D8): shown only when the merchant turned it on. The label IS the sentence the server records as the proof. --}}
                    @if ($form['newsletter'])
                        <div class="sf-field sf-check">
                            <input id="sf-newsletter" name="marketing_consent" type="checkbox" value="1">
                            <label for="sf-newsletter">{{ __('checkout.marketing_consent_text') }}</label>
                        </div>
                    @endif
                    <div class="sf-field sf-float">
                        <label for="sf-phone">{{ __('storefront.checkout_phone') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <input id="sf-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required aria-required="true" maxlength="32" placeholder=" ">
                        <p class="sf-field-error" id="sf-err-phone" data-sf-error-for="phone" hidden></p>
                    </div>
                    @if ($form['callBeforeShipping'])
                        <div class="sf-field sf-check">
                            <input id="sf-call-before" name="call_before_shipping" type="checkbox" value="1">
                            <label for="sf-call-before">{{ __('storefront.checkout_call_before') }}</label>
                        </div>
                    @endif
                </fieldset>

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_delivery') }}</legend>
                    <div class="sf-field sf-float sf-float-up">
                        <label for="sf-country">{{ __('storefront.checkout_country') }} <span class="sf-required" aria-hidden="true">*</span></label>
                        <select id="sf-country" name="country" autocomplete="country" required aria-required="true">
                            @foreach ($countries as $code => $countryName)
                                <option value="{{ $code }}"{{ $code === $defaultCountry ? ' selected' : '' }}>{{ $countryName }}</option>
                            @endforeach
                        </select>
                        <p class="sf-field-error" id="sf-err-country" data-sf-error-for="country" hidden></p>
                    </div>
                    {{-- ONE place field: the street address's city, and the settlement a pick-up point is quoted and stored for. The postal code sits NEXT TO it (C1e, G4):
                         shown for a street address and while no kind is chosen, hidden (and never sent) for an office or a locker. --}}
                    <div class="sf-field-row sf-place-row">
                        <div class="sf-field sf-float">
                            <label for="sf-city">{{ __('storefront.checkout_city') }} <span class="sf-required" aria-hidden="true">*</span></label>
                            <input id="sf-city" name="city" type="text" autocomplete="address-level2" required aria-required="true" maxlength="255" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-city" data-sf-error-for="city" hidden></p>
                        </div>
                        <div class="sf-field sf-float" data-sf-postal-field>
                            <label for="sf-postal-code">{{ __('storefront.checkout_postal_code_optional') }}</label>
                            <input id="sf-postal-code" name="postal_code" type="text" autocomplete="postal-code" inputmode="numeric" maxlength="255" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-postal_code" data-sf-error-for="postal_code" hidden></p>
                        </div>
                    </div>

                    <p class="sf-field-note" role="status" aria-live="polite" data-sf-quote-status></p>
                    <p class="sf-shipping-hint" data-sf-shipping-hint hidden></p>

                    <div class="sf-delivery-step" data-sf-courier-box hidden>
                        <p class="sf-delivery-step-title" id="sf-courier-title">{{ __('storefront.checkout_courier') }}</p>
                        <div class="sf-choice-stack" role="radiogroup" aria-labelledby="sf-courier-title" data-sf-couriers></div>
                    </div>
                    {{-- After a courier: its kinds. A native <select> for two or more, plain text for exactly one (the script fills both). --}}
                    <div class="sf-delivery-step sf-float sf-float-up" data-sf-type-box hidden>
                        <label class="sf-delivery-step-title" for="sf-delivery-destination">{{ __('storefront.checkout_type') }}</label>
                        <select class="sf-kind-select" id="sf-delivery-destination" name="delivery_destination" data-sf-kind-select hidden></select>
                        <p class="sf-kind-text" data-sf-kind-text hidden></p>
                    </div>
                    <p class="sf-field-error" id="sf-err-shipping_method_id" data-sf-error-for="shipping_method_id" hidden></p>

                    <div data-sf-address-fields hidden>
                        <div class="sf-field sf-float">
                            <label for="sf-address-1">{{ __('storefront.checkout_address_1') }}</label>
                            <input id="sf-address-1" name="address_line_1" type="text" autocomplete="address-line1" maxlength="255" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-address_line_1" data-sf-error-for="address_line_1" hidden></p>
                        </div>
                        <div class="sf-field sf-float">
                            <label for="sf-address-2">{{ __('storefront.checkout_address_2') }}</label>
                            <input id="sf-address-2" name="address_line_2" type="text" autocomplete="address-line2" maxlength="255" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-address_line_2" data-sf-error-for="address_line_2" hidden></p>
                        </div>
                        {{-- The region (state / province): optional, required or hidden per country; the street address only. --}}
                        <div class="sf-field sf-float" data-sf-region-field>
                            <label for="sf-region">{{ __('storefront.checkout_region') }}</label>
                            <input id="sf-region" name="region" type="text" autocomplete="address-level1" maxlength="100" placeholder=" ">
                            <p class="sf-field-error" id="sf-err-region" data-sf-error-for="region" hidden></p>
                        </div>
                    </div>

                    <div data-sf-pickup-fields hidden>
                        {{-- ONE field for the office or locker: the customer types the address, the name or the code, whatever they know (checkout form v2, D3, D14). --}}
                        <div class="sf-field sf-float">
                            <label for="sf-pickup-address">{{ __('storefront.checkout_pickup_address') }}</label>
                            <input id="sf-pickup-address" name="pickup_point_address" type="text" maxlength="255" aria-describedby="sf-pickup-help" data-sf-describedby="sf-pickup-help" placeholder=" ">
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

                {{-- THE INVOICE (order-context-design.md 9.3, stage C2-A): a box that is NOT ticked, and a block that is HIDDEN until it is. While hidden none of
                     its fields is required, validated by the browser or sent; the script (storefront-checkout.js) shows it, sets the requirements from
                     the rule of the INVOICE country and sends an `invoice` object. Only when the merchant offers invoices (invoice.enabled). --}}
                @if ($invoice['enabled'])
                    <div class="sf-invoice" data-sf-invoice data-invoice-rules="{{ json_encode($invoice['rules'], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT) }}">
                        <div class="sf-field sf-check">
                            <input id="sf-invoice-requested" name="invoice_requested" type="checkbox" value="1" aria-controls="sf-invoice-block" data-sf-invoice-toggle>
                            <label for="sf-invoice-requested">{{ __('storefront.checkout_invoice_toggle') }}</label>
                        </div>
                        <fieldset class="sf-fieldset sf-invoice-block" id="sf-invoice-block" data-sf-invoice-block hidden>
                            <legend class="sf-legend">{{ __('storefront.checkout_invoice_legend') }}</legend>
                            {{-- A choice of who the invoice is for (a company is selected), not a consent. --}}
                            <div class="sf-field">
                                <p class="sf-invoice-type-title" id="sf-invoice-type-title">{{ __('storefront.checkout_invoice_type') }}</p>
                                <div class="sf-invoice-type" role="radiogroup" aria-labelledby="sf-invoice-type-title">
                                    <label class="sf-invoice-type-option"><input type="radio" name="invoice_recipient_type" value="company" checked> <span>{{ __('storefront.checkout_invoice_company') }}</span></label>
                                    <label class="sf-invoice-type-option"><input type="radio" name="invoice_recipient_type" value="individual"> <span>{{ __('storefront.checkout_invoice_person') }}</span></label>
                                </div>
                            </div>
                            <div class="sf-field sf-float">
                                <label for="sf-invoice-legal-name"><span data-sf-label-text data-label-company="{{ __('storefront.checkout_invoice_company_name') }}" data-label-individual="{{ __('storefront.checkout_invoice_person_name') }}">{{ __('storefront.checkout_invoice_company_name') }}</span></label>
                                <input id="sf-invoice-legal-name" name="invoice_legal_name" type="text" autocomplete="organization" maxlength="255" placeholder=" ">
                                <p class="sf-field-error" id="sf-err-invoice_legal_name" data-sf-error-for="invoice_legal_name" hidden></p>
                            </div>
                            <div data-sf-invoice-company>
                                <div class="sf-field sf-float">
                                    <label for="sf-invoice-tax-id"><span data-sf-label-text data-label-default="{{ __('checkout.invoice_labels.tax_id') }}">{{ __('checkout.invoice_labels.tax_id') }}</span></label>
                                    <input id="sf-invoice-tax-id" name="invoice_tax_id" type="text" autocomplete="off" maxlength="64" placeholder=" ">
                                    <p class="sf-field-error" id="sf-err-invoice_tax_id" data-sf-error-for="invoice_tax_id" hidden></p>
                                </div>
                                <div class="sf-field sf-float">
                                    <label for="sf-invoice-registration"><span data-sf-label-text data-label-default="{{ __('checkout.invoice_labels.registration_number') }}">{{ __('checkout.invoice_labels.registration_number') }}</span></label>
                                    <input id="sf-invoice-registration" name="invoice_registration_number" type="text" autocomplete="off" maxlength="64" placeholder=" ">
                                    <p class="sf-field-error" id="sf-err-invoice_registration_number" data-sf-error-for="invoice_registration_number" hidden></p>
                                </div>
                                <div class="sf-field sf-float">
                                    <label for="sf-invoice-representative"><span data-sf-label-text data-label-default="{{ __('checkout.invoice_labels.representative') }}">{{ __('checkout.invoice_labels.representative') }}</span></label>
                                    <input id="sf-invoice-representative" name="invoice_representative" type="text" autocomplete="off" maxlength="255" placeholder=" ">
                                    <p class="sf-field-error" id="sf-err-invoice_representative" data-sf-error-for="invoice_representative" hidden></p>
                                </div>
                            </div>
                            {{-- Copies the street delivery fields; shown only while the delivery is a street address. --}}
                            <button class="sf-invoice-copy" type="button" data-sf-invoice-copy hidden>{{ __('storefront.checkout_invoice_copy') }}</button>
                            <div class="sf-field sf-float sf-float-up">
                                <label for="sf-invoice-country"><span data-sf-label-text>{{ __('storefront.checkout_invoice_country') }}</span></label>
                                <select id="sf-invoice-country" name="invoice_country" autocomplete="off">
                                    @foreach ($countries as $code => $countryName)
                                        <option value="{{ $code }}"{{ $code === $defaultCountry ? ' selected' : '' }}>{{ $countryName }}</option>
                                    @endforeach
                                </select>
                                <p class="sf-field-error" id="sf-err-invoice_country" data-sf-error-for="invoice_country" hidden></p>
                            </div>
                            <div class="sf-field-row sf-place-row">
                                <div class="sf-field sf-float">
                                    <label for="sf-invoice-city"><span data-sf-label-text>{{ __('storefront.checkout_city') }}</span></label>
                                    <input id="sf-invoice-city" name="invoice_city" type="text" autocomplete="off" maxlength="128" placeholder=" ">
                                    <p class="sf-field-error" id="sf-err-invoice_city" data-sf-error-for="invoice_city" hidden></p>
                                </div>
                                <div class="sf-field sf-float">
                                    <label for="sf-invoice-postal-code"><span data-sf-label-text>{{ __('storefront.checkout_postal_code') }}</span></label>
                                    <input id="sf-invoice-postal-code" name="invoice_postal_code" type="text" autocomplete="off" maxlength="32" placeholder=" ">
                                    <p class="sf-field-error" id="sf-err-invoice_postal_code" data-sf-error-for="invoice_postal_code" hidden></p>
                                </div>
                            </div>
                            <div class="sf-field sf-float">
                                <label for="sf-invoice-address-1"><span data-sf-label-text>{{ __('storefront.checkout_address_1') }}</span></label>
                                <input id="sf-invoice-address-1" name="invoice_address_line_1" type="text" autocomplete="off" maxlength="255" placeholder=" ">
                                <p class="sf-field-error" id="sf-err-invoice_address_line_1" data-sf-error-for="invoice_address_line_1" hidden></p>
                            </div>
                            <div class="sf-field sf-float">
                                <label for="sf-invoice-address-2"><span data-sf-label-text>{{ __('storefront.checkout_address_2') }}</span></label>
                                <input id="sf-invoice-address-2" name="invoice_address_line_2" type="text" autocomplete="off" maxlength="255" placeholder=" ">
                                <p class="sf-field-error" id="sf-err-invoice_address_line_2" data-sf-error-for="invoice_address_line_2" hidden></p>
                            </div>
                            <div class="sf-field">
                                <label for="sf-invoice-email"><span data-sf-label-text>{{ __('storefront.checkout_invoice_email') }}</span></label>
                                <input id="sf-invoice-email" name="invoice_email" type="email" autocomplete="off" maxlength="255">
                                <p class="sf-field-error" id="sf-err-invoice_email" data-sf-error-for="invoice_email" hidden></p>
                            </div>
                        </fieldset>
                    </div>
                @endif

                <fieldset class="sf-fieldset">
                    <legend class="sf-legend">{{ __('storefront.checkout_payment') }}</legend>
                    {{-- One card per payment method the registry offers (checkout form v2, section 8.3). Everything a descriptor supplies is escaped text; the logo is an <img> whose alt is the label. The first is preselected: a choice of method, not a consent. --}}
                    <div class="sf-pay-cards" role="radiogroup" aria-label="{{ __('storefront.checkout_payment') }}">
                        @foreach ($paymentCards as $card)
                            <label class="sf-pay-card">
                                <input type="radio" name="payment_method" value="{{ $card['code'] }}"{{ $loop->first ? ' checked' : '' }} data-place-order-label="{{ $card['placeOrderLabel'] }}"{!! $card['blockable'] ? ' data-blockable="1"' : '' !!}>
                                @if ($card['icon'] !== null)
                                    <img class="sf-pay-card-logo" src="{{ $card['icon'] }}" alt="{{ $card['label'] }}" loading="lazy" decoding="async">
                                @endif
                                <span>{{ $card['label'] }}</span>
                                @if ($card['description'] !== null)
                                    <small class="sf-pay-card-description">{{ $card['description'] }}</small>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    <p class="sf-field-error" id="sf-err-payment_method" data-sf-error-for="payment_method" hidden></p>
                </fieldset>

                {{-- The privacy notice (information, not a checkbox) and the terms checkbox (never ticked, required, BEFORE its sentence). Each is shown only when its link is set; the anchors are built escaped by the controller, never from merchant markup. --}}
                @if ($form['privacyHtml'] !== null)
                    <p class="sf-privacy-notice">{!! $form['privacyHtml'] !!}</p>
                @endif
                @if ($form['termsShown'])
                    <div class="sf-field sf-check sf-terms">
                        <input id="sf-terms" name="terms_accepted" type="checkbox" value="1" required aria-required="true" data-sf-describedby="sf-err-terms_accepted" data-sf-terms-version="{{ $form['termsVersion'] }}">
                        <label for="sf-terms">{!! $form['termsHtml'] !!} <span class="sf-required" aria-hidden="true">*</span></label>
                        <p class="sf-field-error" id="sf-err-terms_accepted" data-sf-error-for="terms_accepted" hidden></p>
                    </div>
                @endif

                <div class="sf-checkout-actions">
                    <button class="sf-place-order" type="submit" disabled data-sf-place-order>{{ __('storefront.checkout_place_order') }}</button>
                    <p class="sf-cart-status" role="status" aria-live="polite" data-sf-checkout-status></p>
                    <a class="sf-checkout-back" href="{{ route('storefront.cart', [], false) }}">{{ __('storefront.checkout_back_to_cart') }}</a>
                </div>
            </form>
        </div>
    </section>
@endsection
