<ul class="sf-menu">
    @foreach ($nodes as $node)
        <li class="sf-menu-item">
            <a class="sf-menu-link" href="{{ $node->url }}">{{ $node->name }}</a>
            @if ($node->children !== [])
                @include('storefront.partials.menu', ['nodes' => $node->children])
            @endif
        </li>
    @endforeach
</ul>
