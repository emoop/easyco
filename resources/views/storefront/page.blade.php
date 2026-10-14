@extends('storefront.layout')

@section('content')
    <article class="sf-page">
        <h1 class="sf-title">{{ $page->title }}</h1>
        {{-- The ONLY unescaped output of a page: its body, already rebuilt from the allow-list at render (DescriptionSanitizer). --}}
        <div class="sf-description">{!! $bodyHtml !!}</div>
    </article>
@endsection
