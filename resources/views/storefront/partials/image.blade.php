<img class="sf-image"
     src="{{ $image->src }}"
     srcset="{{ collect($image->srcset)->map(fn ($source) => $source['url'].' '.$source['width'].'w')->implode(', ') }}"
     sizes="{{ $image->sizesHint }}"
     width="{{ $image->width }}"
     height="{{ $image->height }}"
     alt="{{ $image->alt }}"
     @if ($eager)
         loading="eager"
         @if ($priority ?? false) fetchpriority="high" @endif
     @else
         loading="lazy"
     @endif
     decoding="async">
