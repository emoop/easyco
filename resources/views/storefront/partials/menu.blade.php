{{-- A compiled menu as nested lists (D3, MENU-2): links with their badge, and a heading as a titled group (the HTML block of a mega item is printed by menu-mega, never here).
     Labels are escaped. --}}
<ul class="sf-menu">
    @foreach ($nodes as $node)
        @if ($node['type'] === 'link')
            <li class="sf-menu-item">
                <a class="sf-menu-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ $node['label'] }}@include('storefront.partials.menu-badge', ['badge' => $node['badge']])</a>
                @if ($node['children'] !== [])
                    @include('storefront.partials.menu', ['nodes' => $node['children']])
                @endif
            </li>
        @else
            <li class="sf-menu-item sf-menu-column">
                <p class="sf-mega-heading">{{ $node['label'] }}</p>
                @include('storefront.partials.menu', ['nodes' => $node['children']])
            </li>
        @endif
    @endforeach
</ul>
