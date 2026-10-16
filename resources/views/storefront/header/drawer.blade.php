{{-- The drawer (opened by the menu_toggle element): slides in from the chosen side over an overlay. Closed, it is invisible and out of the tab order; open, focus moves in and
     is trapped, Esc / the overlay / the close button close it and focus returns to the toggle. Without the script it is a plain block under the header (menus stay reachable). --}}
<div class="sf-drawer sf-drawer-{{ $mobile['drawer_side'] }}" id="sf-drawer" data-sf-drawer>
    <div class="sf-drawer-overlay" data-sf-drawer-close></div>
    <div class="sf-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="sf-drawer-title" tabindex="-1">
        <div class="sf-drawer-head">
            <p class="sf-drawer-title" id="sf-drawer-title">{{ __('storefront.menu') }}</p>
            <button class="sf-drawer-close" type="button" data-sf-drawer-close>
                <svg class="sf-close-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>
                <span class="sf-sr-only">{{ __('storefront.drawer_close') }}</span>
            </button>
        </div>
        <div class="sf-drawer-body">
            @foreach ($mobile['drawer'] as $index => $element)
                @include($element['view'], ['settings' => $element['settings'], 'context' => 'drawer', 'uid' => 'drawer-'.$index])
            @endforeach
        </div>
    </div>
</div>
