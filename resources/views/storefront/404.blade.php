@extends('storefront.layout')

@section('content')
    <section class="sf-404">
        <h1>{{ __('storefront.not_found_heading') }}</h1>
        <p>{{ __('storefront.not_found_text') }}</p>
        <p><a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.back_home') }}</a></p>
    </section>
@endsection
