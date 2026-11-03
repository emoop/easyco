@extends('storefront.layout')

@section('content')
    {{-- HP-1: a PUBLISHED home layout takes the page over. The page keeps its one <h1> (the shop's name; plain text, escaped) for search engines and screen
         readers: it is not shown, the sections say what the page says. The sections are the full width of the content area; the spacing between them is a class of a closed list (no style attribute). --}}
    <h1 class="sf-sr">{{ config('app.name') }}</h1>
    <div class="sf-hp sf-hp-gap-{{ $sections['gap'] }}">
        @foreach ($sections['items'] as $item)
            <section class="{{ $item['class'] }}">
                @include($item['view'], ['item' => $item])
            </section>
        @endforeach
    </div>
@endsection
