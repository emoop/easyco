{{-- The drawer's top bar search (MENU-4): input + magnifier, full width; printed only when the search route exists (the caller checks). It also names the dialog for screen readers. --}}
<form class="sf-drawer-search" role="search" method="get" action="{{ route('storefront.search', [], false) }}">
    <span class="sf-sr-only" id="sf-drawer-title">{{ __('storefront.menu_main') }}</span>
    <label class="sf-sr-only" for="sf-drawer-q">{{ __('storefront.search') }}</label>
    <input class="sf-drawer-search-input" id="sf-drawer-q" name="q" type="search" maxlength="100" placeholder="{{ __('storefront.drawer_search_placeholder') }}" autocomplete="off">
    <button class="sf-drawer-search-btn" type="submit">
        <svg class="sf-hdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15z"/><path d="M16 16l5 5"/></svg>
        <span class="sf-sr-only">{{ __('storefront.search_submit') }}</span>
    </button>
</form>
