@extends('storefront.layout')

{{-- SRCH-1: the results page, a category listing for the words the visitor typed. The query is visitor input: it is only ever printed through {{ }} (escaped), in the title, the field and the messages. --}}
@section('content')
    <section class="sf-listing sf-category sf-search-page sf-cat-layout-{{ $layout ?? 'full' }} sf-cat-side-{{ $side ?? 'left' }}{{ ($layout ?? 'full') === 'sidebar' ? ' sf-cat-sidew-'.($sideWidth ?? 'wide') : '' }}">
        <div class="sf-cat-head">
            <h1 class="sf-title">{{ $title }}</h1>
        </div>
        <form class="sf-search-page-form" role="search" method="get" action="{{ \App\Storefront\Search\SearchFeature::path() }}">
            <label class="sf-sr-only" for="sf-search-page-q">{{ __('storefront.search') }}</label>
            <input class="sf-search-input" id="sf-search-page-q" name="q" type="search" maxlength="100" value="{{ $query->text }}" placeholder="{{ __('storefront.search_placeholder') }}" autocomplete="off">
            <button class="sf-search-submit" type="submit">{{ __('storefront.search_submit') }}</button>
        </form>
        @if ($state === 'empty')
            <p class="sf-empty sf-search-message">{{ __('storefront.search_hint_empty') }}</p>
        @elseif ($state === 'short')
            <p class="sf-empty sf-search-message">{{ __('storefront.search_hint_short', ['min' => \App\Storefront\Search\SearchQuery::MIN_LENGTH]) }}</p>
        @elseif ($state === 'none')
            <p class="sf-empty sf-search-message">{{ __('storefront.search_none', ['query' => $query->text]) }}</p>
        @else
            <div class="sf-cat-body">
                @include('storefront.partials.category-bar', ['filters' => null, 'sorts' => $sorts])
                @include('storefront.partials.filters', ['filters' => null, 'sorts' => $sorts, 'perPageHidden' => null, 'clearUrl' => \App\Storefront\Search\SearchFeature::path(), 'actionUrl' => \App\Storefront\Search\SearchFeature::path(), 'layout' => $layout ?? 'full', 'total' => $listing->total, 'searchQuery' => $query->text])
                <div class="sf-cat-main">
                    @include('storefront.partials.category-toolbar', ['filters' => null, 'sorts' => $sorts])
                    <div class="sf-grid">
                        @foreach ($listing->items as $card)
                            @include('storefront.partials.card', ['card' => $card, 'eager' => $loop->index < 2])
                        @endforeach
                    </div>
                    @include('storefront.partials.pagination', ['pagination' => $pagination])
                </div>
            </div>
        @endif
    </section>
@endsection
