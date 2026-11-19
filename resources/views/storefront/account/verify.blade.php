@extends('storefront.layout')

@section('content')
    {{-- A GET changes nothing (mail scanners open links): the button POSTs. The signed parameters are in the ADDRESS only; the HTML never holds them. --}}
    <section class="sf-account" data-sf-account="verify">
        @include('storefront.account.partials.head', ['title' => __('account_pages.verify_title')])
        <p class="sf-account-intro" data-sf-account-intro>{{ __('account_pages.verify_intro') }}</p>
        <form class="sf-account-form" data-sf-account-form novalidate>
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.verify_button') }}</button>
        </form>
        {{-- an invalid or expired link: ask for a new mail (the address is not in the link, so it is asked here) --}}
        <form class="sf-account-form" data-sf-account-resend-form novalidate hidden>
            @include('storefront.account.partials.email', ['id' => 'sf-acc-email'])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.resend_button') }}</button>
        </form>
        <p class="sf-account-links" data-sf-account-done-links hidden>
            <a href="{{ route('storefront.account.login', [], false) }}">{{ __('account_pages.login_only_link') }}</a>
        </p>
    </section>
@endsection
