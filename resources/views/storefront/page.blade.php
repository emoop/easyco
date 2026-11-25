@extends('storefront.layout')

{{-- CC-2: the slug of this page: the cookie banner compares it with the policy page's and prints its marker (the same for every visitor of the URL) --}}
@section('sf_page_slug', $page->slug)

@section('content')
    <article class="sf-page">
        <h1 class="sf-title">{{ $page->title }}</h1>
        {{-- The ONLY unescaped output of a page: its body, already rebuilt from the allow-list at render (DescriptionSanitizer). --}}
        <div class="sf-description">{!! $bodyHtml !!}</div>
    </article>
@endsection
