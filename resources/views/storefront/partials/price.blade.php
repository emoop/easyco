@inject('prices', 'App\Storefront\Support\PriceFormatter')
<p class="sf-price">
    @if ($price->regularFromMinor !== null)
        <s class="sf-price-regular">{{ $prices->format($price->regularFromMinor, $price->currency) }}</s>
    @endif
    <span class="sf-price-current">
        @if ($price->fromMinor === $price->toMinor)
            {{ $prices->format($price->fromMinor, $price->currency) }}
        @else
            {{ __('storefront.price_from_to', ['from' => $prices->format($price->fromMinor, $price->currency), 'to' => $prices->format($price->toMinor, $price->currency)]) }}
        @endif
    </span>
</p>
