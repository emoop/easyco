@extends('storefront.layout')

@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $category->breadcrumbs])
    <section class="sf-listing sf-category sf-cat-layout-{{ $layout ?? 'full' }} sf-cat-side-{{ $side ?? 'left' }}">
        {{-- CD-1c: the title and «Брой продукти: N» (the total of the current result) on one line. CD-1b: with a «Блок отгоре» the heading stays for search engines and screen readers and is hidden for the eye (the banner says it) --}}
        <div class="sf-cat-head">
            <h1 class="sf-title{{ ($headerBlock ?? null) !== null ? ' sf-sr' : '' }}">{{ $category->name }}</h1>
            <p class="sf-count">{{ __('storefront.products_total', ['count' => $listing->total]) }}</p>
        </div>
        @if (($headerBlock ?? null) !== null)
            <div class="sf-cat-banner">{!! $headerBlock !!}</div>
        @endif
        @if ($category->children !== [])
            <nav class="sf-subcategories" aria-label="{{ __('storefront.subcategories') }}">
                <ul class="sf-subcategory-list">
                    @foreach ($category->children as $child)
                        <li class="sf-subcategory"><a href="{{ $child->url }}">{{ $child->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
        {{-- CD-1c: «filters on top» prints the BAR (a computer: the sort and one dropdown per filter, plain links); every layout prints the FORM (the phone drawer; the side panel). CSS shows the one that fits: the DOM order is always
             the bar, the form, then the list. --}}
        <div class="sf-cat-body">
            @include('storefront.partials.category-bar', ['filters' => $filters ?? null, 'sorts' => $sorts ?? []])
            @include('storefront.partials.filters', ['filters' => $filters ?? null, 'sorts' => $sorts ?? [], 'perPageHidden' => $perPageHidden ?? null, 'clearUrl' => $clearUrl ?? $category->url, 'actionUrl' => $category->url, 'layout' => $layout ?? 'full', 'total' => $listing->total])
            <div class="sf-cat-main">
                @include('storefront.partials.category-toolbar', ['filters' => $filters ?? null, 'sorts' => $sorts ?? []])
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
            </div>
        </div>
    </section>
@endsection
