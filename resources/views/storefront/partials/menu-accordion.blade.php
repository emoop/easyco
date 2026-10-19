{{-- A compiled menu as an ACCORDION (the drawer, D3): a parent has its own link and a button that opens its children (aria-expanded); dropdown children are a list, mega columns are
     headed groups, pictures are not printed here. Without the script every list is open. --}}
<ul class="sf-acc">
    @foreach ($nodes as $index => $node)
        <li class="sf-acc-item">
            @if ($node['children'] !== [])
                <div class="sf-acc-row">
                    <a class="sf-acc-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ $node['label'] }}</a>
                    <button class="sf-acc-toggle" type="button" aria-expanded="false" aria-controls="sf-acc-{{ $uid }}-{{ $index }}" data-sf-acc-toggle>
                        <span class="sf-sr-only">{{ __('storefront.subcategories') }}: {{ $node['label'] }}</span>
                    </button>
                </div>
                <div class="sf-acc-sub" id="sf-acc-{{ $uid }}-{{ $index }}">
                    @include('storefront.partials.menu', ['nodes' => $node['children']])
                </div>
            @else
                <a class="sf-acc-link" href="{{ $node['url'] }}"@if ($node['new_tab']) target="_blank" rel="noopener"@endif>{{ $node['label'] }}</a>
            @endif
        </li>
    @endforeach
</ul>
