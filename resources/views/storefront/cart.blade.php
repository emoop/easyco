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
        {{-- The empty cart's category links: rendered here (from the menu data the layout already has: no query) and MOVED into the empty state by the script; hidden otherwise. --}}
        @if (! empty($categories))
            <div class="sf-cart-empty-extras" data-sf-cart-empty-extras hidden>
                <p class="sf-cart-empty-categories-title">{{ __('storefront.home_categories') }}</p>
                <ul class="sf-cart-empty-categories">
                    @foreach ($categories as $node)
                        <li><a href="{{ $node->url }}">{{ $node->name }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
        <p class="sf-cart-continue">
            <a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.continue_shopping') }}</a>
        </p>
    </section>
@endsection
