{{-- HP-3, home section `tiles`: a row of tiles, each ONE link wrapping a picture and its caption below it. The picture is one of our media renditions (srcset + sizes, the width and height of its shape: no layout shift),
     the first row is loaded at once, the rest lazily; alt is empty because the caption names the link. Every class is from a closed list (columns, phone columns, gap, shape, radius, caption size); no inline style,
     no script, no heading. $item['tiles'] = {class, tiles[{label, url, src, srcset[{url, width}], width, height}], sizes, width, height, eager, caption}. --}}
@php $t = $item['tiles']; @endphp
<ul class="{{ $t['class'] }}">
    @foreach ($t['tiles'] as $tile)
        <li class="sf-hp-tile">
            <a class="sf-hp-tile-link" href="{{ $tile['url'] }}">
                <img class="sf-hp-tile-img" src="{{ $tile['src'] }}"@if ($tile['srcset'] !== []) srcset="{{ implode(', ', array_map(static fn (array $row): string => $row['url'].' '.$row['width'].'w', $tile['srcset'])) }}" sizes="{{ $t['sizes'] }}"@endif width="{{ $t['width'] }}" height="{{ $t['height'] }}" alt=""@if ($loop->index >= $t['eager']) loading="lazy"@endif decoding="async">
                <span class="sf-hp-tile-cap">{{ $tile['label'] }}</span>
            </a>
        </li>
    @endforeach
</ul>
