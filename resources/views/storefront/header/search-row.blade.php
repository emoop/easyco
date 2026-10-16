{{-- The search row (element `search`, mode icon_row): a full-width row under the header; the script shows it, moves focus into the field and closes it on Esc. Without the script it is a plain form. --}}
<form class="sf-search-row" id="sf-search-row" role="search" method="get" action="{{ route('storefront.search', [], false) }}" data-sf-search-row hidden>
    <div class="sf-search-row-inner">
        <label class="sf-sr-only" for="sf-search-row-input">{{ __('storefront.search') }}</label>
        <input class="sf-search-input" id="sf-search-row-input" name="q" type="search" maxlength="100" placeholder="{{ __('storefront.search_placeholder') }}" autocomplete="off">
        <button class="sf-search-submit" type="submit">{{ __('storefront.search_submit') }}</button>
    </div>
</form>
