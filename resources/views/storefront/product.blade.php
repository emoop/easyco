@extends('storefront.layout')

@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $product->breadcrumbs])
    @inject('prices', 'App\Storefront\Support\PriceFormatter')
    @php
        $purchasable = collect($product->variations)->first(fn ($variation) => $variation->purchasable && $variation->inStock);
        $images = $product->images;
    @endphp
    <article class="sf-product">
        <section class="sf-gallery" aria-label="{{ __('storefront.gallery') }}" x-data="{ active: 0 }">
            <div class="sf-gallery-main">
                @foreach ($images as $image)
                    <div class="sf-gallery-item @if ($loop->first) sf-gallery-active @endif"
                         x-bind:class="{ 'sf-gallery-active': active === {{ $loop->index }} }">
                        @include('storefront.partials.image', ['image' => $image, 'eager' => $loop->first, 'priority' => $loop->first])
                    </div>
                @endforeach
            </div>
            @if (count($images) > 1)
                <div class="sf-gallery-thumbs">
                    @foreach ($images as $image)
                        <button type="button"
                                class="sf-gallery-thumb @if ($loop->first) sf-gallery-thumb-active @endif"
                                x-bind:class="{ 'sf-gallery-thumb-active': active === {{ $loop->index }} }"
                                x-bind:aria-current="active === {{ $loop->index }} ? 'true' : 'false'"
                                x-on:click="active = {{ $loop->index }}">
                            @include('storefront.partials.image', ['image' => $image, 'eager' => false])
                        </button>
                    @endforeach
                </div>
            @endif
        </section>
        <section class="sf-summary">
            @if ($product->brand !== null)
                <p class="sf-product-brand">{{ $product->brand['name'] }}</p>
            @endif
            <h1 class="sf-title">{{ $product->name }}</h1>
            @if ($product->shortDescription !== null && $product->shortDescription !== '')
                <p class="sf-short-description">{{ $product->shortDescription }}</p>
            @endif
            @if ($product->price !== null)
                @include('storefront.partials.price', ['price' => $product->price])
            @endif
            @if ($product->inStock)
                <p class="sf-stock sf-stock-in">{{ __('storefront.in_stock') }}</p>
            @else
                <p class="sf-stock sf-stock-out">{{ __('storefront.out_of_stock') }}</p>
            @endif
            @if ($product->type === 'variable' && $product->variations !== [])
                <div class="sf-variations">
                    <label class="sf-variation-label" for="sf-variation">{{ __('storefront.choose_variation') }}</label>
                    <select class="sf-variation-select" id="sf-variation" name="variation">
                        @foreach ($product->variations as $variation)
                            @php
                                $available = $variation->purchasable && $variation->inStock;
                                $priceText = $variation->price !== null ? $prices->format($variation->price->fromMinor, $variation->price->currency) : '';
                            @endphp
                            <option value="{{ $variation->id }}" @disabled(! $available)>
                                @if ($priceText === '')
                                    {{ $variation->label }}
                                @elseif ($available)
                                    {{ __('storefront.variation_option', ['label' => $variation->label, 'price' => $priceText]) }}
                                @else
                                    {{ __('storefront.variation_option_unavailable', ['label' => $variation->label, 'price' => $priceText, 'reason' => __('storefront.unavailable')]) }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <button class="sf-add-to-cart" type="button" disabled data-variation-id="{{ $purchasable?->id }}">{{ __('storefront.add_to_cart') }}</button>
        </section>
        @if ($descriptionHtml !== '')
            <section class="sf-description">
                <h2 class="sf-description-title">{{ __('storefront.description') }}</h2>
                {!! $descriptionHtml !!}
            </section>
        @endif
    </article>
@endsection
