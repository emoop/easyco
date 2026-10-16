<a class="sf-hdr-link" href="{{ route('storefront.wishlist', [], false) }}">
    <svg class="sf-hdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>
    <span class="{{ $settings['style'] === 'icon_label' ? 'sf-hdr-link-label' : 'sf-sr-only' }}">{{ __('storefront.wishlist') }}</span>
    @if ($settings['show_count'])
        <span class="sf-cart-count" data-sf-wishlist-count hidden></span>
    @endif
</a>
