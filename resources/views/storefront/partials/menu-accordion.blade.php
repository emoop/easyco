{{-- A compiled menu as an ACCORDION (the drawer, D3, MENU-2): a parent has its own link and a button that opens its children in place (aria-expanded). A dropdown item lists its
     links (a second level is an indented list); a MEGA item shows its columns as SECTIONS one under another (the heading, when there is one, as a small title, its links under it; an empty column is not printed), with no second
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
                            @if ($column['children'] !== [])
                                <div class="sf-acc-section">
                                    @if ($column['label'] !== null)
                                        <p class="sf-mega-heading">{{ $column['label'] }}</p>
                                    @endif
                                    @include('storefront.partials.menu', ['nodes' => $column['children']])
                                </div>
                            @endif
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
