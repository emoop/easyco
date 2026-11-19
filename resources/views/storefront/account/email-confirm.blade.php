@extends('storefront.layout')

@section('content')
    {{-- A GET changes nothing (mail scanners open links): the button POSTs. The signed parameters and the token are in the ADDRESS only; the HTML never holds them. --}}
    <section class="sf-account" data-sf-account="email-confirm">
        @include('storefront.account.partials.head', ['title' => __('account_pages.confirm_title')])
        <p class="sf-account-intro" data-sf-account-intro>{{ __('account_pages.confirm_intro') }}</p>
        <form class="sf-account-form" data-sf-account-form novalidate>
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.confirm_button') }}</button>
        </form>
        <p class="sf-account-links" data-sf-account-done-links hidden>
            <a href="{{ route('storefront.account.login', [], false) }}">{{ __('account_pages.login_only_link') }}</a>
        </p>
        <p class="sf-account-links" data-sf-account-invalid-links hidden>
            <a href="{{ route('storefront.account', [], false) }}">{{ __('account_pages.back_to_account_link') }}</a>
        </p>
    </section>
@endsection
