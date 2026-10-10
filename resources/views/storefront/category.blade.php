@extends('storefront.layout')

@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $category->breadcrumbs])
    <section class="sf-listing sf-category">
        <h1 class="sf-title">{{ $category->name }}</h1>
        @if ($category->children !== [])
            <nav class="sf-subcategories" aria-label="{{ __('storefront.subcategories') }}">
                <ul class="sf-subcategory-list">
                    @foreach ($category->children as $child)
                        <li class="sf-subcategory"><a href="{{ $child->url }}">{{ $child->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
        @if ($listing->items === [])
            <p class="sf-empty">{{ __('storefront.no_products') }}</p>
        @else
            <div class="sf-grid">
                @foreach ($listing->items as $card)
                    @include('storefront.partials.card', ['card' => $card, 'eager' => $loop->index < 2])
                @endforeach
            </div>
        @endif
        @include('storefront.partials.pagination', ['pagination' => $pagination])
    </section>
@endsection
