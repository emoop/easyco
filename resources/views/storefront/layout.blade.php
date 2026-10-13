<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The same favicon the admin panel shows (AdminPanelProvider), so a storefront tab is recognisable too. --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}">
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
    @include('storefront.partials.messages')
    <header class="sf-header">
        <div class="sf-header-bar">
            @if (! empty($categories))
                <button class="sf-menu-toggle" type="button" aria-controls="sf-menu" aria-expanded="false" data-sf-menu-toggle>
                    <span>{{ __('storefront.menu') }}</span>
                </button>
            @endif
            {{-- The mark, centred by the header's own grid; the site has no dark mode, so this is the dark
                 (currentColor) variant of the logo — the same file the admin uses in its light mode. --}}
            <a class="sf-logo" href="{{ route('storefront.home', [], false) }}">
                <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}" width="596" height="150">
            </a>
            <a class="sf-cart" href="{{ route('storefront.cart', [], false) }}" data-sf-cart-link>
                <span>{{ __('storefront.cart') }}</span>
                <span class="sf-cart-count" data-sf-cart-count hidden></span>
            </a>
        </div>
        @if (! empty($categories))
            <nav class="sf-nav" id="sf-menu" aria-label="{{ __('storefront.menu') }}">
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
