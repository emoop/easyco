@extends('storefront.layout')

@section('content')
    <section class="sf-cart-page" data-sf-cart-page>
        <h1 class="sf-title">{{ __('storefront.cart_title') }}</h1>
        <p class="sf-cart-loading" data-sf-cart-loading>{{ __('storefront.cart_loading') }}</p>
        <noscript>
            <p class="sf-cart-noscript">{{ __('storefront.cart_noscript') }}</p>
        </noscript>
        <p class="sf-cart-status" role="status" aria-live="polite" data-sf-cart-status></p>
        <div class="sf-cart-content" data-sf-cart-content></div>
        <p class="sf-cart-continue">
            <a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.continue_shopping') }}</a>
        </p>
    </section>
@endsection
