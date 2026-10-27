@extends('storefront.layout')

@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $product->breadcrumbs])
    @inject('prices', 'App\Storefront\Support\PriceFormatter')
    @php
        $purchasable = collect($product->variations)->first(fn ($variation) => $variation->purchasable && $variation->inStock);
    @endphp
    <article class="sf-product">
        <div class="sf-product-main">
            @include('storefront.partials.gallery', ['product' => $product, 'gallerySettings' => $gallerySettings ?? null])
            <section class="sf-summary">
{{-- SC-1: the right column is the published layout's components in order. The directives start at the left edge on purpose: each partial prints the page's own markup with its own indentation, so nothing is added between them. --}}@foreach ($summary ?? \App\Storefront\ProductSummary\ProductSummaryView::prepare(null) as $component)
@php($part = $__env->make('storefront.partials.summary.'.$component['type'], \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render())
{!! substr($part, 1) !!}@endforeach
            </section>
        </div>
        @if ($descriptionHtml !== '')
            <section class="sf-description">
                <h2 class="sf-description-title">{{ __('storefront.description') }}</h2>
                {!! $descriptionHtml !!}
            </section>
        @endif
    </article>
@endsection
