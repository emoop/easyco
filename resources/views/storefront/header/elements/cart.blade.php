{{-- The bag icon is inline SVG (no icon library) and decorative: the link accessible name is its text, plus the count when there is one (filled by the script, cache-safe). --}}
<a class="sf-cart" href="{{ route('storefront.cart', [], false) }}" data-sf-cart-link>
    <svg class="sf-cart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
    <span class="sf-cart-label{{ $settings['style'] === 'icon' ? ' sf-sr-only' : '' }}">{{ __('storefront.cart') }}</span>
    <span class="sf-cart-count" data-sf-cart-count hidden></span>
</a>
