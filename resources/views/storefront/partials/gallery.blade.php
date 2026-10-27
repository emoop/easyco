{{-- GL-1: the product page gallery. ONE markup for every mode; CSS (storefront.css) shows what each desktop / mobile mode needs.
     Receives $product (ProductPage) and $gallerySettings (GallerySettings). Everything printed is escaped; no style=, no script, no on…=. --}}
@php
    $settings = $gallerySettings ?? new \App\Settings\GallerySettings();
    $items = $product->gallery;
    $total = count($items);
    $imageTotal = count(array_filter($items, fn ($item) => $item->isImage()));
@endphp
<section class="sf-gallery {{ $settings->widthClass() }}" aria-label="{{ __('storefront.gallery') }}" data-sf-gallery
         @foreach ($settings->dataAttributes() as $name => $value) {{ $name }}="{{ $value }}" @endforeach>
    @if ($total === 0)
        <div class="sf-gal-placeholder sf-image-placeholder" aria-hidden="true" role="presentation"></div>
    @else
        <div class="sf-gal-layout">
            <div class="sf-gal-stage">
                <div class="sf-gal-main">
                    @foreach ($items as $item)
                        @if ($item->isImage())
                            <figure @class(['sf-gal-item', 'is-active' => $loop->first]) data-gal-kind="image">
                                <a class="sf-gal-link" href="{{ $item->largeUrl }}" data-lightbox="{{ $item->imageIndex }}">
                                    @php $srcset = collect($item->image->srcset)->map(fn ($source) => $source['url'].' '.$source['width'].'w')->implode(', '); @endphp
                                    @if ($loop->first)
                                        <img class="sf-gal-img"
                                             src="{{ $item->image->src }}"
                                             srcset="{{ $srcset }}"
                                             sizes="{{ $item->image->sizesHint }}"
                                             width="{{ $item->image->width }}"
                                             height="{{ $item->image->height }}"
                                             alt="{{ $item->image->alt }}"
                                             loading="eager" fetchpriority="high"
                                             decoding="async">
                                    @else
                                        {{-- Chromium fetches a lazy image inside a display:none figure (measured, GL-1): a thumbnail mode must not download the photos nobody asked for.
                                             The script fills src/srcset when the figure can be seen (storefront.js); without it the noscript copy is the image. --}}
                                        <img class="sf-gal-img"
                                             data-src="{{ $item->image->src }}"
                                             data-srcset="{{ $srcset }}"
                                             sizes="{{ $item->image->sizesHint }}"
                                             width="{{ $item->image->width }}"
                                             height="{{ $item->image->height }}"
                                             alt="{{ $item->image->alt }}"
                                             loading="lazy"
                                             decoding="async">
                                        <noscript>
                                            <img class="sf-gal-img"
                                                 src="{{ $item->image->src }}"
                                                 srcset="{{ $srcset }}"
                                                 sizes="{{ $item->image->sizesHint }}"
                                                 width="{{ $item->image->width }}"
                                                 height="{{ $item->image->height }}"
                                                 alt="{{ $item->image->alt }}"
                                                 loading="lazy"
                                                 decoding="async">
                                        </noscript>
                                    @endif
                                </a>
                            </figure>
                        @else
                            <figure @class(['sf-gal-item', 'is-active' => $loop->first]) data-gal-kind="video">
                                <video class="sf-gal-video" controls playsinline preload="metadata">
                                    <source src="{{ $item->videoUrl }}#t=0.1" type="{{ $item->videoMime !== '' ? $item->videoMime : 'video/mp4' }}">
                                </video>
                            </figure>
                        @endif
                    @endforeach
                </div>
                <button type="button" class="sf-gal-next" aria-label="{{ __('storefront.gallery_next') }}"><span aria-hidden="true">›</span></button>
            </div>
            <div class="sf-gal-thumbs">
                @foreach ($items as $item)
                    <button type="button" @class(['sf-gal-thumb', 'is-active' => $loop->first]) data-gal-kind="{{ $item->kind }}" data-gal-index="{{ $loop->index }}"
                            aria-label="{{ __($item->isImage() ? 'storefront.gallery_thumb' : 'storefront.gallery_thumb_video', ['n' => $loop->iteration, 'total' => $total]) }}"
                            aria-current="{{ $loop->first ? 'true' : 'false' }}">
                        @if ($item->isImage())
                            <img class="sf-gal-thumb-img" src="{{ $item->thumbUrl }}" width="{{ $item->thumbWidth }}" height="{{ $item->thumbHeight }}" alt="" loading="lazy" decoding="async">
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
        <dialog class="sf-lightbox" aria-label="{{ __('storefront.gallery_enlarged') }}" data-counter="{{ __('storefront.gallery_counter', ['n' => ':n', 'total' => ':total']) }}">
            <div class="sf-lightbox-stage">
                <img class="sf-lightbox-img" alt="" draggable="false">
            </div>
            <button type="button" class="sf-lightbox-close" aria-label="{{ __('storefront.gallery_close') }}"><span aria-hidden="true">×</span></button>
            <button type="button" class="sf-lightbox-prev" aria-label="{{ __('storefront.gallery_prev') }}"><span aria-hidden="true">‹</span></button>
            <button type="button" class="sf-lightbox-next" aria-label="{{ __('storefront.gallery_next') }}"><span aria-hidden="true">›</span></button>
            <p class="sf-lightbox-count" aria-live="polite">{{ __('storefront.gallery_counter', ['n' => 1, 'total' => $imageTotal]) }}</p>
        </dialog>
    @endif
</section>
