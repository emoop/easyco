{{-- A compiled menu as an ACCORDION (the drawer, D3, MENU-2): a parent has its own link and a button that opens its children in place (aria-expanded). A dropdown item lists its
     links (a second level is an indented list); a MEGA item shows its columns as SECTIONS one under another (the heading as a small title, its links under it), with no second
     accordion and no HTML block. Without the script every list is open. --}}
<ul class="sf-acc">
    @foreach ($nodes as $index => $node)
        <li class="sf-acc-item">
            @if ($node['children'] !== [])
                <div class="sf-acc-row">
                    <a class="sf-acc-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ $node['label'] }}@include('storefront.partials.menu-badge', ['badge' => $node['badge']])</a>
                    <button class="sf-acc-toggle" type="button" aria-expanded="false" aria-controls="sf-acc-{{ $uid }}-{{ $index }}" data-sf-acc-toggle>
                        <span class="sf-sr-only">{{ __('storefront.subcategories') }}: {{ $node['label'] }}</span>
                    </button>
                </div>
                <div class="sf-acc-sub" id="sf-acc-{{ $uid }}-{{ $index }}">
                    @if ($node['submenu'] === 'mega')
                        @foreach ($node['children'] as $column)
                            <div class="sf-acc-section">
                                @if ($column['type'] === 'link')
                                    <p class="sf-mega-heading"><a class="sf-mega-heading-link" href="{{ $column['url'] }}"@if ($column['new_tab']) target="_blank" rel="noopener"@endif>{{ $column['label'] }}</a></p>
                                @else
                                    <p class="sf-mega-heading">{{ $column['label'] }}</p>
                                @endif
                                @if ($column['children'] !== [])
                                    @include('storefront.partials.menu', ['nodes' => $column['children']])
                                @endif
                            </div>
                        @endforeach
                    @else
                        @include('storefront.partials.menu', ['nodes' => $node['children']])
                    @endif
                </div>
            @else
                <a class="sf-acc-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ $node['label'] }}@include('storefront.partials.menu-badge', ['badge' => $node['badge']])</a>
            @endif
        </li>
    @endforeach
</ul>
