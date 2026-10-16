<a class="sf-hdr-link" href="{{ route('storefront.account', [], false) }}">
    <svg class="sf-hdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
    <span class="{{ $settings['style'] === 'icon_label' ? 'sf-hdr-link-label' : 'sf-sr-only' }}">{{ __('storefront.account') }}</span>
</a>
