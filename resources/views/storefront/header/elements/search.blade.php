@if ($settings['mode'] === 'field')
    <form class="sf-search-field" role="search" method="get" action="{{ route('storefront.search', [], false) }}">
        <label class="sf-sr-only" for="sf-search-{{ $uid }}">{{ __('storefront.search') }}</label>
        <input class="sf-search-input" id="sf-search-{{ $uid }}" name="q" type="search" maxlength="100" placeholder="{{ __('storefront.search_placeholder') }}" autocomplete="off">
        <button class="sf-search-submit" type="submit">{{ __('storefront.search_submit') }}</button>
    </form>
@else
    <button class="sf-hdr-icon-btn" type="button" aria-controls="sf-search-row" aria-expanded="false" data-sf-search-toggle>
        <svg class="sf-hdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15z"/><path d="M16 16l5 5"/></svg>
        <span class="sf-sr-only">{{ __('storefront.search') }}</span>
    </button>
@endif
