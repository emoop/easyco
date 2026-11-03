{{-- HP-5, home section `carousel`: builds the items and hands them to the SHARED partial (storefront.partials.carousel). products = the SAME card partial as the catalog (read at request time by HomeView::fill);
     blocks = the compiled, sanitised HTML; images / brands = our own pictures (srcset + sizes, width and height: no layout shift, lazy), a link only when set (a brand has none). Every value is escaped here; no inline
     style, no script. $item['carousel'] = {kind, class, label, arrows, cards, blocks, pictures, sizes}. --}}
@php
    $c = $item['carousel'];
    $items = [];

    foreach ($c['cards'] as $n => $card) {
        // the first two cards are loaded at once, as in the catalog's grid: the card is the SAME markup
        $items[] = ['class' => 'sf-car-card', 'html' => view('storefront.partials.card', ['card' => $card, 'eager' => $n < 2])->render()];
    }

    foreach ($c['blocks'] as $html) {
        $items[] = ['class' => 'sf-car-block', 'html' => $html];
    }

    foreach ($c['pictures'] as $picture) {
        $srcset = $picture['srcset'] === [] ? '' : ' srcset="'.e(implode(', ', array_map(static fn (array $row): string => $row['url'].' '.$row['width'].'w', $picture['srcset']))).'" sizes="'.e($picture['sizes']).'"';
        $img = '<img class="sf-car-img" src="'.e($picture['src']).'"'.$srcset.' width="'.(int) $picture['width'].'" height="'.(int) $picture['height'].'" alt="'.e($picture['alt']).'" loading="lazy" decoding="async">';
        $items[] = ['class' => 'sf-car-picture', 'html' => $picture['url'] === null ? $img : '<a class="sf-car-link" href="'.e($picture['url']).'">'.$img.'</a>'];
    }
@endphp
@include('storefront.partials.carousel', ['class' => $c['class'], 'label' => $c['label'] !== '' ? $c['label'] : __('storefront.carousel.label'), 'arrows' => $c['arrows'], 'items' => $items])
