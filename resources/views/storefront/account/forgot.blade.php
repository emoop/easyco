@extends('storefront.layout')

@section('content')
    <section class="sf-account" data-sf-account="forgot">
        @include('storefront.account.partials.head', ['title' => __('account_pages.forgot_title')])
        <p class="sf-account-intro">{{ __('account_pages.forgot_intro') }}</p>
        <form class="sf-account-form" data-sf-account-form novalidate>
            @include('storefront.account.partials.email', ['id' => 'sf-acc-email'])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.forgot_submit') }}</button>
        </form>
        <p class="sf-account-links">
            <a href="{{ route('storefront.account.login', [], false) }}">{{ __('account_pages.login_only_link') }}</a>
        </p>
    </section>
@endsection
