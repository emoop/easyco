@extends('storefront.layout')

@section('content')
    {{-- The token and the email are in the ADDRESS only: the script reads them from there and sends them in the POST body; the HTML never holds them. --}}
    <section class="sf-account" data-sf-account="reset">
        @include('storefront.account.partials.head', ['title' => __('account_pages.reset_title')])
        <form class="sf-account-form" data-sf-account-form novalidate>
            @include('storefront.account.partials.password', ['id' => 'sf-acc-password', 'name' => 'password', 'label' => __('account_pages.new_password'), 'autocomplete' => 'new-password', 'hint' => __('account_pages.password_hint')])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-password2', 'name' => 'password_confirmation', 'label' => __('account_pages.new_password_repeat'), 'autocomplete' => 'new-password'])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.reset_submit') }}</button>
        </form>
        <p class="sf-account-links" data-sf-account-invalid-links hidden>
            <a href="{{ route('storefront.account.forgot', [], false) }}">{{ __('account_pages.forgot_again_link') }}</a>
        </p>
    </section>
@endsection
