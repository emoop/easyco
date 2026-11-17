@extends('storefront.layout')

@section('content')
    {{-- NF-1: the 404 page is what «Дизайн» → «Страница 404» says: the title (the page's one <h1>), the plain text (escaped, line breaks kept), the search form (only while search is on), the chosen block (the
         home page's own sections, a cached fragment) and the button home. Closed classes only (`sf-nf-*`); no inline style, no script. --}}
    <section class="sf-nf">
        <h1 class="sf-nf-title">{{ $nf['title'] }}</h1>
        @if ($nf['text'] !== '')
            <p class="sf-nf-text">{!! nl2br(e($nf['text'])) !!}</p>
        @endif
        @if ($nfSearch)
            <form class="sf-nf-search sf-search-page-form" role="search" method="get" action="{{ \App\Storefront\Search\SearchFeature::path() }}">
                <label class="sf-sr-only" for="sf-nf-q">{{ __('storefront.search') }}</label>
                <input class="sf-search-input" id="sf-nf-q" name="q" type="search" maxlength="100" placeholder="{{ __('storefront.search_placeholder') }}" autocomplete="off">
                <button class="sf-search-submit" type="submit">{{ __('storefront.search_submit') }}</button>
            </form>
        @endif
        @if ($nfBottom !== '')
            <div class="sf-nf-bottom">{!! $nfBottom !!}</div>
        @endif
        @if ($nf['button'])
            <p class="sf-nf-button-row"><a class="sf-nf-button" href="{{ route('storefront.home', [], false) }}">{{ __('storefront.not_found_button') }}</a></p>
        @endif
    </section>
@endsection
