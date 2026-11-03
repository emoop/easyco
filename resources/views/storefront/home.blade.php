@extends('storefront.layout')

@section('content')
    {{-- HP-1: the page has no intro any more (heading, text, button); its one <h1> is the shop's name, for search engines and screen readers only. --}}
    <h1 class="sf-sr">{{ config('app.name') }}</h1>

    @if (! empty($categories))
        <section class="sf-category-row" aria-labelledby="sf-category-row-title">
            <h2 class="sf-section-title" id="sf-category-row-title">{{ __('storefront.home_categories') }}</h2>
            <ul class="sf-category-tiles">
                @foreach ($categories as $node)
                    <li class="sf-category-tile">
                        <a class="sf-category-tile-link" href="{{ $node->url }}">
                            <span class="sf-category-tile-name">{{ $node->name }}</span>
                            <span class="sf-category-tile-count">{{ trans_choice('storefront.home_category_count', $node->productCount, ['count' => $node->productCount]) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="sf-listing sf-home" id="newest" aria-labelledby="sf-newest-title">
        <h2 class="sf-section-title" id="sf-newest-title">{{ __('storefront.newest_products') }}</h2>
        @if ($listing->items === [])
            <p class="sf-empty">{{ __('storefront.no_products') }}</p>
        @else
            <div class="sf-grid">
                @foreach ($listing->items as $card)
                    @include('storefront.partials.card', ['card' => $card, 'eager' => $loop->index < 2])
                @endforeach
            </div>
        @endif
    </section>
@endsection
