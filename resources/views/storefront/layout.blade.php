<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if (($seo['canonical'] ?? null) !== null)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @else
        <meta name="robots" content="noindex">
    @endif
    @if ((file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json'))) && ! app()->runningUnitTests())
        @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
    @endif
</head>
<body class="sf-layout">
    <header class="sf-header" x-data="{ menuOpen: false }">
        <div class="sf-header-bar">
            <a class="sf-logo" href="{{ route('storefront.home', [], false) }}">{{ config('app.name') }}</a>
            @if (! empty($categories))
                <button class="sf-menu-toggle" type="button" aria-controls="sf-menu"
                        x-on:click="menuOpen = ! menuOpen"
                        x-bind:aria-expanded="menuOpen ? 'true' : 'false'">
                    <span>{{ __('storefront.menu') }}</span>
                </button>
            @endif
            <a class="sf-cart" href="/cart">{{ app()->getLocale() === 'bg' ? 'Количка' : 'Cart' }}</a>
        </div>
        @if (! empty($categories))
            <nav class="sf-nav" id="sf-menu" aria-label="{{ __('storefront.menu') }}" x-bind:class="{ 'sf-nav-open': menuOpen }">
                <div class="sf-nav-inner">
                    @include('storefront.partials.menu', ['nodes' => $categories])
                </div>
            </nav>
        @endif
    </header>
    <main class="sf-main">
        @yield('content')
    </main>
    <footer class="sf-footer">
        <div class="sf-footer-inner">
            <span>{{ config('app.name') }}</span>
            <a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a>
        </div>
    </footer>
</body>
</html>
