{{-- The `menu` element (D3): a menu of the shop (`source` = its key) or, for `categories`, the shown category tree. Both are the same compiled shape, printed by the same markup.
     `links` (default) is the bar: every item is a real link; an item with a submenu adds a SEPARATE chevron button (the disclosure pattern: aria-expanded, aria-controls) that opens its
     dropdown or mega panel; what does not fit its column moves into "More" (script). `dropdown` is one "Menu" trigger with the whole tree in a panel. In the drawer it is an accordion. --}}
@php
    $items = $menu ?? \App\Storefront\Menu\MenuView::fromCategories($categories ?? []);
    $label = $menu === null ? __('storefront.menu') : __('storefront.menu_main');
@endphp
@if ($items !== [])
    @if ($context === 'drawer')
        <nav class="sf-hdr-menu sf-hdr-menu-drawer" aria-label="{{ $label }}">
            @include('storefront.partials.menu-accordion', ['nodes' => $items, 'uid' => $uid])
        </nav>
    @elseif ($settings['style'] === 'dropdown')
        <nav class="sf-hdr-menu sf-hdr-menu-dropdown" id="sf-menu-{{ $uid }}" aria-label="{{ $label }}">
            <button class="sf-hdr-menu-trigger" type="button" aria-haspopup="true">{{ $label }}</button>
            <div class="sf-hdr-menu-panel">
                @include('storefront.partials.menu', ['nodes' => $items])
            </div>
        </nav>
    @else
        <nav class="sf-hdr-menu sf-hdr-menu-links" id="sf-menu-{{ $uid }}" aria-label="{{ $label }}" data-sf-menubar>
            <ul class="sf-bar">
                @foreach ($items as $index => $item)
                    @php($sub = $item['submenu'])
                    <li class="sf-bar-item{{ $sub !== 'none' ? ' sf-bar-has-sub sf-bar-'.$sub : '' }}" data-sf-bar-item>
                        <a class="sf-bar-link" href="{{ $item['url'] }}"@if ($item['new_tab']) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a>
                        @if ($sub !== 'none')
                            <button class="sf-bar-chevron" type="button" aria-expanded="false" aria-controls="sf-sub-{{ $uid }}-{{ $index }}" data-sf-sub-toggle>
                                <span class="sf-sr-only">{{ __('storefront.subcategories') }}: {{ $item['label'] }}</span>
                            </button>
                            <div class="sf-sub sf-sub-{{ $sub }}" id="sf-sub-{{ $uid }}-{{ $index }}" data-sf-sub>
                                @if ($sub === 'mega')
                                    @include('storefront.partials.menu-mega', ['item' => $item])
                                @else
                                    @include('storefront.partials.menu', ['nodes' => $item['children']])
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
                <li class="sf-bar-item sf-bar-has-sub sf-bar-more" data-sf-bar-item data-sf-more hidden>
                    <button class="sf-bar-more-btn" type="button" aria-expanded="false" aria-controls="sf-more-{{ $uid }}" data-sf-sub-toggle>{{ __('storefront.more') }}</button>
                    <div class="sf-sub sf-sub-dropdown sf-more-panel" id="sf-more-{{ $uid }}" data-sf-sub>
                        <ul class="sf-menu" data-sf-more-list></ul>
                    </div>
                </li>
            </ul>
        </nav>
    @endif
@endif
