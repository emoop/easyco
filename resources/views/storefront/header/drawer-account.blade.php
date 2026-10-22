{{-- The drawer's account rows (MENU-4): "Wishlist" and "My profile", the same height as a top-level row. $rows holds only the types whose route exists. The label is ALWAYS "My profile" (HDR-3): the storefront is sessionless and its HTML is cached for everybody, so it must not depend on who is signed in; the account page itself shows the sign-in when needed. --}}
<ul class="sf-acc sf-acc-account">
    @foreach ($rows as $type)
        <li class="sf-acc-item">
            @if ($type === 'wishlist')
                <a class="sf-acc-link sf-acc-account-link" href="{{ route('storefront.wishlist', [], false) }}">
                    <svg class="sf-hdr-icon sf-acc-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>
                    <span>{{ __('storefront.wishlist') }}</span>
                </a>
            @else
                <a class="sf-acc-link sf-acc-account-link" href="{{ route('storefront.account', [], false) }}">
                    <svg class="sf-hdr-icon sf-acc-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    <span>{{ __('storefront.drawer_profile') }}</span>
                </a>
            @endif
        </li>
    @endforeach
</ul>
