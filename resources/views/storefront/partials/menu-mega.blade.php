{{-- The panel of a `mega` item (D3, MENU-2): the first-level items are the COLUMNS (a heading, or a link used as the heading), their children the links under them, and the
     item's HTML block sits to the right. The block was sanitized when it was saved and again when the menus were compiled and read, so it is the one place printed raw. --}}
<div class="sf-mega">
    <div class="sf-mega-cols" data-cols="{{ min(count($item['children']), 6) }}">
        @foreach ($item['children'] as $column)
            <div class="sf-mega-col">
                @if ($column['type'] === 'link')
                    <p class="sf-mega-heading"><a class="sf-mega-heading-link" href="{{ $column['url'] }}"@if ($column['new_tab']) target="_blank" rel="noopener"@endif>{{ $column['label'] }}@include('storefront.partials.menu-badge', ['badge' => $column['badge']])</a></p>
                @else
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
