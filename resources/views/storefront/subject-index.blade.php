@extends('storefront.layout')

{{-- SEO-1.1: the index of the brands or the collections that have products. The letter bar (brands only) links to plain anchors of the groups; there is no script. Every name
     and text is printed through {{ }}; the page's trail and its JSON-LD come from the controller. --}}
@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $crumbs])
    <section class="sf-listing sf-subjects sf-subjects-{{ $kind }}">
        <div class="sf-cat-head">
            <h1 class="sf-title">{{ $title }}</h1>
        </div>
        <p class="sf-subjects-lead">{{ $lead }}</p>
        @if ($groups !== [])
            <nav class="sf-letters" aria-label="{{ __('storefront.index_letters') }}">
                <ul>
                    @foreach ($groups as $group)
                        <li><a href="#{{ $group['anchor'] }}">{{ $group['anchor'] === 'other' ? __('storefront.index_other') : $group['letter'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
            @foreach ($groups as $group)
                <section class="sf-subject-group" id="{{ $group['anchor'] }}">
                    <h2 class="sf-subject-letter">{{ $group['anchor'] === 'other' ? __('storefront.index_other') : $group['letter'] }}</h2>
                    @include('storefront.partials.subject-tiles', ['tiles' => $group['tiles']])
                </section>
            @endforeach
        @else
            @include('storefront.partials.subject-tiles', ['tiles' => $tiles])
        @endif
    </section>
@endsection
