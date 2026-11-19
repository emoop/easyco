@extends('storefront.layout')

@section('content')
    {{-- ACC-3a placeholder: ACC-3b replaces this body (dashboard, orders, addresses). --}}
    <section class="sf-account" data-sf-account="home">
        @include('storefront.account.partials.head', ['title' => __('account_pages.title')])
        <p class="sf-account-greeting">{{ __('account_pages.home_greeting', ['email' => $email]) }}</p>
        <form class="sf-account-form" data-sf-account-logout-form>
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.logout') }}</button>
        </form>
    </section>
@endsection
