<article class="sf-card" data-product-id="{{ $card->id }}">
    <a class="sf-card-link" href="{{ $card->url }}">
        @if ($card->image !== null)
            <div class="sf-card-image">@include('storefront.partials.image', ['image' => $card->image, 'eager' => $eager])@if ($card->secondImage !== null)<img class="sf-card-image-hover" alt="" width="{{ $card->secondImage->width }}" height="{{ $card->secondImage->height }}" decoding="async" hidden data-src="{{ $card->secondImage->src }}" data-srcset="{{ collect($card->secondImage->srcset)->map(fn ($source) => $source['url'].' '.$source['width'].'w')->implode(', ') }}" data-sizes="{{ $card->secondImage->sizesHint }}">@endif<ul class="sf-card-sizes" hidden aria-hidden="true"></ul></div>
        @else
            <div class="sf-card-image sf-image-placeholder" aria-hidden="true" role="presentation"></div>
        @endif
        @if ($card->brand !== null)
            <p class="sf-card-brand">{{ $card->brand['name'] }}</p>
        @endif
        <h3 class="sf-card-name">{{ $card->name }}</h3>
    </a>
    @if ($card->price !== null || ! $card->inStock)
        <div class="sf-card-footer">
            @if ($card->price !== null)
                @include('storefront.partials.price', ['price' => $card->price])
            @endif
            @if (! $card->inStock)
                <p class="sf-stock sf-stock-out">{{ __('storefront.out_of_stock') }}</p>
            @endif
        </div>
    @endif
</article>

