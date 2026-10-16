@if (! empty($categories))
    @if ($context === 'drawer')
        <nav class="sf-hdr-menu sf-hdr-menu-drawer" aria-label="{{ __('storefront.menu') }}">
            @include('storefront.partials.menu-accordion', ['nodes' => $categories, 'uid' => $uid])
        </nav>
    @elseif ($settings['style'] === 'links')
        <nav class="sf-hdr-menu sf-hdr-menu-links" id="sf-menu-{{ $uid }}" aria-label="{{ __('storefront.menu') }}">
            @include('storefront.partials.menu', ['nodes' => $categories])
        </nav>
    @else
        {{-- `dropdown` (and `mega`, which renders as a dropdown until the menus stage, D3): one trigger, the tree in a panel on hover and focus. --}}
        <nav class="sf-hdr-menu sf-hdr-menu-dropdown" id="sf-menu-{{ $uid }}" aria-label="{{ __('storefront.menu') }}">
            <button class="sf-hdr-menu-trigger" type="button" aria-haspopup="true">{{ __('storefront.menu') }}</button>
            <div class="sf-hdr-menu-panel">
                @include('storefront.partials.menu', ['nodes' => $categories])
            </div>
        </nav>
    @endif
@endif
