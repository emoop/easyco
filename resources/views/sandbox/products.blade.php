@extends('sandbox.layout')

@section('title', 'Products')

@section('content')
    <h1>Products</h1>
    <p class="muted">
        {{ $products->total() }} product{{ $products->total() === 1 ? '' : 's' }} visible to a customer
        (active + catalog-visible), newest first by the product timeline.
    </p>

    @if ($products->isEmpty())
        <p>No products are currently listed. A product appears here once its status is active and its catalog
            visibility is visible.</p>
    @else
        <div class="grid">
            @foreach ($products as $product)
                <article class="card">
                    <a href="{{ $product->url }}">
                        @if ($product->thumbnailUrl !== null)
                            <img class="thumb" src="{{ $product->thumbnailUrl }}" alt="{{ $product->name }}" loading="lazy">
                        @else
                            <span class="thumb-placeholder">No image</span>
                        @endif
                    </a>

                    <h2><a href="{{ $product->url }}">{{ $product->name }}</a></h2>

                    @if ($product->brandName !== null)
                        <p class="brand">{{ $product->brandName }}</p>
                    @endif

                    {{-- Already-safe HTML from App\Services\ProductPriceDisplay — the same rule the admin
                         product table uses (D6). Deliberately NOT escaped again. --}}
                    <p class="price">{!! $product->priceHtml !!}</p>

                    {{-- D8: read-only. A disabled placeholder, never a working control. --}}
                    <button type="button" disabled>Add to cart — coming in stage 2</button>
                </article>
            @endforeach
        </div>

        @if ($products->hasPages())
            <nav class="pagination">
                @if ($products->onFirstPage())
                    <span class="disabled">← Previous</span>
                @else
                    <a href="{{ $products->previousPageUrl() }}">← Previous</a>
                @endif

                <span class="muted">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>

                @if ($products->hasMorePages())
                    <a href="{{ $products->nextPageUrl() }}">Next →</a>
                @else
                    <span class="disabled">Next →</span>
                @endif
            </nav>
        @endif
    @endif
@endsection
