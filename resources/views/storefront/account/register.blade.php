@extends('storefront.layout')

@section('content')
    <section class="sf-account" data-sf-account="register">
        @include('storefront.account.partials.head', ['title' => __('account_pages.register_title')])
        <form class="sf-account-form" data-sf-account-form novalidate>
            @include('storefront.account.partials.email', ['id' => 'sf-acc-email'])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-password', 'name' => 'password', 'label' => __('account_pages.password'), 'autocomplete' => 'new-password', 'hint' => __('account_pages.password_hint')])
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.register_submit') }}</button>
        </form>
        <p class="sf-account-links">
            <a href="{{ route('storefront.account.login', [], false) }}">{{ __('account_pages.login_link') }}</a>
        </p>
    </section>
@endsection
