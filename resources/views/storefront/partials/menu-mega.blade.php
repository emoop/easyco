{{-- The panel of a `mega` item (D3): its columns of links and up to two pictures with an optional link and caption. It is in the page from the start (opening it asks for nothing);
     the pictures carry their address in data- attributes and the script sets src / srcset the first time the panel opens. Without the script a <noscript> copy loads lazily. --}}
@php
    $columns = array_values(array_filter($item['children'], fn (array $child): bool => $child['type'] === 'column'));
    $pictures = [];
    foreach ($columns as $column) {
        foreach ($column['children'] as $child) {
            if ($child['type'] === 'image') {
                $pictures[] = $child;
            }
        }
    }
    $srcset = fn (array $picture): string => implode(', ', array_map(fn (array $row): string => $row['url'].' '.$row['width'].'w', $picture['srcset']));
    $sizes = \App\Storefront\Menu\MenuCompiler::IMAGE_SIZES;
@endphp
<div class="sf-mega">
    <div class="sf-mega-cols">
        @foreach ($columns as $column)
            <div class="sf-mega-col">
                @if ($column['label'] !== null)
                    <p class="sf-mega-heading">{{ $column['label'] }}</p>
                @endif
                @include('storefront.partials.menu', ['nodes' => array_values(array_filter($column['children'], fn (array $child): bool => $child['type'] === 'link'))])
            </div>
        @endforeach
    </div>
    @if ($pictures !== [])
        <div class="sf-mega-media">
            @foreach ($pictures as $picture)
                <figure class="sf-mega-figure">
                    @if ($picture['url'] !== null)
                        <a class="sf-mega-figure-link" href="{{ $picture['url'] }}"@if ($picture['new_tab']) target="_blank" rel="noopener"@endif>
                    @endif
                    <img class="sf-mega-img" alt="{{ $picture['alt'] }}" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" sizes="{{ $sizes }}" loading="lazy" decoding="async" data-sf-src="{{ $picture['src'] }}"@if ($picture['srcset'] !== []) data-sf-srcset="{{ $srcset($picture) }}"@endif>
                    <noscript><img class="sf-mega-img" alt="{{ $picture['alt'] }}" width="{{ $picture['width'] }}" height="{{ $picture['height'] }}" src="{{ $picture['src'] }}"@if ($picture['srcset'] !== []) srcset="{{ $srcset($picture) }}" sizes="{{ $sizes }}"@endif loading="lazy"></noscript>
                    @if ($picture['label'] !== null)
                        <figcaption class="sf-mega-caption">{{ $picture['label'] }}</figcaption>
                    @endif
                    @if ($picture['url'] !== null)
                        </a>
                    @endif
                </figure>
            @endforeach
        </div>
    @endif
</div>
