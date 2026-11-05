@extends('storefront.layout')

@section('content')
    @include('storefront.partials.breadcrumbs', ['crumbs' => $category->breadcrumbs])
    <section class="sf-listing sf-category sf-cat-layout-{{ $layout ?? 'full' }} sf-cat-side-{{ $side ?? 'left' }}{{ ($layout ?? 'full') === 'sidebar' ? ' sf-cat-sidew-'.($sideWidth ?? 'wide') : '' }}">
        {{-- P3.1: the title. CD-1b: with a «Блок отгоре» of the category, or with «Покажи заглавието» off in the template, the <h1> stays for search engines and screen readers and is hidden for the eye. Otherwise
             the look is the shop's «Стил на заглавието»: the HOME heading with lines (its partial, with the <h1> tag) or the plain title. The count of the result is on the sort row now (category-toolbar / category-bar). --}}
        @php
            $hideTitle = ($headerBlock ?? null) !== null || ($content['title'] ?? true) === false;
            $withLines = ! $hideTitle && ($content['title_style'] ?? 'lines') === 'lines';
        @endphp
        <div class="sf-cat-head{{ $withLines ? ' sf-cat-head-lines' : '' }}">
            @if ($withLines)
                @include('storefront.partials.home.heading', ['item' => ['tag' => 'h1', 'settings' => ['text' => $category->name, 'level' => 'h2', 'lines' => 'lines', 'thickness' => '1']]])
            @else
                <h1 class="sf-title{{ $hideTitle ? ' sf-sr' : '' }}">{{ $category->name }}</h1>
            @endif
        </div>
        @if (($headerBlock ?? null) !== null)
            <div class="sf-cat-banner">{!! $headerBlock !!}</div>
        @endif
@if (($content['cloud'] ?? null) !== null)
            {{-- P3 / P3.1: the quick-navigation cloud (at most 12 links, the most products first) — printed ONLY when the template has it on. The shape is the shop's «Форма на облака»: «site» = the look of the side panel's
                 buttons (sf-bv-chip), «pill» = the rounded chips. The wrapper's class hides it below the filter drawer's breakpoint (CSS only: the HTML is the same for everybody). --}}
@php $site = ($content['shape'] ?? 'site') !== 'pill'; @endphp
            <nav class="sf-subcategories sf-cloud sf-cloud-{{ $site ? 'site' : 'pill' }}" aria-label="{{ $content['label'] }}">
                <ul class="sf-subcategory-list{{ $site ? ' sf-bctl-values-buttons' : '' }}">
@foreach ($content['cloud'] as $link)
@if ($site)
                            <li><a class="sf-bv-chip" href="{{ $link['url'] }}">{{ $link['name'] }}</a></li>
@else
                            <li class="sf-subcategory"><a href="{{ $link['url'] }}">{{ $link['name'] }}</a></li>
@endif
@endforeach
                </ul>
            </nav>
@endif
@include('storefront.partials.listing-slot', ['slot' => $content['top'] ?? null, 'where' => 'top'])
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
@include('storefront.partials.listing-slot', ['slot' => $content['bottom'] ?? null, 'where' => 'bottom', 'heading' => $content['bottom_heading'] ?? null])
    </section>
@endsection
