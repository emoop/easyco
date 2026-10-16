{{-- The category tree as an ACCORDION (the drawer): a parent has its own link and a button that opens its children (aria-expanded). Without the script every list is open. --}}
<ul class="sf-acc">
    @foreach ($nodes as $index => $node)
        <li class="sf-acc-item">
            @if ($node->children !== [])
                <div class="sf-acc-row">
                    <a class="sf-acc-link" href="{{ $node->url }}">{{ $node->name }}</a>
                    <button class="sf-acc-toggle" type="button" aria-expanded="false" aria-controls="sf-acc-{{ $uid }}-{{ $index }}" data-sf-acc-toggle>
                        <span class="sf-sr-only">{{ __('storefront.subcategories') }}: {{ $node->name }}</span>
                    </button>
                </div>
                <div class="sf-acc-sub" id="sf-acc-{{ $uid }}-{{ $index }}">
                    @include('storefront.partials.menu', ['nodes' => $node->children])
                </div>
            @else
                <a class="sf-acc-link" href="{{ $node->url }}">{{ $node->name }}</a>
            @endif
        </li>
    @endforeach
</ul>
