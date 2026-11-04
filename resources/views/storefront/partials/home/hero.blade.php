{{-- HP-6, home section `hero`: ONE big banner at a time, changing by itself with CSS only (see «HP-6 hero slider» in storefront.css). Every slide is an HTML block (the compiled, sanitised HTML) and, when set, a second block for the
     phone: below 48rem the phone block shows, from 48rem the desktop one; a slide with no phone block shows its desktop block at every size. The same markup as the shared carousel (`data-carousel`, `.sf-car-track`,
     `.sf-car-item`, the `.sf-car-arrow` buttons printed `hidden`) so that the manual row (autoplay off, or `prefers-reduced-motion: reduce`) is the HP-5 carousel's own script and arrows. The pause control is a
     visually hidden checkbox and its label (printed only when it animates). No inline style, no inline script; the same HTML for every visitor. $item['hero'] = {class, slides[{d, m}], count, autoplay}. --}}
@php
    $hero = $item['hero'];
    $total = $hero['count'];
@endphp
<section class="{{ $hero['class'] }}" role="region" aria-roledescription="carousel" aria-label="{{ __('storefront.hero.label') }}" data-carousel>
    @if ($hero['autoplay'])
        <input type="checkbox" class="sf-hs-toggle" id="sf-hs-toggle">
        <label class="sf-car-arrow sf-hs-pause" for="sf-hs-toggle"><span class="sf-hs-pause-text sf-sr">{{ __('storefront.hero.pause') }}</span><span class="sf-hs-play-text sf-sr">{{ __('storefront.hero.play') }}</span></label>
    @endif
    <ul class="sf-hs-track sf-car-track"@if ($total > 1) tabindex="0"@endif>
        @foreach ($hero['slides'] as $slide)
            <li class="sf-hs-slide sf-car-item{{ $slide['m'] !== '' ? ' sf-hs-slide-p' : '' }}" aria-label="{{ $loop->iteration }} / {{ $total }}">
                <div class="sf-hs-d">{!! $slide['d'] !!}</div>
                @if ($slide['m'] !== '')
                    <div class="sf-hs-m">{!! $slide['m'] !!}</div>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($total > 1)
        <button type="button" class="sf-car-arrow sf-car-prev" data-car-step="-1" aria-label="{{ __('storefront.carousel.previous') }}" hidden></button>
        <button type="button" class="sf-car-arrow sf-car-next" data-car-step="1" aria-label="{{ __('storefront.carousel.next') }}" hidden></button>
    @endif
</section>
