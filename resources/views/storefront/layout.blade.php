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
    @endif
    @if (($seo['canonical'] ?? null) === null || ($seo['noindex'] ?? false))
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
            {{-- The bag icon is inline SVG (no icon library) and decorative: the link's accessible name is its text («Количка»), plus the count when there is one (set by the script). --}}
            <a class="sf-cart" href="{{ route('storefront.cart', [], false) }}" data-sf-cart-link>
                <svg class="sf-cart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                <span class="sf-cart-label">{{ __('storefront.cart') }}</span>
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
            <div class="sf-footer-brand">
                <span class="sf-footer-name">{{ config('app.name') }}</span>
                <a class="sf-footer-home" href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a>
            </div>
            {{-- The content pages flagged for the footer (pages-design.md section 4): published only, titles escaped, links built by route(). --}}
            @if (! empty($footerPages))
                <nav class="sf-footer-pages" aria-label="{{ __('storefront.footer_pages') }}">
                    <ul class="sf-footer-list">
                        @foreach ($footerPages as $footerPage)
                            <li><a href="{{ $footerPage['url'] }}">{{ $footerPage['title'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
    </footer>
</body>
</html>
