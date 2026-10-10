@extends('storefront.layout')

@section('content')
    <section class="sf-listing sf-home">
        <h1 class="sf-title">{{ __('storefront.newest_products') }}</h1>
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
