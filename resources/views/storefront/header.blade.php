{{-- THE HEADER (storefront-customization-design.md 4, D2): ONE <header> holding the desktop layout and the mobile layout (CSS shows one per breakpoint, no user-agent sniffing),
     the drawer the menu_toggle opens and, when a search element asks for it, the search row. Everything is walked from the VALIDATED layout ($header, prepared by
     HeaderView: elements whose feature does not exist are already gone). Row heights and the logo height are CSS variables of the token stylesheet, never inline styles. --}}
@php($desktop = $header['desktop'])
@php($mobile = $header['mobile'])
<header class="sf-header sf-hdr{{ $desktop['sticky'] ? ' sf-hdr-d-sticky' : '' }}{{ $mobile['sticky'] ? ' sf-hdr-m-sticky' : '' }}" data-sf-header>
    <div class="sf-hdr-layout sf-hdr-desktop">
        @foreach (['top', 'main', 'bottom'] as $rowName)
            @if ($desktop['rows'][$rowName]['visible'])
                @include('storefront.header.row', ['row' => $desktop['rows'][$rowName], 'rowName' => $rowName, 'context' => 'desktop'])
            @endif
        @endforeach
    </div>
    <div class="sf-hdr-layout sf-hdr-mobile">
        @foreach (['top', 'main'] as $rowName)
            @if ($mobile['rows'][$rowName]['visible'])
                @include('storefront.header.row', ['row' => $mobile['rows'][$rowName], 'rowName' => $rowName, 'context' => 'mobile'])
            @endif
        @endforeach
    </div>
    @if ($header['needs_search_row'])
        @include('storefront.header.search-row')
    @endif
    @if ($header['needs_drawer'])
        @include('storefront.header.drawer', ['mobile' => $mobile])
    @endif
</header>
