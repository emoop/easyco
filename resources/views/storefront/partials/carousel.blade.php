{{-- HP-5, the SHARED carousel: one row of items that scrolls sideways (CSS scroll-snap; no library). The caller feeds it PREPARED items and the closed-list classes; it knows nothing of the home page (the product page's
     related products reuse it later). Args: $class (the section's classes: `sf-car` + closed-list classes), $label (the accessible name, already plain text), $arrows (bool), $items (list of {html, class}: `html` is
     prepared and trusted markup, `class` an optional extra class of the item). The track is a focusable `ul` (the keyboard scrolls it); the arrows are real buttons rendered `hidden`: storefront.js shows them, so without
     JavaScript the row still scrolls. No inline style, no inline script; the same HTML for every visitor. --}}
<section class="{{ $class }}" role="region" aria-label="{{ $label }}" data-carousel>
    <ul class="sf-car-track" tabindex="0">
        @foreach ($items as $entry)
            <li class="sf-car-item {{ $entry['class'] ?? '' }}">{!! $entry['html'] !!}</li>
        @endforeach
    </ul>
    @if ($arrows)
        <button type="button" class="sf-car-arrow sf-car-prev" data-car-step="-1" aria-label="{{ __('storefront.carousel.previous') }}" hidden></button>
        <button type="button" class="sf-car-arrow sf-car-next" data-car-step="1" aria-label="{{ __('storefront.carousel.next') }}" hidden></button>
    @endif
</section>
