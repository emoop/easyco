@extends('storefront.account.area.layout')

{{-- ACC-3b-2: the street-address form (new / edit). Pick-up addresses have no edit. The labels and the region rule are the checkout's own; the server (AddressController::validatedInput) stays the authority: its 422 comes back under each field. $address: null (new) or HER street Address. --}}
@php
    $value = fn (string $field): string => (string) ($address === null ? '' : match ($field) {
        'first' => $address->recipientFirstName(),
        'last' => $address->recipientLastName(),
        'phone' => $address->phone(),
        'city' => $address->city(),
        'postal' => $address->postalCode(),
        'line1' => $address->addressLine1(),
        'line2' => $address->addressLine2(),
        default => $address->region(),
    });
@endphp

@section('account')
    <form class="sf-account-form sf-acc-address-form" data-sf-account-address-form
          data-address-id="{{ $address?->id() }}"
          data-region-policy="{{ json_encode($regionPolicy, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT) }}" novalidate>
        <div class="sf-field-row">
            <div class="sf-field sf-float">
                <label for="sf-addr-first">{{ __('storefront.checkout_first_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                <input id="sf-addr-first" name="recipient_first_name" type="text" autocomplete="given-name" required aria-required="true" maxlength="100" placeholder=" " value="{{ $value('first') }}">
                <p class="sf-field-error" data-sf-error-for="recipient_first_name" hidden></p>
            </div>
            <div class="sf-field sf-float">
                <label for="sf-addr-last">{{ __('storefront.checkout_last_name') }} <span class="sf-required" aria-hidden="true">*</span></label>
                <input id="sf-addr-last" name="recipient_last_name" type="text" autocomplete="family-name" required aria-required="true" maxlength="100" placeholder=" " value="{{ $value('last') }}">
                <p class="sf-field-error" data-sf-error-for="recipient_last_name" hidden></p>
            </div>
        </div>
        <div class="sf-field sf-float">
            <label for="sf-addr-phone">{{ __('storefront.checkout_phone') }} <span class="sf-required" aria-hidden="true">*</span></label>
            <input id="sf-addr-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" required aria-required="true" maxlength="32" placeholder=" " value="{{ $value('phone') }}">
            <p class="sf-field-error" data-sf-error-for="phone" hidden></p>
        </div>
        <div class="sf-field sf-float sf-float-up">
            <label for="sf-addr-country">{{ __('storefront.checkout_country') }} <span class="sf-required" aria-hidden="true">*</span></label>
            <select id="sf-addr-country" name="country" autocomplete="country" required aria-required="true">
                @foreach ($countries as $code => $countryName)
                    <option value="{{ $code }}"{{ $code === $country ? ' selected' : '' }}>{{ $countryName }}</option>
                @endforeach
            </select>
            <p class="sf-field-error" data-sf-error-for="country" hidden></p>
        </div>
        <div class="sf-field-row sf-place-row">
            <div class="sf-field sf-float">
                <label for="sf-addr-city">{{ __('storefront.checkout_city') }} <span class="sf-required" aria-hidden="true">*</span></label>
                <input id="sf-addr-city" name="city" type="text" autocomplete="address-level2" required aria-required="true" maxlength="255" placeholder=" " value="{{ $value('city') }}">
                <p class="sf-field-error" data-sf-error-for="city" hidden></p>
            </div>
            <div class="sf-field sf-float">
                <label for="sf-addr-postal">{{ __('storefront.checkout_postal_code_optional') }}</label>
                <input id="sf-addr-postal" name="postal_code" type="text" autocomplete="postal-code" inputmode="numeric" maxlength="255" placeholder=" " value="{{ $value('postal') }}">
                <p class="sf-field-error" data-sf-error-for="postal_code" hidden></p>
            </div>
        </div>
        <div class="sf-field sf-float">
            <label for="sf-addr-line1">{{ __('storefront.checkout_address_1') }} <span class="sf-required" aria-hidden="true">*</span></label>
            <input id="sf-addr-line1" name="address_line_1" type="text" autocomplete="address-line1" required aria-required="true" maxlength="255" placeholder=" " value="{{ $value('line1') }}">
            <p class="sf-field-error" data-sf-error-for="address_line_1" hidden></p>
        </div>
        <div class="sf-field sf-float">
            <label for="sf-addr-line2">{{ __('storefront.checkout_address_2') }}</label>
            <input id="sf-addr-line2" name="address_line_2" type="text" autocomplete="address-line2" maxlength="255" placeholder=" " value="{{ $value('line2') }}">
            <p class="sf-field-error" data-sf-error-for="address_line_2" hidden></p>
        </div>
        {{-- optional, required or hidden per country (the checkout's region setting): the script follows the country --}}
        <div class="sf-field sf-float" data-sf-region-field>
            <label for="sf-addr-region">{{ __('storefront.checkout_region') }}</label>
            <input id="sf-addr-region" name="region" type="text" autocomplete="address-level1" maxlength="100" placeholder=" " value="{{ $value('region') }}">
            <p class="sf-field-error" data-sf-error-for="region" hidden></p>
        </div>
        <p class="sf-field-error" data-sf-error-for="address_limit" hidden></p>

        <div class="sf-acc-form-actions">
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.address_save') }}</button>
            <a class="sf-acc-cancel" href="{{ route('storefront.account.addresses', [], false) }}">{{ __('account_pages.address_cancel') }}</a>
        </div>
    </form>
@endsection
