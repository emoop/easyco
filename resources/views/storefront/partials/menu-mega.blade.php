{{-- The panel of a `mega` item (MENU-3): `mega_columns` equal COLUMNS, each an optional heading (a plain text) with its links under it; an empty column prints an empty box and keeps its
     place. The item's HTML block sits to the right, as before. The block was sanitized when it was saved and again when the menus were compiled and read, so it is the one place printed raw. --}}
<div class="sf-mega">
    <div class="sf-mega-cols" data-cols="{{ min(count($item['children']), 4) }}">
        @foreach ($item['children'] as $column)
            <div class="sf-mega-col">
                @if ($column['label'] !== null)
                    <p class="sf-mega-heading">{{ $column['label'] }}</p>
                @endif
                @if ($column['children'] !== [])
                    @include('storefront.partials.menu', ['nodes' => $column['children']])
                @endif
            </div>
        @endforeach
    </div>
    @if ($item['html'] !== null)
        <div class="sf-mega-media sf-mega-html">{!! $item['html'] !!}</div>
    @endif
</div>
