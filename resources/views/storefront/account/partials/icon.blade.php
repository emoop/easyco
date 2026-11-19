{{-- ACC-3b-1: the four icons of the dashboard tiles, inline SVG (the shop's closed icon set: no library, no file). Decorative: the label next to it is the name. $name: orders | addresses | details | logout. Only the account pages use it: no cached page changes. --}}
<svg class="sf-acc-tile-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch ($name)
        @case ('orders')<path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>@break
        @case ('addresses')<path d="M12 21s-6-5.2-6-10a6 6 0 0 1 12 0c0 4.8-6 10-6 10z"/><path d="M12 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/>@break
        @case ('details')<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 21a8 8 0 0 1 16 0"/>@break
        @default<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4"/><path d="M19 12H9"/>
    @endswitch
</svg>
