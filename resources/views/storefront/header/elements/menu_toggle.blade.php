{{-- A bare hamburger (three thin lines, inline SVG, decorative); the word stays as the accessible name, visible only when the element asks for it. --}}
<button class="sf-menu-toggle" type="button" aria-controls="sf-drawer" aria-expanded="false" data-sf-menu-toggle>
    <svg class="sf-menu-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
    <span class="{{ $settings['label_visible'] ? 'sf-menu-toggle-label' : 'sf-sr-only' }}">{{ __('storefront.menu_main') }}</span>
</button>
