{{-- A compiled menu as nested lists (D3): links, and a column as a headed group (the pictures of a mega panel are printed by menu-mega, never here). Labels are escaped; a "see all" link
     has no label of its own. --}}
<ul class="sf-menu">
    @foreach ($nodes as $node)
        @if ($node['type'] === 'link')
            <li class="sf-menu-item">
                <a class="sf-menu-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ ($node['see_all'] ?? false) ? __('storefront.see_all') : $node['label'] }}</a>
                @if ($node['children'] !== [])
                    @include('storefront.partials.menu', ['nodes' => $node['children']])
                @endif
            </li>
        @elseif ($node['type'] === 'column')
            <li class="sf-menu-item sf-menu-column">
                @if ($node['label'] !== null)
                    <p class="sf-mega-heading">{{ $node['label'] }}</p>
                @endif
                @include('storefront.partials.menu', ['nodes' => array_values(array_filter($node['children'], fn (array $child): bool => $child['type'] === 'link'))])
            </li>
        @endif
    @endforeach
</ul>
