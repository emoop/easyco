@extends('storefront.account.area.layout')

{{-- ACC-3b-2: «Данни»: the email (a change is a request: the current password, then a mail to the NEW address) and the password. The writes go to the API from storefront-account.js. --}}
@section('account')
    <section class="sf-acc-block" aria-labelledby="sf-acc-email-title">
        <h2 class="sf-section-title" id="sf-acc-email-title">{{ __('account_pages.details_email_title') }}</h2>
        <p class="sf-acc-current"><span class="sf-acc-current-label">{{ __('account_pages.details_email_current') }}:</span> <strong>{{ $email }}</strong></p>
        <form class="sf-account-form" data-sf-account-email-form novalidate>
            @include('storefront.account.partials.email', ['id' => 'sf-acc-new-email', 'label' => __('account_pages.details_new_email'), 'autocomplete' => 'off'])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-email-password', 'name' => 'current_password', 'label' => __('account_pages.details_current_password'), 'autocomplete' => 'current-password', 'hint' => __('account_pages.details_email_hint')])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.details_email_submit') }}</button>
        </form>
    </section>

    <section class="sf-acc-block" aria-labelledby="sf-acc-password-title">
        <h2 class="sf-section-title" id="sf-acc-password-title">{{ __('account_pages.details_password_title') }}</h2>
        <form class="sf-account-form" data-sf-account-password-form novalidate>
            @include('storefront.account.partials.password', ['id' => 'sf-acc-current-password', 'name' => 'current_password', 'label' => __('account_pages.details_current_password'), 'autocomplete' => 'current-password'])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-new-password', 'name' => 'password', 'label' => __('account_pages.new_password'), 'autocomplete' => 'new-password', 'hint' => __('account_pages.password_hint')])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-new-password-repeat', 'name' => 'password_confirmation', 'label' => __('account_pages.new_password_repeat'), 'autocomplete' => 'new-password'])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.details_password_submit') }}</button>
        </form>
    </section>
@endsection
